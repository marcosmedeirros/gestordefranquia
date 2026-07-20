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

// ── 1. Pontos por temporada ───────────────────────────────────────
$stmtPts = $pdo->prepare("SELECT COUNT(*) AS seasons_played, SUM(points) AS total_points, MAX(points) AS best_season_pts FROM team_season_points WHERE team_id = ?");
$stmtPts->execute([$teamId]);
$ptsData = $stmtPts->fetch(PDO::FETCH_ASSOC);

// ── 2. Playoffs (≥2 pts) ─────────────────────────────────────────
$stmtPlayoffs = $pdo->prepare("SELECT COUNT(*) AS playoff_appearances FROM team_season_points WHERE team_id = ? AND points >= 2");
$stmtPlayoffs->execute([$teamId]);
$playoffData = $stmtPlayoffs->fetch(PDO::FETCH_ASSOC);

// ── 3. Temporada regular via team_ranking_points ─────────────────
$stmtStandings = $pdo->prepare("SELECT regular_season_points, playoff_champion, playoff_runner_up, playoff_conference_finals, playoff_second_round, playoff_first_round FROM team_ranking_points WHERE team_id = ?");
$stmtStandings->execute([$teamId]);
$standingsRaw = $stmtStandings->fetchAll(PDO::FETCH_ASSOC);
$top1Regular = 0; $top4Regular = 0; $top8Regular = 0;
foreach ($standingsRaw as $r) {
    if ((int)$r['regular_season_points'] > 0)  $top8Regular++;
    if ((int)$r['regular_season_points'] >= 4) $top4Regular++;
    if ((int)$r['regular_season_points'] >= 8) $top1Regular++;
}

// ── 4. Títulos e playoffs por fase ───────────────────────────────
$stmtTitles = $pdo->prepare("SELECT SUM(champion_team_id=?) AS titles, SUM(runner_up_team_id=?) AS runner_ups FROM season_history");
$stmtTitles->execute([$teamId, $teamId]);
$titlesData = $stmtTitles->fetch(PDO::FETCH_ASSOC);

$stmtChampSeasons = $pdo->prepare("SELECT season_id, year, season_number FROM season_history WHERE champion_team_id = ? ORDER BY year DESC");
$stmtChampSeasons->execute([$teamId]);
$champSeasons = $stmtChampSeasons->fetchAll(PDO::FETCH_ASSOC);

$playoffResults = ['conference_final' => 0, 'second_round' => 0, 'first_round' => 0];
foreach ($standingsRaw as $r) {
    if ((int)$r['playoff_conference_finals']) $playoffResults['conference_final']++;
    if ((int)$r['playoff_second_round'])      $playoffResults['second_round']++;
    if ((int)$r['playoff_first_round'])       $playoffResults['first_round']++;
}

// ── 5. Picks trocadas ────────────────────────────────────────────
$stmtPicks = $pdo->prepare("SELECT COUNT(*) AS traded FROM picks WHERE original_team_id != team_id AND (original_team_id = ? OR team_id = ?)");
$stmtPicks->execute([$teamId, $teamId]);
$picksOwned = $stmtPicks->fetch(PDO::FETCH_ASSOC);

// ── 6. Trocas ────────────────────────────────────────────────────
$stmtTrades = $pdo->prepare("SELECT COUNT(*) AS c FROM trades WHERE status='accepted' AND (from_team_id=? OR to_team_id=?)");
$stmtTrades->execute([$teamId, $teamId]);
$tradesCount = (int)$stmtTrades->fetch(PDO::FETCH_ASSOC)['c'];
try {
    $stmtMT = $pdo->prepare("SELECT COUNT(DISTINCT mt.id) AS c FROM multi_trades mt JOIN multi_trade_teams mtt ON mtt.trade_id=mt.id WHERE mt.status='accepted' AND mtt.team_id=?");
    $stmtMT->execute([$teamId]);
    $tradesCount += (int)$stmtMT->fetch(PDO::FETCH_ASSOC)['c'];
} catch (Exception $e) {}

// ── 7. Jogadores históricos ──────────────────────────────────────
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

// Média de OVR e idade usando apenas top 5 (titulares) por temporada
$stmtSeasons = $pdo->prepare("SELECT DISTINCT season_id, year FROM player_season_log WHERE team_id = ? AND year IS NOT NULL ORDER BY year ASC");
$stmtSeasons->execute([$teamId]);
$seasonsList = $stmtSeasons->fetchAll(PDO::FETCH_ASSOC);

$avgByYear = [];
$bestSeasonMeta = null;
foreach ($seasonsList as $sv) {
    $sTop = $pdo->prepare("SELECT ovr, age FROM player_season_log WHERE team_id = ? AND season_id = ? AND ovr > 0 ORDER BY ovr DESC LIMIT 5");
    $sTop->execute([$teamId, $sv['season_id']]);
    $rows = $sTop->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) continue;
    $avgOvr = round(array_sum(array_column($rows, 'ovr')) / count($rows), 1);
    $avgAge = round(array_sum(array_column($rows, 'age')) / count($rows), 1);
    $avgByYear[] = ['year' => $sv['year'], 'players' => count($rows), 'avg_ovr' => $avgOvr, 'avg_age' => $avgAge];
    if ($bestSeasonMeta === null || $avgOvr > $bestSeasonMeta['avg_ovr']) {
        $bestSeasonMeta = ['season_id' => $sv['season_id'], 'year' => $sv['year'], 'avg_ovr' => $avgOvr];
    }
}

// ── 7c. Melhor elenco já montado (temporada com maior OVR médio dos top 5) ──
$bestRoster = null;
if ($bestSeasonMeta) {
    try {
        $sBR = $pdo->prepare("SELECT player_name, position, ovr, age FROM player_season_log WHERE team_id = ? AND season_id = ? AND ovr > 0 ORDER BY ovr DESC LIMIT 8");
        $sBR->execute([$teamId, $bestSeasonMeta['season_id']]);
        $bestRoster = [
            'year'    => $bestSeasonMeta['year'] !== null ? (int)$bestSeasonMeta['year'] : null,
            'avg_ovr' => $bestSeasonMeta['avg_ovr'],
            'players' => $sBR->fetchAll(PDO::FETCH_ASSOC),
        ];
    } catch (Exception $e) { $bestRoster = null; }
}

// ── 7b. Posição final por temporada (season_standings) ───────────
$positionsByYear = [];
try {
    if ($pdo->query("SHOW TABLES LIKE 'season_standings'")->fetch()) {
        $hasConf = (bool)$pdo->query("SHOW COLUMNS FROM season_standings LIKE 'conference'")->fetch();
        $confCol = $hasConf ? 'ss.conference' : 'NULL AS conference';
        // conference_size = quantos times havia naquela conferência/temporada,
        // usado para escalar o eixo do gráfico de posições.
        $sizeExpr = $hasConf
            ? "(SELECT COUNT(*) FROM season_standings ss2 WHERE ss2.season_id = ss.season_id AND ss2.conference <=> ss.conference)"
            : "(SELECT COUNT(*) FROM season_standings ss2 WHERE ss2.season_id = ss.season_id)";
        $sPos = $pdo->prepare("
            SELECT ss.position, {$confCol}, s.year, s.season_number,
                   {$sizeExpr} AS conference_size
            FROM season_standings ss
            JOIN seasons s ON s.id = ss.season_id
            WHERE ss.team_id = ?
            ORDER BY s.year ASC, s.season_number ASC
        ");
        $sPos->execute([$teamId]);
        foreach ($sPos->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $positionsByYear[] = [
                'year'       => $r['year'] !== null ? (int)$r['year'] : null,
                'season_number' => $r['season_number'] !== null ? (int)$r['season_number'] : null,
                'position'   => (int)$r['position'],
                'conference' => $r['conference'],
                'conference_size' => isset($r['conference_size']) ? (int)$r['conference_size'] : null,
                'made_playoffs' => (int)$r['position'] <= 8,
            ];
        }
    }
} catch (Exception $e) { $positionsByYear = []; }

// ── 7d. Trades por ciclo ─────────────────────────────────────────
$tradesByCycle = [];
try {
    $sTC = $pdo->prepare("
        SELECT COALESCE(cycle, 0) AS cycle, COUNT(*) AS total
        FROM trades
        WHERE status = 'accepted' AND (from_team_id = ? OR to_team_id = ?)
        GROUP BY COALESCE(cycle, 0)
        ORDER BY cycle ASC
    ");
    $sTC->execute([$teamId, $teamId]);
    foreach ($sTC->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tradesByCycle[] = ['cycle' => (int)$r['cycle'], 'total' => (int)$r['total']];
    }
} catch (Exception $e) { $tradesByCycle = []; }

// ── 7e. Trades por time parceiro ─────────────────────────────────
$tradesByPartner = [];
try {
    $sTP = $pdo->prepare("
        SELECT other.id AS team_id, CONCAT(other.city, ' ', other.name) AS team_name,
               other.photo_url, COUNT(*) AS total
        FROM trades tr
        JOIN teams other ON other.id = CASE WHEN tr.from_team_id = ? THEN tr.to_team_id ELSE tr.from_team_id END
        WHERE tr.status = 'accepted' AND (tr.from_team_id = ? OR tr.to_team_id = ?)
        GROUP BY other.id, other.city, other.name, other.photo_url
        ORDER BY total DESC, team_name ASC
    ");
    $sTP->execute([$teamId, $teamId, $teamId]);
    foreach ($sTP->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tradesByPartner[] = [
            'team_id'   => (int)$r['team_id'],
            'team_name' => $r['team_name'],
            'photo_url' => $r['photo_url'],
            'total'     => (int)$r['total'],
        ];
    }
} catch (Exception $e) { $tradesByPartner = []; }

// ── 7f. Estatísticas comparativas da liga (mesmas métricas do estatisticas.php) ──
// Para cada métrica: valor do time + posição dele entre os times da mesma liga.
$leagueStats = [];
$teamLeague = $team['league'] ?? null;
if ($teamLeague) {
    $metricQueries = [
        'drafted' => "SELECT t.id, COUNT(p.id) AS val FROM teams t
                      LEFT JOIN players p ON p.drafted_by_team_id = t.id
                      WHERE t.league = ? GROUP BY t.id",
        'turnover' => "SELECT t.id, COUNT(DISTINCT psl.player_id) AS val FROM teams t
                       LEFT JOIN player_season_log psl ON psl.team_id = t.id
                       WHERE t.league = ? GROUP BY t.id",
        'fa' => "SELECT t.id, COUNT(far.id) AS val FROM teams t
                 LEFT JOIN fa_requests far ON far.winner_team_id = t.id AND far.status = 'assigned'
                 WHERE t.league = ? GROUP BY t.id",
        'avg_age' => "SELECT t.id, ROUND(AVG(p.age), 1) AS val FROM teams t
                      LEFT JOIN players p ON p.team_id = t.id
                      WHERE t.league = ? GROUP BY t.id",
        'avg_ovr' => "SELECT t.id, ROUND(AVG(p.ovr), 1) AS val FROM teams t
                      LEFT JOIN players p ON p.team_id = t.id
                      WHERE t.league = ? GROUP BY t.id",
    ];
    foreach ($metricQueries as $key => $sql) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute([$teamLeague]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) continue;
            // ordenar desc para achar a posição do time
            usort($rows, static fn($a, $b) => (float)$b['val'] <=> (float)$a['val']);
            $value = null; $rank = null;
            foreach ($rows as $i => $r) {
                if ((int)$r['id'] === $teamId) { $value = $r['val']; $rank = $i + 1; break; }
            }
            $leagueStats[$key] = [
                'value' => $value !== null ? (float)$value : null,
                'rank'  => $rank,
                'total' => count($rows),
            ];
        } catch (Exception $e) { /* métrica indisponível */ }
    }
}

// ── 8. Prêmios individuais ───────────────────────────────────────
$stmtAwards = $pdo->prepare("SELECT award_type, COUNT(*) AS total FROM season_awards WHERE team_id = ? GROUP BY award_type ORDER BY total DESC");
$stmtAwards->execute([$teamId]);
$awards = $stmtAwards->fetchAll(PDO::FETCH_ASSOC);

// ── 9. Elenco campeão ────────────────────────────────────────────
$champRoster = [];
if (!empty($champSeasons)) {
    $s = $pdo->prepare("SELECT player_name, position, ovr, age FROM player_season_log WHERE team_id = ? AND season_id = ? ORDER BY ovr DESC");
    $s->execute([$teamId, $champSeasons[0]['season_id']]);
    $champRoster = $s->fetchAll(PDO::FETCH_ASSOC);
}

// ── 10. Draftados pelo time ──────────────────────────────────────
$draftedPlayers = [];
try {
    $sD = $pdo->prepare("
        SELECT p.name, p.position, p.age,
               COALESCE(p.ovr_current, p.ovr, 0) AS ovr,
               p.drafted_season_number
        FROM players p
        WHERE p.drafted_by_team_id = ?
        ORDER BY p.drafted_season_number DESC, ovr DESC
    ");
    $sD->execute([$teamId]);
    $draftedPlayers = $sD->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── 11. GM Stats ─────────────────────────────────────────────────
$gmStats = [];
try {
    $sT = $pdo->prepare("SELECT COALESCE(tapas_used,0) AS tapas_used FROM teams WHERE id = ?");
    $sT->execute([$teamId]);
    $tapasRow = $sT->fetch(PDO::FETCH_ASSOC);
    $gmStats['tapas_used'] = (int)($tapasRow['tapas_used'] ?? 0);
} catch (Exception $e) { $gmStats['tapas_used'] = 0; }

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
        'played'       => (int)($ptsData['seasons_played'] ?? 0),
        'total_points' => (int)($ptsData['total_points'] ?? 0),
        'best_pts'     => (int)($ptsData['best_season_pts'] ?? 0),
    ],
    'playoffs' => [
        'appearances'   => (int)($playoffData['playoff_appearances'] ?? 0),
        'titles'        => (int)($titlesData['titles'] ?? 0),
        'runner_ups'    => (int)($titlesData['runner_ups'] ?? 0),
        'conf_finals'   => $playoffResults['conference_final'],
        'second_round'  => $playoffResults['second_round'],
        'first_round'   => $playoffResults['first_round'],
        'champ_seasons' => $champSeasons,
        'champ_roster'  => $champRoster,
    ],
    'regular'  => [
        'top1' => $top1Regular,
        'top4' => $top4Regular,
        'top8' => $top8Regular,
    ],
    'positions' => $positionsByYear,
    'best_roster' => $bestRoster,
    'trades_by_cycle' => $tradesByCycle,
    'trades_by_partner' => $tradesByPartner,
    'league_stats' => $leagueStats,
    'picks'    => [
        'traded' => (int)($picksOwned['traded'] ?? 0),
    ],
    'trades'   => $tradesCount,
    'players'  => [
        'total_ever'  => $totalPlayers,
        'best'        => $bestPlayer,
        'worst'       => $worstPlayer,
        'best_by_pos' => $bestByPos,
        'avg_by_year' => $avgByYear,
    ],
    'drafted'  => $draftedPlayers,
    'awards'   => $awards,
    'gm'       => $gmStats,
]);
