<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo  = db();
$user = getUserSession();

$teamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if (!$teamId) { echo json_encode(['error' => 'team_id obrigatório']); exit; }

$stmtTeam = $pdo->prepare("SELECT t.*, CONCAT(t.city,' ',t.name) AS full_name, u.name AS owner_name FROM teams t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?");
$stmtTeam->execute([$teamId]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
if (!$team) { echo json_encode(['error' => 'Time não encontrado']); exit; }

$league = $team['league'];

// ── 1. Pontos por temporada ───────────────────────────────────────────────────
$stmtPts = $pdo->prepare("
    SELECT COUNT(*) AS seasons_played, SUM(points) AS total_points,
           MAX(points) AS best_season_pts
    FROM team_season_points WHERE team_id = ?");
$stmtPts->execute([$teamId]);
$ptsData = $stmtPts->fetch(PDO::FETCH_ASSOC);

// ── 2. Playoffs (≥2 pts) ──────────────────────────────────────────────────────
$stmtPlayoffs = $pdo->prepare("SELECT COUNT(*) AS playoff_appearances FROM team_season_points WHERE team_id = ? AND points >= 2");
$stmtPlayoffs->execute([$teamId]);
$playoffData = $stmtPlayoffs->fetch(PDO::FETCH_ASSOC);

// ── 3. Temporada regular — temporadas com pontos de temporada regular ─────────
// regular_season_points > 0 = chegou ao playoff (top 8 da liga)
// Usa os campos bool de playoff para derivar as fases
$stmtStandings = $pdo->prepare("
    SELECT regular_season_points, playoff_champion, playoff_runner_up,
           playoff_conference_finals, playoff_second_round, playoff_first_round
    FROM team_ranking_points
    WHERE team_id = ?");
$stmtStandings->execute([$teamId]);
$standingsRaw = $stmtStandings->fetchAll(PDO::FETCH_ASSOC);
$top1Regular = 0; $top4Regular = 0; $top8Regular = 0;
foreach ($standingsRaw as $r) {
    // Considera que pontos de reg season > 0 equivale a top 8
    if ((int)$r['regular_season_points'] > 0) $top8Regular++;
    if ((int)$r['regular_season_points'] >= 4) $top4Regular++;
    if ((int)$r['regular_season_points'] >= 8) $top1Regular++;
}

// ── 4. Títulos e resultados nos playoffs ─────────────────────────────────────
$stmtTitles = $pdo->prepare("SELECT SUM(champion_team_id=?) AS titles, SUM(runner_up_team_id=?) AS runner_ups FROM season_history");
$stmtTitles->execute([$teamId, $teamId]);
$titlesData = $stmtTitles->fetch(PDO::FETCH_ASSOC);

$stmtChampSeasons = $pdo->prepare("SELECT season_id, year, season_number, sprint_number FROM season_history WHERE champion_team_id = ? ORDER BY year DESC");
$stmtChampSeasons->execute([$teamId]);
$champSeasons = $stmtChampSeasons->fetchAll(PDO::FETCH_ASSOC);

// Usa team_ranking_points para contagem por fase (mais confiável)
$playoffResults = [
    'conference_final' => 0,
    'second_round'     => 0,
    'first_round'      => 0,
];
foreach ($standingsRaw as $r) {
    if ((int)$r['playoff_conference_finals']) $playoffResults['conference_final']++;
    if ((int)$r['playoff_second_round'])      $playoffResults['second_round']++;
    if ((int)$r['playoff_first_round'])       $playoffResults['first_round']++;
}

// ── 5. Picks ──────────────────────────────────────────────────────────────────
$stmtPicks = $pdo->prepare("SELECT SUM(round=1) AS picks_r1, SUM(round=2) AS picks_r2 FROM picks WHERE original_team_id = ? AND auto_generated = 1");
$stmtPicks->execute([$teamId]);
$picksOwned = $stmtPicks->fetch(PDO::FETCH_ASSOC);

// ── 6. Trocas ─────────────────────────────────────────────────────────────────
$stmtTrades = $pdo->prepare("SELECT COUNT(*) AS c FROM trades WHERE status='accepted' AND (from_team_id=? OR to_team_id=?)");
$stmtTrades->execute([$teamId, $teamId]);
$tradesCount = (int)$stmtTrades->fetch(PDO::FETCH_ASSOC)['c'];
try {
    $stmtMT = $pdo->prepare("SELECT COUNT(DISTINCT mt.id) AS c FROM multi_trades mt JOIN multi_trade_teams mtt ON mtt.trade_id=mt.id WHERE mt.status='accepted' AND mtt.team_id=?");
    $stmtMT->execute([$teamId]);
    $tradesCount += (int)$stmtMT->fetch(PDO::FETCH_ASSOC)['c'];
} catch (Exception $e) {}

// ── 7. Jogadores históricos ───────────────────────────────────────────────────
$stmtAllPlayers = $pdo->prepare("SELECT COUNT(DISTINCT player_id) AS total FROM player_season_log WHERE team_id = ?");
$stmtAllPlayers->execute([$teamId]);
$totalPlayers = (int)$stmtAllPlayers->fetch(PDO::FETCH_ASSOC)['total'];

$stmtBest = $pdo->prepare("SELECT player_name, ovr, position, year FROM player_season_log WHERE team_id = ? ORDER BY ovr DESC LIMIT 1");
$stmtBest->execute([$teamId]);
$bestPlayer = $stmtBest->fetch(PDO::FETCH_ASSOC);

$stmtWorst = $pdo->prepare("SELECT player_name, ovr, position, year FROM player_season_log WHERE team_id = ? AND ovr > 0 ORDER BY ovr ASC LIMIT 1");
$stmtWorst->execute([$teamId]);
$worstPlayer = $stmtWorst->fetch(PDO::FETCH_ASSOC);

$bestByPos = [];
foreach (['PG','SG','SF','PF','C'] as $pos) {
    $s = $pdo->prepare("SELECT player_name, ovr, year FROM player_season_log WHERE team_id = ? AND position = ? ORDER BY ovr DESC LIMIT 1");
    $s->execute([$teamId, $pos]);
    $bestByPos[$pos] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Média de OVR e idade por ano
$stmtAvg = $pdo->prepare("SELECT year, ROUND(AVG(ovr),1) AS avg_ovr, ROUND(AVG(age),1) AS avg_age, COUNT(DISTINCT player_id) AS players FROM player_season_log WHERE team_id = ? AND year IS NOT NULL GROUP BY year ORDER BY year ASC");
$stmtAvg->execute([$teamId]);
$avgByYear = $stmtAvg->fetchAll(PDO::FETCH_ASSOC);

// ── 8. Prêmios individuais ────────────────────────────────────────────────────
$stmtAwards = $pdo->prepare("SELECT award_type, COUNT(*) AS total FROM season_awards WHERE team_id = ? GROUP BY award_type ORDER BY total DESC");
$stmtAwards->execute([$teamId]);
$awards = $stmtAwards->fetchAll(PDO::FETCH_ASSOC);

// ── 9. Elenco campeão ────────────────────────────────────────────────────────
$champRoster = [];
if (!empty($champSeasons)) {
    $s = $pdo->prepare("SELECT player_name, position, ovr, age FROM player_season_log WHERE team_id = ? AND season_id = ? ORDER BY ovr DESC");
    $s->execute([$teamId, $champSeasons[0]['season_id']]);
    $champRoster = $s->fetchAll(PDO::FETCH_ASSOC);
}

// ── 10. GM Stats ─────────────────────────────────────────────────────────────
$gmStats = [];
try {
    // Tapas
    $sT = $pdo->prepare("SELECT COALESCE(tapas,0) AS tapas, COALESCE(tapas_used,0) AS tapas_used FROM teams WHERE id = ?");
    $sT->execute([$teamId]);
    $tapasRow = $sT->fetch(PDO::FETCH_ASSOC);
    $gmStats['tapas']       = (int)($tapasRow['tapas'] ?? 0);
    $gmStats['tapas_used']  = (int)($tapasRow['tapas_used'] ?? 0);
} catch (Exception $e) { $gmStats['tapas'] = 0; $gmStats['tapas_used'] = 0; }

try {
    // FA pickups — jogadores que vieram de free_agent_offers aceitas
    $sFA = $pdo->prepare("SELECT COUNT(*) AS c FROM free_agent_offers WHERE team_id = ? AND status = 'accepted'");
    $sFA->execute([$teamId]);
    $gmStats['fa_pickups'] = (int)$sFA->fetch(PDO::FETCH_ASSOC)['c'];
} catch (Exception $e) { $gmStats['fa_pickups'] = 0; }

try {
    // Draftados na 1ª rodada por este time
    $sD = $pdo->prepare("SELECT COUNT(*) AS c FROM players WHERE drafted_by_team_id = ? AND drafted_season_number IS NOT NULL");
    $sD->execute([$teamId]);
    $gmStats['drafted_players'] = (int)$sD->fetch(PDO::FETCH_ASSOC)['c'];
} catch (Exception $e) { $gmStats['drafted_players'] = 0; }

try {
    // Gastos (cap space usada) — soma do OVR dos jogadores atuais como proxy de CAP
    $sCap = $pdo->prepare("SELECT COALESCE(cap_top8, 0) AS cap FROM teams WHERE id = ?");
    $sCap->execute([$teamId]);
    $gmStats['current_cap'] = (int)$sCap->fetch(PDO::FETCH_ASSOC)['cap'];
} catch (Exception $e) { $gmStats['current_cap'] = 0; }

// Número de acessos (logins) do dono
try {
    $sL = $pdo->prepare("SELECT COALESCE(login_count,0) AS c FROM users WHERE id = ?");
    $sL->execute([(int)$team['user_id']]);
    $gmStats['logins'] = (int)$sL->fetch(PDO::FETCH_ASSOC)['c'];
} catch (Exception $e) { $gmStats['logins'] = 0; }

try {
    $sFP = $pdo->prepare("SELECT COALESCE(fba_points,0) AS c FROM users WHERE id = ?");
    $sFP->execute([(int)$team['user_id']]);
    $gmStats['fba_points'] = (int)$sFP->fetch(PDO::FETCH_ASSOC)['c'];
} catch (Exception $e) { $gmStats['fba_points'] = 0; }

echo json_encode([
    'success'  => true,
    'team'     => $team,
    'seasons'  => [
        'played'      => (int)($ptsData['seasons_played'] ?? 0),
        'total_points'=> (int)($ptsData['total_points'] ?? 0),
        'best_pts'    => (int)($ptsData['best_season_pts'] ?? 0),
    ],
    'playoffs' => [
        'appearances'   => (int)($playoffData['playoff_appearances'] ?? 0),
        'titles'        => (int)($titlesData['titles'] ?? 0),
        'runner_ups'    => (int)($titlesData['runner_ups'] ?? 0),
        'conf_finals'   => $playoffResults['conference_final'] ?? 0,
        'second_round'  => $playoffResults['second_round'] ?? 0,
        'first_round'   => $playoffResults['first_round'] ?? 0,
        'champ_seasons' => $champSeasons,
        'champ_roster'  => $champRoster,
    ],
    'regular'  => [
        'top1' => $top1Regular,
        'top4' => $top4Regular,
        'top8' => $top8Regular,
    ],
    'picks'    => [
        'own_r1' => (int)($picksOwned['picks_r1'] ?? 0),
        'own_r2' => (int)($picksOwned['picks_r2'] ?? 0),
    ],
    'trades'   => $tradesCount,
    'players'  => [
        'total_ever' => $totalPlayers,
        'best'       => $bestPlayer,
        'worst'      => $worstPlayer,
        'best_by_pos'=> $bestByPos,
        'avg_by_year'=> $avgByYear,
    ],
    'awards'   => $awards,
    'gm'       => $gmStats,
]);
