<?php
/**
 * Retrospectiva da liga: dados agregados de todas as temporadas para a página
 * retrospectiva.php. Tudo calculado na hora a partir de team_season_points e
 * season_history. Read-only.
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
header('Content-Type: application/json');

requireAuth();
$pdo = db();

$league = strtoupper((string)($_GET['league'] ?? 'NEXT'));
if (!in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
    echo json_encode(['success' => false, 'error' => 'Liga inválida']);
    exit;
}

/** Sigla de 3 letras a partir da cidade (ex.: "New York" -> NYA... usamos city+name). */
function abbr3(string $city, string $name): string
{
    $base = trim($city) !== '' ? $city : $name;
    $clean = preg_replace('/[^A-Za-zÀ-ÿ ]/u', '', $base);
    $parts = preg_split('/\s+/', trim($clean));
    $ascii = fn($s) => iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
    if (count($parts) >= 2) {
        return mb_strtoupper($ascii(mb_substr($parts[0], 0, 2) . mb_substr($parts[1], 0, 1)));
    }
    return mb_strtoupper($ascii(mb_substr($base, 0, 3)));
}

// ── Todas as temporadas da liga (não só as com pontos) ──
// O ranking sai da pontuação por time; quem não pontuou numa temporada conta 0.
$stmtSeasons = $pdo->prepare("
    SELECT s.id AS season_id, s.season_number, s.year
    FROM seasons s
    WHERE s.league = ?
    ORDER BY s.sprint_id ASC, s.season_number ASC, s.id ASC");
$stmtSeasons->execute([$league]);
$seasonsRaw = $stmtSeasons->fetchAll(PDO::FETCH_ASSOC);

if (!$seasonsRaw) {
    echo json_encode(['success' => true, 'league' => $league, 'empty' => true, 'seasons' => [], 'teams' => []]);
    exit;
}

$seasons = [];
$seasonOrder = []; // season_id => índice
foreach ($seasonsRaw as $i => $r) {
    $seasonOrder[(int)$r['season_id']] = $i;
    $seasons[] = [
        'season_id'     => (int)$r['season_id'],
        'season_number' => (int)$r['season_number'],
        'year'          => $r['year'] !== null ? (int)$r['year'] : null,
        'label'         => 'T' . ($i + 1),
    ];
}
$nSeasons = count($seasons);

// ── Times da liga + pontos por temporada ──
$stmtT = $pdo->prepare("SELECT id, city, name, photo_url FROM teams WHERE league = ? ORDER BY city, name");
$stmtT->execute([$league]);
$teamsRaw = $stmtT->fetchAll(PDO::FETCH_ASSOC);

$teams = [];
foreach ($teamsRaw as $t) {
    $teams[(int)$t['id']] = [
        'team_id'   => (int)$t['id'],
        'name'      => trim($t['city'] . ' ' . $t['name']),
        'short'     => trim($t['name']) ?: trim($t['city']),
        'abbr'      => abbr3($t['city'] ?? '', $t['name'] ?? ''),
        'photo_url' => $t['photo_url'] ?: null,
    ];
}

// pontos[team_id][seasonIndex] = pontos daquela temporada
$stmtP = $pdo->prepare("SELECT team_id, season_id, points FROM team_season_points WHERE league = ?");
$stmtP->execute([$league]);
$pointsBySeason = [];
foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $tid = (int)$r['team_id'];
    $sid = (int)$r['season_id'];
    if (!isset($seasonOrder[$sid]) || !isset($teams[$tid])) continue;
    $pointsBySeason[$tid][$seasonOrder[$sid]] = (int)$r['points'];
}

// ── Acumulado + ranking por temporada (o heatmap) ──
// Para cada temporada i, soma pontos até i e ranqueia os times.
$cumulative = []; // team_id => [cum por temporada]
foreach ($teams as $tid => $_) {
    $run = 0;
    for ($i = 0; $i < $nSeasons; $i++) {
        $run += $pointsBySeason[$tid][$i] ?? 0;
        $cumulative[$tid][$i] = $run;
    }
}
$heatmap = []; // team_id => [rank por temporada]
for ($i = 0; $i < $nSeasons; $i++) {
    $col = [];
    foreach ($teams as $tid => $_) {
        $col[$tid] = $cumulative[$tid][$i];
    }
    // ordena por pontos desc; empate mantém estável
    arsort($col);
    $rank = 0; $prevPts = null; $seen = 0;
    foreach ($col as $tid => $pts) {
        $seen++;
        if ($pts !== $prevPts) { $rank = $seen; $prevPts = $pts; }
        $heatmap[$tid][$i] = $rank;
    }
}

// ── season_history: campeões, vices e prêmios ──
$stmtH = $pdo->prepare("SELECT * FROM season_history WHERE league = ? ORDER BY sprint_number ASC, season_number ASC");
$stmtH->execute([$league]);
$history = $stmtH->fetchAll(PDO::FETCH_ASSOC);

$nameOf = fn($tid) => isset($teams[(int)$tid]) ? $teams[(int)$tid]['name'] : null;
$champions = [];
$titleCount = [];
$runnerCount = [];
$awards = [];
foreach ($history as $h) {
    $champId = (int)($h['champion_team_id'] ?? 0);
    $viceId  = (int)($h['runner_up_team_id'] ?? 0);
    if ($champId) $titleCount[$champId] = ($titleCount[$champId] ?? 0) + 1;
    if ($viceId)  $runnerCount[$viceId] = ($runnerCount[$viceId] ?? 0) + 1;
    $champions[] = [
        'season_number' => (int)$h['season_number'],
        'year'          => $h['year'] !== null ? (int)$h['year'] : null,
        'champion_id'   => $champId ?: null,
        'champion'      => $nameOf($champId),
        'champion_photo'=> isset($teams[$champId]) ? $teams[$champId]['photo_url'] : null,
        'runner_up'     => $nameOf($viceId),
    ];
    $awards[] = [
        'season_number' => (int)$h['season_number'],
        'year'          => $h['year'] !== null ? (int)$h['year'] : null,
        'mvp'   => ['player' => $h['mvp_player']   ?: null, 'team' => $nameOf($h['mvp_team_id'])],
        'dpoy'  => ['player' => $h['dpoy_player']  ?: null, 'team' => $nameOf($h['dpoy_team_id'])],
        'mip'   => ['player' => $h['mip_player']   ?: null, 'team' => $nameOf($h['mip_team_id'])],
        'sixth' => ['player' => $h['sixth_man_player'] ?: null, 'team' => $nameOf($h['sixth_man_team_id'])],
        'roy'   => ['player' => $h['roy_player']   ?: null, 'team' => $nameOf($h['roy_team_id'])],
    ];
}

// ── Leaderboard all-time (pontos acumulados) ──
$leaderboard = [];
foreach ($teams as $tid => $t) {
    $total = $cumulative[$tid][$nSeasons - 1] ?? 0;
    $played = 0; $best = 0; $sum = 0;
    for ($i = 0; $i < $nSeasons; $i++) {
        $p = $pointsBySeason[$tid][$i] ?? null;
        if ($p !== null) { $played++; $sum += $p; if ($p > $best) $best = $p; }
    }
    $leaderboard[] = [
        'team_id'   => $tid,
        'name'      => $t['name'],
        'abbr'      => $t['abbr'],
        'photo_url' => $t['photo_url'],
        'total'     => $total,
        'played'    => $played,
        'avg'       => $played ? round($sum / $played, 1) : 0,
        'best'      => $best,
        'titles'    => $titleCount[$tid] ?? 0,
        'runner_ups'=> $runnerCount[$tid] ?? 0,
        'final_rank'=> $heatmap[$tid][$nSeasons - 1] ?? null,
    ];
}
usort($leaderboard, fn($a, $b) => $b['total'] <=> $a['total']);

// ── Superlativos ──
// Maior pontuação numa única temporada
$bestSeasonRow = null;
$stmtBest = $pdo->prepare("SELECT team_id, season_id, points FROM team_season_points WHERE league = ? ORDER BY points DESC LIMIT 1");
$stmtBest->execute([$league]);
$b = $stmtBest->fetch(PDO::FETCH_ASSOC);
if ($b) {
    $sidx = $seasonOrder[(int)$b['season_id']] ?? null;
    $bestSeasonRow = [
        'team'   => $nameOf($b['team_id']),
        'points' => (int)$b['points'],
        'season' => $sidx !== null ? $seasons[$sidx]['label'] : null,
        'year'   => $sidx !== null ? $seasons[$sidx]['year'] : null,
    ];
}
// Mais temporadas em 1º no acumulado
$weeksAtTop = [];
for ($i = 0; $i < $nSeasons; $i++) {
    foreach ($teams as $tid => $_) {
        if (($heatmap[$tid][$i] ?? 99) === 1) $weeksAtTop[$tid] = ($weeksAtTop[$tid] ?? 0) + 1;
    }
}
arsort($weeksAtTop);
$topDog = null;
foreach ($weeksAtTop as $tid => $cnt) { $topDog = ['team' => $nameOf($tid), 'seasons' => $cnt]; break; }

// Maior escalada: melhor diferença de rank (primeira temporada -> última)
$biggestRiser = null; $bestDelta = -999;
foreach ($teams as $tid => $_) {
    $first = $heatmap[$tid][0] ?? null;
    $last  = $heatmap[$tid][$nSeasons - 1] ?? null;
    if ($first === null || $last === null) continue;
    $delta = $first - $last; // positivo = subiu
    if ($delta > $bestDelta) { $bestDelta = $delta; $biggestRiser = ['team' => $nameOf($tid), 'from' => $first, 'to' => $last, 'delta' => $delta]; }
}
// Mais consistente: menor rank médio no acumulado
$bestAvgRank = null; $bestAvg = 999;
foreach ($teams as $tid => $_) {
    $s = 0; $c = 0;
    for ($i = 0; $i < $nSeasons; $i++) { if (isset($heatmap[$tid][$i])) { $s += $heatmap[$tid][$i]; $c++; } }
    if ($c && ($s / $c) < $bestAvg) { $bestAvg = $s / $c; $bestAvgRank = ['team' => $nameOf($tid), 'avg' => round($s / $c, 1)]; }
}

// mais titulado
$mostTitles = null;
if ($titleCount) { arsort($titleCount); foreach ($titleCount as $tid => $cnt) { $mostTitles = ['team' => $nameOf($tid), 'titles' => $cnt]; break; } }

echo json_encode([
    'success'      => true,
    'league'       => $league,
    'seasons'      => $seasons,
    'teams'        => array_values($teams),
    'heatmap'      => $heatmap,      // team_id => [rank por temporada]
    'cumulative'   => $cumulative,   // team_id => [pontos acumulados por temporada]
    'points'       => $pointsBySeason,
    'champions'    => $champions,
    'awards'       => $awards,
    'leaderboard'  => $leaderboard,
    'superlatives' => [
        'most_titles'    => $mostTitles,
        'best_season'    => $bestSeasonRow,
        'top_dog'        => $topDog,        // mais temporadas em 1º
        'biggest_riser'  => $biggestRiser,
        'most_consistent'=> $bestAvgRank,
        'n_seasons'      => $nSeasons,
        'n_champions'    => count(array_unique(array_filter(array_map(fn($c) => $c['champion_id'], $champions)))),
    ],
]);
