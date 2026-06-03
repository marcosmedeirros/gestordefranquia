<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

// Time do usuário para sidebar
$myTeam = null;
try {
    $s = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
    $s->execute([$user['id']]);
    $myTeam = $s->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {}

// Ano da temporada para sidebar
$seasonDisplayYear = (int)date('Y');
try {
    $s = $pdo->prepare("
        SELECT s.season_number, sp.start_year
        FROM seasons s INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ('completed'))
        ORDER BY s.created_at DESC LIMIT 1
    ");
    $s->execute([$user['league']]);
    $cs = $s->fetch(PDO::FETCH_ASSOC);
    if ($cs && isset($cs['start_year'], $cs['season_number']))
        $seasonDisplayYear = (int)$cs['start_year'] + (int)$cs['season_number'] - 1;
} catch (Exception $e) {}

$awardLabels = [
    'mvp'               => 'MVP',
    'dpoy'              => 'Melhor Defensor',
    'smoy'              => '6º Homem',
    'mip'               => 'Mais Melhorado',
    'roy'               => 'Calouro do Ano',
    'fmvp'              => 'MVP das Finais',
    'all_nba_1'         => 'All-NBA 1ª Equipe',
    'all_nba_2'         => 'All-NBA 2ª Equipe',
    'all_nba_3'         => 'All-NBA 3ª Equipe',
    'all_defense_1'     => 'All-Defense 1ª',
    'all_defense_2'     => 'All-Defense 2ª',
    'all_star_1'        => 'All-Star 1ª Equipe',
    'all_star_2'        => 'All-Star 2ª Equipe',
    'all_star_3'        => 'All-Star 3ª Equipe',
    'all_rookie'        => 'All-Rookie',
    'coach_of_year'     => 'Técnico do Ano',
    'executive_of_year' => 'GM do Ano',
    'champion'          => 'Campeão',
    'runner_up'         => 'Vice-Campeão',
    'conf_champ_leste'  => 'Conf. Leste',
    'conf_champ_oeste'  => 'Conf. Oeste',
];

$aiTagMeta = [
    'Contending' => ['label' => 'Contending', 'desc' => 'Candidato ao titulo. Janela de contenda aberta.'],
    'Buying'     => ['label' => 'Buying',     'desc' => 'Time competitivo buscando reforcos para o playoff.'],
    'Selling'    => ['label' => 'Selling',    'desc' => 'Time em transicao, buscando ativos futuros.'],
    'Rebuilding' => ['label' => 'Rebuilding', 'desc' => 'Time em reconstrucao com foco em jovens.'],
];

function computeAiTagPHP(?float $avgOvr, ?float $maxOvr, ?float $avgAge): ?string {
    if ($avgOvr === null || $avgOvr < 1) return null;
    if ($avgOvr >= 86 && ($maxOvr ?? 0) >= 89) return 'Contending';
    if ($avgOvr >= 82) return 'Buying';
    if (($avgAge ?? 25) >= 31 || $avgOvr < 78) return 'Rebuilding';
    return 'Selling';
}

// Simula TODAS as temporadas restantes do sprint, acumula pontos e retorna:
// - matrix: probabilidade de cada time terminar em cada posição no RANKING FINAL do sprint
// - avg_expected_pts: média de pontos extras esperados sobre todas as seasons restantes
function simulateSprintMatrix(array $teams, int $seasonsLeft, string $league, int $iterations = 350): array {
    $n = count($teams);
    if ($n === 0) return ['matrix' => [], 'avg_expected_pts' => []];
    if ($seasonsLeft < 1) $seasonsLeft = 1;

    $counts      = array_fill(0, $n, array_fill(0, $n, 0));
    $totalPts    = array_fill(0, $n, 0.0);
    $baseStr     = array_map(fn($t) => max(0.01, (float)($t['composite'] ?? 1.0)), $teams);

    for ($iter = 0; $iter < $iterations; $iter++) {
        $cumPts = array_fill(0, $n, 0.0);

        for ($s = 1; $s <= $seasonsLeft; $s++) {
            // Ajusta força de cada time para a season s (OVR growth + tag + picks + variância)
            $adjS = [];
            foreach ($teams as $i => $t) {
                $age    = (float)($t['avg_age']    ?? 26);
                $tag    = $t['ai_tag'] ?? '';
                $picks  = (int)  ($t['picks_count'] ?? 0);
                $maxOvr = (float)($t['max_ovr']    ?? 80);

                $ovrG  = $age < 22 ? 3.5 : ($age < 25 ? 2.5 : ($age < 28 ? 1.5 : ($age < 31 ? 0.5 : -0.8)));
                $ovrF  = $ovrG * $s * 0.7;
                $ageF  = ($age > 30 ? -($age - 30) * 1.5 * $s : ($age < 25 ? (25 - $age) * 0.9 * $s : 0)) + $ovrF;

                if ($tag === 'Contending')     $tagF = -3.0 * $s;
                elseif ($tag === 'Buying')     $tagF = -1.2 * $s;
                elseif ($tag === 'Selling')    $tagF =  1.8 * $s;
                elseif ($tag === 'Rebuilding') $tagF =  3.5 * $s;
                else                           $tagF = 0;

                $picksF = min($picks * 0.6, 9) * (1 + $s * 0.2);
                $franqF = $maxOvr >= 92 ? -0.5 * $s : ($maxOvr <= 78 ? 0.4 * $s : 0);

                $u1 = max(1e-9, mt_rand(1, 1000000) / 1000000.0);
                $u2 = max(1e-9, mt_rand(1, 1000000) / 1000000.0);
                $var = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2) * $s * 2.5;

                $adjS[$i] = max(0.01, $baseStr[$i] + $ageF + $tagF + $picksF + $franqF + $var);
            }

            // Simula a season: performance exponencial
            $perfs = [];
            foreach ($adjS as $i => $str) {
                $u = max(1e-9, mt_rand(1, 1000000) / 1000000.0);
                $perfs[$i] = $str * (-log($u));
            }
            $order = range(0, $n - 1);
            usort($order, fn($a, $b) => $perfs[$b] <=> $perfs[$a]);

            // Acumula pontos (playoff + seed)
            foreach ($order as $pos => $idx) {
                if ($pos === 0)     $pts = 11 + 4;
                elseif ($pos === 1) $pts = 8  + 3;
                elseif ($pos <= 3)  $pts = 6  + 3;
                elseif ($pos <= 7)  $pts = 3  + 2;
                elseif ($pos <= 15) $pts = 1;
                else                $pts = 0;
                $cumPts[$idx] += $pts;
            }

            // Prêmios individuais (5 × 1pt por season, distribuídos por força)
            $adjSum = array_sum($adjS) ?: 1;
            foreach ($adjS as $i => $str) $cumPts[$i] += ($str / $adjSum) * 5;

            // NBA Cup (ELITE only): +2pts para time ≠ campeão
            if ($league === 'ELITE') {
                $champIdx = $order[0];
                $cupAdj   = $adjS;
                $cupAdj[$champIdx] *= 0.3;
                $cupOrd = range(0, $n - 1);
                usort($cupOrd, fn($a, $b) => $cupAdj[$b] <=> $cupAdj[$a]);
                $cumPts[$cupOrd[0]] += 2;
            }
        }

        // Ranking final do sprint por pontos acumulados
        $finalOrd = range(0, $n - 1);
        usort($finalOrd, fn($a, $b) => $cumPts[$b] <=> $cumPts[$a]);
        foreach ($finalOrd as $pos => $idx) $counts[$idx][$pos]++;
        foreach ($cumPts as $i => $pts) $totalPts[$i] += $pts;
    }

    return [
        'matrix'           => array_map(fn($row) => array_map(fn($c) => round($c / $iterations * 100, 1), $row), $counts),
        'avg_expected_pts' => array_map(fn($t) => round($t / $iterations, 1), $totalPts),
    ];
}

function buildLeagueAnalysis(
    array $powerRanking, array $ranking, array $picksPerTeam,
    ?int $currentSeasonNum, ?int $totalSeasons, string $league
): array {
    if (empty($powerRanking)) return ['has_data' => false];
    $n = count($powerRanking);
    $seasonsLeft = ($totalSeasons !== null && $currentSeasonNum !== null)
        ? max(0, $totalSeasons - $currentSeasonNum) : null;

    // Mapa ranking_pts por nome de time
    $rankPtsMap = [];
    foreach ($ranking as $r) $rankPtsMap[$r['team_name']] = (int)($r['total_points'] ?? 0);

    // Normalização dos fatores
    $maxPow   = max(array_column($powerRanking, 'score') ?: [1]) ?: 1;
    $maxPts   = max(array_values($rankPtsMap) ?: [1]) ?: 1;
    $maxPicks = max(array_values($picksPerTeam) ?: [1]) ?: 1;

    // Score composto: power 55% + pontos 30% + picks 15%
    $teams = [];
    foreach ($powerRanking as $pr) {
        $tid   = (int)($pr['team_id'] ?? 0);
        $pts   = $rankPtsMap[$pr['team_name']] ?? 0;
        $picks = $picksPerTeam[$tid] ?? 0;
        $composite = ($pr['score'] / $maxPow) * 0.55
                   + ($pts / $maxPts)          * 0.30
                   + ($picks / $maxPicks)       * 0.15;
        $teams[] = array_merge($pr, [
            'ranking_pts' => $pts,
            'picks_count' => $picks,
            'composite'   => round($composite * 100, 3),
        ]);
    }

    // Softmax sobre composite para probabilidade de campeão
    $comps  = array_column($teams, 'composite');
    $maxC   = max($comps ?: [0]);
    $exps   = array_map(fn($c) => exp(($c - $maxC) / 10.0), $comps);
    $sumExp = array_sum($exps) ?: 1;

    $proj = [];
    foreach ($teams as $i => $t) {
        $proj[] = array_merge($t, ['prob' => round($exps[$i] / $sumExp * 100, 1)]);
    }
    usort($proj, fn($a, $b) => $b['prob'] <=> $a['prob']);

    // Finalistas cientes de conferência
    $finalists = []; $usedConf = [];
    foreach ($proj as $t) {
        $conf = trim((string)($t['conference'] ?? ''));
        if ($conf !== '' && in_array($conf, $usedConf, true)) continue;
        $finalists[] = $t;
        if ($conf !== '') $usedConf[] = $conf;
        if (count($finalists) >= 2) break;
    }
    if (count($finalists) < 2) $finalists = array_slice($proj, 0, 2);

    // Promoção (NEXT/RISE)
    $promoProj = null;
    if ($league !== 'ELITE') {
        $promoProj = [];
        foreach ($proj as $i => $t) {
            $x = ($n > 1) ? $i / ($n - 1) : 0;
            $promoProj[] = array_merge($t, ['promo_prob' => round((1 / (1 + exp(5 * ($x - 0.28)))) * 100, 1)]);
        }
        $promoProj = array_slice($promoProj, 0, 6);
    }

    // Rebaixamento
    $releProj = [];
    foreach (array_reverse($proj) as $i => $t) {
        $x = ($n > 1) ? $i / ($n - 1) : 0;
        $releProj[] = array_merge($t, ['rele_prob' => round((1 / (1 + exp(5 * ($x - 0.28)))) * 100, 1)]);
    }
    $releProj = array_slice($releProj, 0, 6);

    // Projeção por temporada restante — análise completa: tag, idade, picks, OVR, variância
    $seasonProjs = [];
    if ($seasonsLeft && $seasonsLeft > 0) {
        mt_srand(crc32($league . ($currentSeasonNum ?? 0))); // estável por temporada
        for ($s = 1; $s <= min($seasonsLeft, 5); $s++) {
            $adj = array_map(function($t) use ($s) {
                $age    = (float)($t['avg_age']    ?? 26);
                $picks  = (int)  ($t['picks_count'] ?? 0);
                $maxOvr = (float)($t['max_ovr']    ?? 80);
                $tag    = $t['ai_tag'] ?? '';

                // OVR growth: NBA2K sem lesões/fadiga → 1-4 OVR/temp por faixa etária
                // <22: +3.5 | <25: +2.5 | <28: +1.5 | <31: +0.5 | 31+: declínio
                $ovrGrowth = $age < 22 ? 3.5 : ($age < 25 ? 2.5 : ($age < 28 ? 1.5 : ($age < 31 ? 0.5 : -0.8)));
                $ovrFactor = $ovrGrowth * $s * 0.7;

                // Idade: velhos caem, jovens sobem (amplificado pelo crescimento de OVR)
                $ageFactor = ($age > 30 ? -($age - 30) * 1.5 * $s : ($age < 25 ? (25 - $age) * 0.9 * $s : 0))
                           + $ovrFactor;

                // Tag: ajuste de força por perfil do time
                if ($tag === 'Contending')     $tagF = -3.0 * $s; // janela fechando rápido
                elseif ($tag === 'Buying')     $tagF = -1.2 * $s; // leve declínio
                elseif ($tag === 'Selling')    $tagF =  1.8 * $s; // vendendo para o futuro
                elseif ($tag === 'Rebuilding') $tagF =  3.5 * $s; // ascensão forte
                else                           $tagF =  0;

                // Picks: mais picks = mais ativos futuros = crescimento
                $picksFactor = min($picks * 0.6, 9) * (1 + $s * 0.2);

                // Franquia: estrela (max OVR >= 92) mais volátil com o tempo (já inclusa no ovrFactor)
                $franqFactor = $maxOvr >= 92 ? -0.5 * $s : ($maxOvr <= 78 ? 0.4 * $s : 0);

                // Variância Box-Muller (aumenta com a distância no tempo)
                $u1 = max(1e-9, mt_rand(1, 1000000) / 1000000.0);
                $u2 = max(1e-9, mt_rand(1, 1000000) / 1000000.0);
                $variance = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2) * $s * 3.0;

                return array_merge($t, [
                    'adj' => max(0.01, $t['composite'] + $ageFactor + $tagF + $picksFactor + $franqFactor + $variance)
                ]);
            }, $proj);

            $adjC  = array_column($adj, 'adj');
            $adjMx = max($adjC ?: [0]);
            $adjEx = array_map(fn($c) => exp(($c - $adjMx) / 10.0), $adjC);
            $adjSm = array_sum($adjEx) ?: 1;
            $sp    = [];
            foreach ($adj as $i => $t) $sp[] = array_merge($t, ['sp' => round($adjEx[$i] / $adjSm * 100, 1)]);
            usort($sp, fn($a, $b) => $b['sp'] <=> $a['sp']);

            // Finalistas cientes de conferência para esta season
            $sF = []; $sCF = [];
            foreach ($sp as $t) {
                $conf = trim((string)($t['conference'] ?? ''));
                if ($conf !== '' && in_array($conf, $sCF, true)) continue;
                $sF[] = $t;
                if ($conf !== '') $sCF[] = $conf;
                if (count($sF) >= 2) break;
            }
            if (count($sF) < 2) $sF = array_slice($sp, 0, 2);

            $seasonProjs[$s] = [
                'finalist1' => $sF[0] ?? null, // conf 1 champ
                'finalist2' => $sF[1] ?? null, // conf 2 champ
                'champion'  => $sF[0] ?? null, // maior sp = campeão geral
            ];
        }
    }

    // ── Simulação do sprint completo (todas as temporadas restantes) ──────────
    // Cada iteração simula N seasons, acumula pontos e registra ranking final do sprint.
    $sprintSim  = simulateSprintMatrix($teams, $seasonsLeft ?? 1, $league);
    $rawMx      = $sprintSim['matrix'];        // prob de cada time no ranking final do sprint
    $avgExpPts  = $sprintSim['avg_expected_pts']; // pts médios esperados de todas as seasons

    $nameIdx   = array_column($teams, 'team_name');
    $matrixRaw = [];
    foreach ($proj as $t) {
        $oi = array_search($t['team_name'], $nameIdx);
        $matrixRaw[] = [
            'team'  => $t,
            'probs' => ($oi !== false && isset($rawMx[$oi])) ? $rawMx[$oi] : array_fill(0, $n, 0),
            'avg_exp' => $oi !== false ? ($avgExpPts[$oi] ?? 0) : 0,
        ];
    }

    // Greedy assignment: para cada posição do sprint, aloca o time com maior prob
    $assigned = []; $matrix = [];
    for ($pos = 0; $pos < $n; $pos++) {
        $bestIdx = -1; $bestProb = -1;
        foreach ($matrixRaw as $i => $row) {
            if (isset($assigned[$i])) continue;
            $p = $row['probs'][$pos] ?? 0;
            if ($p > $bestProb) { $bestProb = $p; $bestIdx = $i; }
        }
        if ($bestIdx >= 0) {
            $assigned[$bestIdx] = true;
            $matrix[] = array_merge($matrixRaw[$bestIdx], ['peak_col' => $pos]);
        }
    }

    // PROJ = pontos atuais + pontos esperados acumulados de TODAS as seasons restantes
    foreach ($matrix as &$mrow) {
        $currentPts       = $mrow['team']['ranking_pts'] ?? 0;
        $exp              = $mrow['avg_exp'] ?? 0;
        $mrow['exp_pts']  = round($exp, 1);
        $mrow['proj_pts'] = $currentPts + (int)round($exp);
    }
    unset($mrow);

    // ── NBA Cup (ELITE only): 2pts, geralmente vence time ≠ campeão ──
    $nbaCupWinner = null;
    if ($league === 'ELITE' && !empty($proj)) {
        $cupArr = array_map(function($t) {
            $tag = $t['ai_tag'] ?? '';
            if ($tag === 'Buying')         $mod = 1.5;
            elseif ($tag === 'Selling')    $mod = 1.3;
            elseif ($tag === 'Contending') $mod = 0.55;
            elseif ($tag === 'Rebuilding') $mod = 0.75;
            else                           $mod = 1.0;
            return array_merge($t, ['cup_s' => $t['composite'] * $mod]);
        }, $proj);
        usort($cupArr, fn($a, $b) => $b['cup_s'] <=> $a['cup_s']);
        foreach ($cupArr as $t) {
            if ($t['team_name'] !== ($proj[0]['team_name'] ?? '')) {
                $nbaCupWinner = $t; break;
            }
        }
        if (!$nbaCupWinner) $nbaCupWinner = $cupArr[0] ?? null;
        // NBA Cup pts já estão embutidos na simulateSprintMatrix — apenas guardamos o vencedor para exibição
    }

    // ── Reordenar matrix por proj_pts decrescente ──────────────────────────────
    // Garante que quem tem mais PROJ está na linha 1, e assim por diante,
    // alinhando posição projetada com pontuação projetada.
    usort($matrix, fn($a, $b) => ($b['proj_pts'] ?? 0) <=> ($a['proj_pts'] ?? 0));
    foreach ($matrix as $i => &$mrow) {
        $mrow['peak_col'] = $i; // célula destaque = coluna correspondente à posição da linha
    }
    unset($mrow);

    return [
        'has_data'       => true,
        'champ_proj'     => array_slice($proj, 0, 5),
        'finalists'      => $finalists,
        'promo_proj'     => $promoProj,
        'rele_proj'      => $releProj,
        'season_projs'   => $seasonProjs,
        'matrix'         => $matrix,
        'team_count'     => $n,
        'seasons_left'   => $seasonsLeft,
        'total_seasons'  => $totalSeasons,
        'current_season' => $currentSeasonNum,
        'nba_cup'        => $nbaCupWinner,
        'league'         => $league,
    ];
}

function buildTop5Stats(array $starters): array {
    $list = array_values(array_filter($starters));
    if (!$list) {
        return ['count' => 0, 'avg_ovr' => 0, 'avg_age' => 0, 'max_ovr' => 0];
    }
    usort($list, static fn($a, $b) => (int)($b['ovr'] ?? 0) - (int)($a['ovr'] ?? 0));
    $top = array_slice($list, 0, 5);
    $count = count($top);
    $sumOvr = 0;
    $sumAge = 0;
    $maxOvr = 0;
    foreach ($top as $p) {
        $ovr = (int)($p['ovr'] ?? 0);
        $age = (int)($p['age'] ?? 0);
        $sumOvr += $ovr;
        $sumAge += $age;
        if ($ovr > $maxOvr) $maxOvr = $ovr;
    }
    $avgOvr = $count ? round($sumOvr / $count, 1) : 0;
    $avgAge = $count ? round($sumAge / $count, 1) : 0;
    return ['count' => $count, 'avg_ovr' => $avgOvr, 'avg_age' => $avgAge, 'max_ovr' => $maxOvr];
}

$leagueOrder = ['ELITE', 'NEXT', 'RISE'];
$leagueMeta  = [
    'ELITE' => ['color' => '#f59e0b', 'icon' => 'bi-trophy-fill',       'label' => 'Liga Elite'],
    'NEXT'  => ['color' => '#3b82f6', 'icon' => 'bi-lightning-charge-fill', 'label' => 'Liga Next'],
    'RISE'  => ['color' => '#22c55e', 'icon' => 'bi-graph-up-arrow',    'label' => 'Liga Rise'],
];
$leagueData  = [];

$capStmt = null;
try { $capStmt = $pdo->prepare('SELECT COALESCE(SUM(ovr),0) FROM (SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8) top8'); } catch (Exception $e) {}

foreach ($leagueOrder as $league) {
    // Temporada atual e total de temporadas da liga
    $leagueMaxSeasons = ['ELITE' => 20, 'NEXT' => 20, 'RISE' => 15];
    $currentSeasonNum = null;
    $totalSeasons = $leagueMaxSeasons[$league] ?? null;
    try {
        $s = $pdo->prepare("SELECT season_number FROM seasons WHERE league = ? AND status NOT IN ('completed') ORDER BY created_at DESC LIMIT 1");
        $s->execute([$league]);
        $r = $s->fetchColumn();
        if ($r !== false) $currentSeasonNum = (int)$r;
    } catch (Exception $e) {}
    // Se não houver temporada ativa, pega a última encerrada
    if ($currentSeasonNum === null) {
        try {
            $s = $pdo->prepare("SELECT MAX(season_number) FROM seasons WHERE league = ?");
            $s->execute([$league]);
            $r = $s->fetchColumn();
            if ($r !== false) $currentSeasonNum = (int)$r;
        } catch (Exception $e) {}
    }

    // Times
    $teams = [];
    try {
        $s = $pdo->prepare("
            SELECT t.id, t.city, t.name, t.photo_url AS team_photo, t.team_tag,
                   t.public_enabled, t.public_slug,
                   u.name AS owner_name,
                   (SELECT AVG(p.ovr) FROM players p WHERE p.team_id = t.id AND LOWER(p.role) = 'titular') AS starters_avg_ovr,
                   (SELECT MAX(p.ovr) FROM players p WHERE p.team_id = t.id AND LOWER(p.role) = 'titular') AS starters_max_ovr,
                   (SELECT AVG(p.age) FROM players p WHERE p.team_id = t.id) AS avg_age
            FROM teams t
            INNER JOIN users u ON u.id = t.user_id
            WHERE t.league = ?
            ORDER BY t.name ASC
        ");
        $s->execute([$league]);
        $teams = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // CAP e titulares por time
    foreach ($teams as &$t) {
        $t['cap_top8'] = 0;
        if ($capStmt) {
            try { $capStmt->execute([$t['id']]); $t['cap_top8'] = (int)$capStmt->fetchColumn(); } catch (Exception $e) {}
        }
        $byPos = ['PG'=>null,'SG'=>null,'SF'=>null,'PF'=>null,'C'=>null];
        try {
            $s = $pdo->prepare("SELECT name, position, ovr, age FROM players WHERE team_id = ? AND role = 'Titular' ORDER BY FIELD(position,'PG','SG','SF','PF','C') LIMIT 10");
            $s->execute([$t['id']]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
                if (array_key_exists($p['position'], $byPos) && $byPos[$p['position']] === null)
                    $byPos[$p['position']] = $p;
            }
        } catch (Exception $e) {}
        $t['starters'] = $byPos;
    }
    unset($t);
    usort($teams, fn($a, $b) => $b['cap_top8'] - $a['cap_top8']);

    // Última temporada encerrada
    $lastSeason = null; $seasonYear = null;
    try {
        $s = $pdo->prepare("
            SELECT s.id, s.season_number, sp.start_year, s.year
            FROM seasons s INNER JOIN sprints sp ON s.sprint_id = sp.id
            WHERE s.league = ? AND s.status = 'completed'
            ORDER BY s.created_at DESC LIMIT 1
        ");
        $s->execute([$league]);
        $lastSeason = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($lastSeason) {
            $seasonYear = isset($lastSeason['start_year'], $lastSeason['season_number'])
                ? (int)$lastSeason['start_year'] + (int)$lastSeason['season_number'] - 1
                : (int)($lastSeason['year'] ?? 0);
        }
    } catch (Exception $e) {}

    // Campeão e vice — busca em season_history (fonte principal) e playoff_results como fallback
    $champion = null; $runnerUp = null;
    $championTeamId = null; $runnerUpTeamId = null;
    try {
        $s = $pdo->prepare("
            SELECT sh.champion_team_id, sh.runner_up_team_id, sh.year AS hist_year,
                   tc.city AS champ_city, tc.name AS champ_name, tc.photo_url AS team_photo_c, uc.name AS champ_owner,
                   tr.city AS ru_city,   tr.name AS ru_name,   tr.photo_url AS team_photo_r, ur.name AS ru_owner
            FROM season_history sh
            LEFT JOIN teams tc ON sh.champion_team_id = tc.id
            LEFT JOIN users uc ON tc.user_id = uc.id
            LEFT JOIN teams tr ON sh.runner_up_team_id = tr.id
            LEFT JOIN users ur ON tr.user_id = ur.id
            WHERE sh.league = ?
            ORDER BY sh.year DESC, sh.sprint_number DESC, sh.season_number DESC, sh.id DESC
            LIMIT 1
        ");
        $s->execute([$league]);
        $sh = $s->fetch(PDO::FETCH_ASSOC);
        if ($sh && $sh['champion_team_id']) {
            $championTeamId = (int)$sh['champion_team_id'];
            $champion = ['city'=>$sh['champ_city'],'team_name'=>$sh['champ_name'],'team_photo'=>$sh['team_photo_c'],'owner_name'=>$sh['champ_owner']];
            if (!$seasonYear && !empty($sh['hist_year'])) $seasonYear = (int)$sh['hist_year'];
        }
        if ($sh && $sh['runner_up_team_id']) {
            $runnerUpTeamId = (int)$sh['runner_up_team_id'];
            $runnerUp = ['city'=>$sh['ru_city'],'team_name'=>$sh['ru_name'],'team_photo'=>$sh['team_photo_r'],'owner_name'=>$sh['ru_owner']];
        }
    } catch (Exception $e) {}
    // Fallback: playoff_results
    if (!$champion && $lastSeason) {
        try {
            $s = $pdo->prepare("
                SELECT pr.position, t.city, t.name AS team_name, t.photo_url AS team_photo, u.name AS owner_name
                FROM playoff_results pr
                JOIN teams t ON pr.team_id = t.id
                JOIN users u ON t.user_id = u.id
                WHERE pr.season_id = ? AND pr.position IN ('champion','runner_up')
            ");
            $s->execute([$lastSeason['id']]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                if ($r['position'] === 'champion') {
                    $champion = $r;
                } else {
                    $runnerUp = $r;
                }
            }
        } catch (Exception $e) {}
    }

    // Prêmios — busca na season_history (fonte principal)
    $awards = [];
    try {
        // Detectar se coluna roy_player existe
        $chk = $pdo->query("SHOW COLUMNS FROM season_history LIKE 'roy_player'");
        $hasRoy = $chk && $chk->rowCount() > 0;
        $roySel = $hasRoy ? ', sh.roy_player' : ', NULL AS roy_player';
        $s = $pdo->prepare("
            SELECT sh.mvp_player, sh.dpoy_player, sh.mip_player, sh.sixth_man_player {$roySel}
            FROM season_history sh
            WHERE sh.league = ?
            ORDER BY sh.year DESC, sh.sprint_number DESC, sh.season_number DESC, sh.id DESC
            LIMIT 1
        ");
        $s->execute([$league]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $map = ['mvp'=>'mvp_player','dpoy'=>'dpoy_player','mip'=>'mip_player','smoy'=>'sixth_man_player','roy'=>'roy_player'];
            foreach ($map as $type => $col) {
                if (!empty($row[$col])) $awards[] = ['award_type'=>$type,'player_name'=>$row[$col]];
            }
        }
    } catch (Exception $e) {}

    // Hall da Fama
    $hof = [];
    try {
        $s = $pdo->prepare("
            SELECT hof.titles,
                   COALESCE(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')), hof.team_name) AS display_team,
                   COALESCE(u.name, hof.gm_name) AS display_gm,
                   t.photo_url AS team_photo
            FROM hall_of_fame hof
            LEFT JOIN teams t ON hof.team_id = t.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE hof.league = ? AND hof.is_active = 1
            ORDER BY hof.titles DESC LIMIT 5
        ");
        $s->execute([$league]);
        $hof = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // Player count
    $playerCount = 0;
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM players p JOIN teams t ON p.team_id = t.id WHERE t.league = ?");
        $s->execute([$league]);
        $playerCount = (int)$s->fetchColumn();
    } catch (Exception $e) {}

    // Trades count
    $tradesCount = 0;
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM trades WHERE league = ? AND status = 'accepted'");
        $s->execute([$league]);
        $tradesCount = (int)$s->fetchColumn();
    } catch (Exception $e) {}

    // Avg CAP
    $avgCap = count($teams) > 0 ? (int)round(array_sum(array_column($teams, 'cap_top8')) / count($teams)) : 0;

    // Ranking da liga (pontos acumulados)
    $ranking = [];
    try {
        $s = $pdo->prepare("
            SELECT CONCAT(t.city,' ',t.name) AS team_name, t.photo_url AS team_photo,
                   COALESCE(t.ranking_points, 0) AS total_points,
                   COALESCE(t.ranking_titles, 0) AS total_titles,
                   u.name AS owner_name
            FROM teams t
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.league = ?
            ORDER BY COALESCE(t.ranking_points,0) DESC, COALESCE(t.ranking_titles,0) DESC, t.name ASC
        ");
        $s->execute([$league]);
        $ranking = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {}

    // Power ranking (quinteto, idade, campeao/vice e tag IA)
    $powerRanking = [];
    foreach ($teams as $t) {
        $stats = buildTop5Stats($t['starters'] ?? []);
        $avgOvr = $stats['avg_ovr'];
        $avgAge = $stats['avg_age'];
        $maxOvr = $stats['max_ovr'];
        $count = $stats['count'];

        $hasFranchise = $maxOvr >= 89;
        $youthFactor = $avgAge > 0 ? max(0, 30 - $avgAge) : 0;
        $agePenalty = $avgAge > 32 ? ($avgAge - 32) * 1.8 : 0;
        $score = ($avgOvr * 1.6) + ($maxOvr * 0.6) + ($youthFactor * 0.8) - $agePenalty;
        if ($hasFranchise) $score += 2.0;
        if ($count < 5) $score -= (5 - $count) * 1.2;
        if ($championTeamId && (int)$t['id'] === $championTeamId) $score += 5.0;
        if ($runnerUpTeamId && (int)$t['id'] === $runnerUpTeamId) $score += 3.0;

        $aiTag = $t['team_tag'] ?? null;
        if (!$aiTag) {
            $aiTag = computeAiTagPHP($avgOvr ?: null, $maxOvr ?: null, $avgAge ?: null);
        }

        $powerRanking[] = [
            'team_id' => (int)$t['id'],
            'team_name' => trim(($t['city'] ?? '') . ' ' . ($t['name'] ?? '')),
            'team_photo' => $t['team_photo'] ?? null,
            'owner_name' => $t['owner_name'] ?? null,
            'score' => round($score, 1),
            'avg_ovr' => $avgOvr,
            'avg_age' => $avgAge,
            'max_ovr' => $maxOvr,
            'starters_count' => $count,
            'ai_tag' => $aiTag,
            'is_champion' => $championTeamId && (int)$t['id'] === $championTeamId,
            'is_runner_up' => $runnerUpTeamId && (int)$t['id'] === $runnerUpTeamId,
        ];
    }
    usort($powerRanking, static fn($a, $b) => $b['score'] <=> $a['score']);

    $powerSummary = null;

    // Conferência de cada time
    $confMap = [];
    try {
        $sc = $pdo->prepare("SELECT id, COALESCE(conference,'') AS conference FROM teams WHERE league = ?");
        $sc->execute([$league]);
        foreach ($sc->fetchAll(PDO::FETCH_ASSOC) as $cr) $confMap[(int)$cr['id']] = (string)$cr['conference'];
    } catch (Exception $e) {}
    foreach ($powerRanking as &$pr) $pr['conference'] = $confMap[$pr['team_id']] ?? '';
    unset($pr);

    // Picks futuros por time
    $picksPerTeam = [];
    try {
        $pickYear = $seasonYear ?? (int)date('Y');
        $sp2 = $pdo->prepare("SELECT p.team_id, COUNT(*) AS cnt FROM picks p JOIN teams t ON p.team_id = t.id WHERE t.league = ? AND CAST(p.season_year AS UNSIGNED) >= ? GROUP BY p.team_id");
        $sp2->execute([$league, $pickYear]);
        foreach ($sp2->fetchAll(PDO::FETCH_ASSOC) as $r) $picksPerTeam[(int)$r['team_id']] = (int)$r['cnt'];
    } catch (Exception $e) {}

    $analysis = buildLeagueAnalysis($powerRanking, $ranking, $picksPerTeam, $currentSeasonNum, $totalSeasons, $league);
    $leagueData[$league] = compact('teams','lastSeason','seasonYear','champion','runnerUp','awards','hof','playerCount','tradesCount','avgCap','ranking','currentSeasonNum','totalSeasons','powerRanking','powerSummary','analysis');
}

$defaultTab = in_array($user['league'] ?? '', $leagueOrder) ? $user['league'] : 'ELITE';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#fc0025">
    <title>Mundo FBA - FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">
    <style>
        :root {
            --red:        #fc0025;
            --red-soft:   rgba(252,0,37,.10);
            --red-glow:   rgba(252,0,37,.18);
            --bg:         #07070a;
            --panel:      #101013;
            --panel-2:    #16161a;
            --panel-3:    #1c1c21;
            --border:     rgba(255,255,255,.06);
            --border-md:  rgba(255,255,255,.10);
            --border-red: rgba(252,0,37,.22);
            --text:       #f0f0f3;
            --text-2:     #868690;
            --text-3:     #48484f;
            --amber:      #f59e0b;
            --green:      #22c55e;
            --blue:       #3b82f6;
            --sidebar-w:  260px;
            --font:       'Poppins', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
        }
        :root[data-theme="light"] {
            --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
            --border:#e3e6ee; --border-md:#d7dbe6; --text:#111217;
            --text-2:#5b6270; --text-3:#8b93a5;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

        /* ── Sidebar ────────────────────────────────────────── */
        .app { display: flex; min-height: 100vh; }
        .sidebar { position:fixed; top:0; left:0; width:260px; height:100vh; background:var(--panel); border-right:1px solid var(--border); display:flex; flex-direction:column; z-index:300; overflow-y:auto; scrollbar-width:none; transition:transform var(--t) var(--ease); }
        .sidebar::-webkit-scrollbar { display:none; }
        .sb-brand { padding:22px 18px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:12px; flex-shrink:0; }
        .sb-logo { width:34px; height:34px; border-radius:9px; background:var(--red); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; color:#fff; flex-shrink:0; }
        .sb-brand-text { font-weight:700; font-size:15px; line-height:1.1; }
        .sb-brand-text span { display:block; font-size:11px; font-weight:400; color:var(--text-2); }
        .sb-team { margin:14px 14px 0; background:var(--panel-2); border:1px solid var(--border); border-radius:var(--radius-sm); padding:14px; display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .sb-team img { width:40px; height:40px; border-radius:9px; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
        .sb-team-name { font-size:13px; font-weight:600; color:var(--text); line-height:1.2; }
        .sb-team-league { font-size:11px; color:var(--red); font-weight:600; }
        .sb-nav { flex:1; padding:12px 10px 8px; }
        .sb-section { font-size:10px; font-weight:600; letter-spacing:1.2px; text-transform:uppercase; color:var(--text-3); padding:12px 10px 5px; }
        .sb-nav a { display:flex; align-items:center; gap:10px; padding:9px 10px; border-radius:var(--radius-sm); color:var(--text-2); font-size:13px; font-weight:500; text-decoration:none; margin-bottom:2px; transition:all var(--t) var(--ease); }
        .sb-nav a i { font-size:15px; width:18px; text-align:center; flex-shrink:0; }
        .sb-nav a:hover { background:var(--panel-2); color:var(--text); }
        .sb-nav a.active { background:var(--red-soft); color:var(--red); font-weight:600; }
        .sb-nav a.active i { color:var(--red); }
        .sb-footer { padding:12px 14px; border-top:1px solid var(--border); display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .sb-avatar { width:30px; height:30px; border-radius:50%; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
        .sb-username { font-size:12px; font-weight:500; color:var(--text); flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sb-logout { width:26px; height:26px; border-radius:7px; background:transparent; border:1px solid var(--border); color:var(--text-2); display:flex; align-items:center; justify-content:center; font-size:12px; cursor:pointer; transition:all var(--t) var(--ease); text-decoration:none; flex-shrink:0; }
        .sb-logout:hover { background:var(--red-soft); border-color:var(--red); color:var(--red); }
        .sb-theme-toggle { margin:0 14px 12px; padding:8px 10px; border-radius:10px; border:1px solid var(--border); background:var(--panel-2); color:var(--text); display:flex; align-items:center; justify-content:center; gap:8px; font-size:12px; font-weight:600; cursor:pointer; transition:all var(--t) var(--ease); }
        .sb-theme-toggle:hover { border-color:var(--border-red); color:var(--red); }

        /* ── Topbar mobile ──────────────────────────────────── */
        .topbar { display:none; position:fixed; top:0; left:0; right:0; height:54px; background:var(--panel); border-bottom:1px solid var(--border); align-items:center; padding:0 16px; gap:12px; z-index:260; }
        .topbar-title { font-weight:700; font-size:15px; flex:1; }
        .topbar-title em { color:var(--red); font-style:normal; }
        .menu-btn { width:34px; height:34px; border-radius:9px; background:var(--panel-2); border:1px solid var(--border); color:var(--text); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:17px; }
        .sb-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.65); backdrop-filter:blur(4px); z-index:250; }
        .sb-overlay.show { display:block; }

        /* ── Main layout ────────────────────────────────────── */
        .main { margin-left:var(--sidebar-w); min-height:100vh; width:calc(100% - var(--sidebar-w)); display:flex; flex-direction:column; }
        .page-hero { padding:28px 32px 20px; border-bottom:1px solid var(--border); }
        .page-eyebrow { font-size:11px; font-weight:600; letter-spacing:1.4px; text-transform:uppercase; color:var(--red); margin-bottom:4px; }
        .page-title { font-size:22px; font-weight:800; display:flex; align-items:center; gap:10px; }
        .page-title i { color:var(--red); }
        .page-sub { font-size:13px; color:var(--text-2); margin-top:4px; }
        .content { padding:24px 32px 56px; flex:1; }

        /* ── League tabs ────────────────────────────────────── */
        .league-tabs { display:flex; gap:0; border-bottom:1px solid var(--border); margin-bottom:28px; justify-content:center; }
        .league-tab {
            display:flex; align-items:center; gap:8px;
            padding:12px 24px; font-size:13px; font-weight:600; color:var(--text-2);
            cursor:pointer; border:none; background:none; position:relative;
            transition:color var(--t) var(--ease); white-space:nowrap;
        }
        .league-tab i { font-size:14px; }
        .tab-season {
            font-size:10px; font-weight:700; letter-spacing:.4px;
            background:var(--panel-3); border:1px solid var(--border);
            border-radius:20px; padding:2px 7px; color:var(--text-3);
            transition:all var(--t) var(--ease);
        }
        .league-tab.active .tab-season { border-color:currentColor; color:inherit; opacity:.75; }
        .league-tab::after {
            content:''; position:absolute; bottom:-1px; left:0; right:0; height:2px;
            background:transparent; transition:background var(--t) var(--ease);
        }
        .league-tab.active { color:var(--text); }
        .league-tab[data-league="ELITE"].active { color:#f59e0b; }
        .league-tab[data-league="ELITE"].active::after { background:#f59e0b; }
        .league-tab[data-league="NEXT"].active  { color:#3b82f6; }
        .league-tab[data-league="NEXT"].active::after  { background:#3b82f6; }
        .league-tab[data-league="RISE"].active  { color:#22c55e; }
        .league-tab[data-league="RISE"].active::after  { background:#22c55e; }
        .league-pane { display:none; }
        .league-pane.active { display:block; }

        /* ── Section header ─────────────────────────────────── */
        .sec-hd {
            display:flex; align-items:center; gap:8px;
            font-size:10px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase;
            color:var(--text-3); margin-bottom:14px; margin-top:28px;
        }
        .sec-hd:first-child { margin-top:0; }
        .sec-hd i { font-size:13px; }

        /* ── Champion banner ────────────────────────────────── */
        .champ-grid { display:grid; grid-template-columns:repeat(2, minmax(0,380px)); gap:12px; margin-bottom:0; justify-content:center; }
        .champ-card {
            background:var(--panel); border:1px solid var(--border);
            border-radius:var(--radius); padding:20px;
            display:flex; align-items:center; gap:16px; position:relative; overflow:hidden;
        }
        .champ-card.champion { border-color:rgba(245,158,11,.35); }
        .champ-card.champion::before {
            content:''; position:absolute; top:-60px; right:-60px;
            width:180px; height:180px; border-radius:50%;
            background:radial-gradient(circle, rgba(245,158,11,.12) 0%, transparent 70%);
            pointer-events:none;
        }
        .champ-logo { width:56px; height:56px; border-radius:12px; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
        .champ-badge {
            width:56px; height:56px; border-radius:12px; flex-shrink:0;
            background:rgba(245,158,11,.12); border:1px solid rgba(245,158,11,.25);
            display:flex; align-items:center; justify-content:center;
            font-size:22px;
        }
        .champ-badge.silver { background:rgba(148,163,184,.1); border-color:rgba(148,163,184,.2); }
        .champ-info { min-width:0; }
        .champ-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:var(--text-3); margin-bottom:3px; }
        .champ-label.gold { color:#f59e0b; }
        .champ-label.silver { color:#94a3b8; }
        .champ-name { font-size:15px; font-weight:800; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .champ-gm { font-size:12px; color:var(--text-2); margin-top:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .champ-year { font-size:11px; font-weight:700; color:var(--text-3); margin-top:4px; }

        /* ── Awards grid ────────────────────────────────────── */
        .awards-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:10px; }
        .award-card {
            background:var(--panel); border:1px solid var(--border);
            border-radius:var(--radius-sm); padding:12px 14px;
        }
        .award-type { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--text-3); margin-bottom:4px; }
        .award-player { font-size:13px; font-weight:700; color:var(--text); }

        /* ── Teams list ─────────────────────────────────────── */
        .teams-list { display:flex; flex-direction:column; gap:6px; }
        .team-row {
            background:var(--panel); border:1px solid var(--border);
            border-radius:var(--radius); overflow:hidden;
            transition:border-color var(--t) var(--ease);
        }
        .team-row:hover { border-color:var(--border-md); }
        .team-row.open { border-color:var(--border-md); }
        .team-head {
            display:flex; align-items:center; gap:14px;
            padding:14px 18px; cursor:pointer; user-select:none;
        }
        .team-rank {
            width:28px; text-align:center; font-size:13px; font-weight:800;
            color:var(--text-3); flex-shrink:0;
        }
        .team-rank.gold   { color:#f59e0b; }
        .team-rank.silver { color:#94a3b8; }
        .team-rank.bronze { color:#c97b3b; }
        .team-logo {
            width:44px; height:44px; border-radius:10px; object-fit:cover;
            border:1px solid var(--border-md); flex-shrink:0;
            background:var(--panel-2);
        }
        .team-info { flex:1; min-width:0; }
        .team-name { font-size:14px; font-weight:700; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .team-gm   { font-size:11px; color:var(--text-2); margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .team-cap {
            display:flex; flex-direction:column; align-items:flex-end; flex-shrink:0; gap:2px;
        }
        .cap-val { font-size:16px; font-weight:800; color:var(--text); line-height:1; }
        .cap-lbl { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:var(--text-3); }
        .team-toggle {
            width:28px; height:28px; border-radius:8px; flex-shrink:0;
            background:var(--panel-2); border:1px solid var(--border);
            display:flex; align-items:center; justify-content:center;
            font-size:12px; color:var(--text-2);
            transition:all var(--t) var(--ease);
        }
        .team-row.open .team-toggle { background:var(--red-soft); border-color:var(--border-red); color:var(--red); transform:rotate(180deg); }

        /* ── Starters table ─────────────────────────────────── */
        .starters-wrap {
            display:none; border-top:1px solid var(--border);
            padding:0 18px 14px;
        }
        .team-row.open .starters-wrap { display:block; }
        .starters-table { width:100%; border-collapse:collapse; margin-top:12px; }
        .starters-table th {
            font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px;
            color:var(--text-3); padding:6px 8px; text-align:left;
            border-bottom:1px solid var(--border);
        }
        .starters-table td { padding:9px 8px; font-size:13px; border-bottom:1px solid var(--border); }
        .starters-table tr:last-child td { border-bottom:none; }
        .pos-badge {
            display:inline-flex; align-items:center; justify-content:center;
            width:28px; height:22px; border-radius:6px;
            background:var(--panel-2); border:1px solid var(--border);
            font-size:10px; font-weight:800; color:var(--text-2); letter-spacing:.4px;
        }
        .ovr-badge {
            display:inline-block; padding:2px 8px; border-radius:6px;
            font-size:12px; font-weight:700;
        }
        .ovr-s { background:rgba(245,158,11,.12); color:#f59e0b; }
        .ovr-a { background:rgba(252,0,37,.10);  color:#fc6680; }
        .ovr-b { background:rgba(167,139,250,.12); color:#a78bfa; }
        .ovr-c { background:var(--panel-2); color:var(--text-2); }
        .empty-row td { color:var(--text-3); font-style:italic; font-size:12px; }

        /* ── Hall da Fama ───────────────────────────────────── */
        .hof-list { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:10px; }
        .hof-card {
            background:var(--panel); border:1px solid var(--border);
            border-radius:var(--radius-sm); padding:14px;
            display:flex; align-items:center; gap:12px;
        }
        .hof-logo { width:40px; height:40px; border-radius:9px; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; background:var(--panel-2); }
        .hof-info { min-width:0; flex:1; }
        .hof-team { font-size:13px; font-weight:700; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .hof-gm   { font-size:11px; color:var(--text-2); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .hof-titles {
            display:flex; flex-direction:column; align-items:center; flex-shrink:0;
            background:rgba(245,158,11,.10); border:1px solid rgba(245,158,11,.2);
            border-radius:8px; padding:5px 10px; gap:1px;
        }
        .hof-num { font-size:18px; font-weight:900; color:#f59e0b; line-height:1; }
        .hof-lbl { font-size:9px; color:var(--amber); font-weight:600; text-transform:uppercase; letter-spacing:.5px; }

        /* ── Stats strip ───────────────────────────────────── */
        .stats-strip { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:24px; }
        .stat-pill {
            background:var(--panel); border:1px solid var(--border);
            border-radius:var(--radius-sm); padding:14px 16px;
            display:flex; flex-direction:column; align-items:center; gap:2px;
        }
        .stat-val { font-size:20px; font-weight:800; color:var(--text); line-height:1; }
        .stat-lbl { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.8px; color:var(--text-3); }

        /* ── Award chips ────────────────────────────────────── */
        .award-chips { display:flex; flex-wrap:wrap; gap:8px; justify-content:center; }
        .award-chip {
            display:flex; align-items:center; gap:8px;
            background:var(--panel); border:1px solid var(--border);
            border-radius:30px; padding:8px 14px;
            min-width:0;
        }
        .chip-icon { font-size:16px; line-height:1; flex-shrink:0; }
        .chip-body { min-width:0; }
        .chip-lbl  { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--text-3); margin-bottom:1px; }
        .chip-name { font-size:12px; font-weight:700; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:140px; }
        .chip-amber { border-color:rgba(245,158,11,.30); background:rgba(245,158,11,.06); }
        .chip-amber .chip-lbl { color:#f59e0b; }
        .chip-blue  { border-color:rgba(59,130,246,.30); background:rgba(59,130,246,.06); }
        .chip-blue  .chip-lbl { color:#3b82f6; }
        .chip-green { border-color:rgba(34,197,94,.30); background:rgba(34,197,94,.06); }
        .chip-green .chip-lbl { color:#22c55e; }
        .chip-purple{ border-color:rgba(167,139,250,.30); background:rgba(167,139,250,.06); }
        .chip-purple .chip-lbl { color:#a78bfa; }
        .chip-red   { border-color:rgba(252,0,37,.25); background:rgba(252,0,37,.06); }
        .chip-red   .chip-lbl { color:#fc6680; }
        .chip-gray  { border-color:var(--border-md); }

        /* ── Ranking table ─────────────────────────────────── */
        .ranking-table { width:100%; border-collapse:collapse; }
        .ranking-table th {
            font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px;
            color:var(--text-3); padding:8px 12px; text-align:left;
            border-bottom:1px solid var(--border);
        }
        .ranking-table th.right, .ranking-table td.right { text-align:right; }
        .ranking-table td { padding:11px 12px; font-size:13px; border-bottom:1px solid var(--border); vertical-align:middle; }
        .ranking-table tr:last-child td { border-bottom:none; }
        .ranking-table tr:hover td { background:var(--panel-2); }
        .rk-pos { width:32px; font-size:13px; font-weight:800; color:var(--text-3); }
        .rk-pos.gold   { color:#f59e0b; }
        .rk-pos.silver { color:#94a3b8; }
        .rk-pos.bronze { color:#c97b3b; }
        .rk-logo { width:30px; height:30px; border-radius:7px; object-fit:cover; border:1px solid var(--border-md); background:var(--panel-2); flex-shrink:0; }
        .rk-team { display:flex; align-items:center; gap:10px; }
        .rk-name { font-size:13px; font-weight:700; color:var(--text); }
        .rk-gm   { font-size:11px; color:var(--text-2); }
        .rk-pts  { font-size:16px; font-weight:800; color:var(--text); }
        .rk-titles { display:inline-flex; align-items:center; gap:4px; font-size:12px; font-weight:700; color:#f59e0b; }
        .ranking-wrap { background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }

        /* ── Power ranking ────────────────────────────────── */
        .power-wrap { background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); padding:16px; }
        .power-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); gap:10px; }
        .power-card {
            background:var(--panel-2); border:1px solid var(--border);
            border-radius:var(--radius-sm); padding:12px 14px; position:relative;
        }
        .power-rank {
            position:absolute; top:10px; right:10px; font-size:11px; font-weight:800;
            color:var(--text); background:var(--panel-3); border:1px solid var(--border);
            padding:3px 6px; border-radius:8px;
        }
        .power-rank.gold { color:var(--text); border-color:rgba(245,158,11,.25); }
        .power-rank.silver { color:var(--text); }
        .power-rank.bronze { color:var(--text); }
        .power-head { display:flex; align-items:center; gap:10px; }
        .power-logo { width:38px; height:38px; border-radius:9px; object-fit:cover; border:1px solid var(--border-md); background:var(--panel-3); flex-shrink:0; }
        .power-name { font-size:13px; font-weight:700; color:var(--text); }
        .power-owner { font-size:11px; color:var(--text-2); }
        .power-score {
            margin-top:8px; font-size:20px; font-weight:900; color:var(--red);
            letter-spacing:.3px; text-align:center;
        }
        .power-score span { font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.8px; color:var(--text); display:block; margin-top:2px; }
        .power-badges { display:flex; gap:6px; margin-top:8px; flex-wrap:wrap; justify-content:center; }
        .power-badge { font-size:10px; font-weight:700; border-radius:999px; padding:2px 8px; border:1px solid var(--border); color:var(--text-2); background:var(--panel-3); }
        .power-badge.champ { color:#f59e0b; border-color:rgba(245,158,11,.35); background:rgba(245,158,11,.08); }
        .power-badge.runner { color:#94a3b8; border-color:rgba(148,163,184,.35); background:rgba(148,163,184,.08); }
        .power-metrics { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-top:10px; }
        .power-metric { background:var(--panel-3); border:1px solid var(--border); border-radius:8px; padding:6px 8px; text-align:center; }
        .power-metric .val { font-size:12px; font-weight:800; color:var(--red); }
        .power-metric .lbl { font-size:9px; letter-spacing:.6px; text-transform:uppercase; color:var(--text); }

        /* ── Empty state ────────────────────────────────────── */
        .empty-state {
            text-align:center; padding:40px 20px; color:var(--text-3);
            background:var(--panel); border:1px solid var(--border); border-radius:var(--radius);
        }
        .empty-state i { font-size:28px; display:block; margin-bottom:10px; }
        .empty-state p { font-size:13px; margin:0; }

        /* ── SuperComputador FBA ────────────────────────────── */
        .sc-wrap {
            background: linear-gradient(135deg, rgba(99,102,241,.07) 0%, rgba(139,92,246,.05) 100%);
            border: 1px solid rgba(99,102,241,.22);
            border-radius: var(--radius); padding: 20px;
        }
        .sc-header { display:flex; align-items:center; gap:12px; margin-bottom:18px; flex-wrap:wrap; }
        .sc-icon { width:40px; height:40px; border-radius:10px; flex-shrink:0; background:rgba(99,102,241,.15); border:1px solid rgba(99,102,241,.3); display:flex; align-items:center; justify-content:center; font-size:20px; }
        .sc-ttl { font-size:14px; font-weight:800; color:var(--text); }
        .sc-sub { font-size:11px; color:var(--text-2); margin-top:1px; }
        .sc-badges { margin-left:auto; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
        .sc-ia-tag { font-size:10px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; padding:3px 10px; border-radius:999px; background:rgba(99,102,241,.18); border:1px solid rgba(99,102,241,.35); color:#818cf8; }
        .sc-season-tag { font-size:10px; font-weight:700; padding:3px 10px; border-radius:999px; background:var(--panel-3); border:1px solid var(--border-md); color:var(--text-2); }
        .sc-sprint-bar { display:flex; align-items:center; gap:8px; margin-bottom:18px; background:rgba(0,0,0,.15); border:1px solid rgba(99,102,241,.12); border-radius:8px; padding:10px 14px; }
        :root[data-theme="light"] .sc-sprint-bar { background:rgba(99,102,241,.04); }
        .sc-sprint-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:#818cf8; white-space:nowrap; }
        .sc-sprint-track { flex:1; height:6px; background:rgba(99,102,241,.15); border-radius:3px; overflow:hidden; }
        .sc-sprint-fill  { height:100%; background:linear-gradient(90deg,#6366f1,#a78bfa); border-radius:3px; }
        .sc-sprint-info  { font-size:11px; font-weight:700; color:var(--text-2); white-space:nowrap; }
        .sc-sprint-info strong { color:var(--text); }
        .sc-grid { display:grid; grid-template-columns:1.15fr 1fr 1fr; gap:12px; }
        @media(max-width:900px){ .sc-grid{ grid-template-columns:1fr 1fr; } }
        @media(max-width:580px){ .sc-grid{ grid-template-columns:1fr; } }
        .sc-block { background:rgba(0,0,0,.18); border:1px solid rgba(99,102,241,.13); border-radius:var(--radius-sm); padding:14px; }
        :root[data-theme="light"] .sc-block { background:rgba(99,102,241,.04); }
        .sc-block-title { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:#818cf8; margin-bottom:12px; display:flex; align-items:center; gap:5px; }
        .sc-row { display:flex; align-items:center; gap:8px; margin-bottom:10px; }
        .sc-row:last-child { margin-bottom:0; }
        .sc-num { width:18px; font-size:11px; font-weight:800; color:#818cf8; flex-shrink:0; text-align:center; }
        .sc-tlogo { width:28px; height:28px; border-radius:6px; object-fit:cover; flex-shrink:0; border:1px solid rgba(99,102,241,.18); background:var(--panel-3); }
        .sc-tinfo { flex:1; min-width:0; }
        .sc-tname { font-size:12px; font-weight:700; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sc-tmeta { font-size:10px; color:var(--text-2); }
        .sc-prob-col { display:flex; flex-direction:column; align-items:flex-end; gap:3px; flex-shrink:0; }
        .sc-pct { font-size:14px; font-weight:800; color:#818cf8; line-height:1; }
        .sc-bar { width:52px; height:4px; background:rgba(99,102,241,.15); border-radius:2px; overflow:hidden; }
        .sc-bar-fill { height:100%; background:linear-gradient(90deg,#6366f1,#a78bfa); border-radius:2px; }
        .sc-fin-row { display:flex; align-items:center; gap:8px; padding:7px 0; border-bottom:1px solid rgba(99,102,241,.08); }
        .sc-fin-row:last-child { border-bottom:none; }
        .sc-fin-lbl { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.5px; white-space:nowrap; }
        .sc-fin-lbl.c1 { color:#f59e0b; }
        .sc-fin-lbl.c2 { color:#3b82f6; }
        .sc-zone-row { display:flex; align-items:center; gap:8px; padding:6px 0; border-bottom:1px solid rgba(99,102,241,.08); }
        .sc-zone-row:last-child { border-bottom:none; }
        .sc-zone-pct { font-size:13px; font-weight:800; margin-left:auto; flex-shrink:0; }
        /* Season projection grid */
        .sc-season-row { display:flex; gap:8px; overflow-x:auto; -webkit-overflow-scrolling:touch; padding-bottom:4px; margin-top:14px; }
        .sc-season-row::-webkit-scrollbar{ height:3px; }
        .sc-season-row::-webkit-scrollbar-thumb{ background:rgba(99,102,241,.3); border-radius:2px; }
        .sc-season-card { flex:1; min-width:160px; max-width:210px; background:rgba(0,0,0,.18); border:1px solid rgba(99,102,241,.13); border-radius:var(--radius-sm); padding:11px 12px; flex-shrink:0; }
        :root[data-theme="light"] .sc-season-card { background:rgba(99,102,241,.04); }
        .sc-season-label { font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.8px; color:#818cf8; margin-bottom:8px; }
        .sc-sf-row { display:flex; align-items:center; gap:7px; padding:4px 0; border-bottom:1px solid rgba(99,102,241,.07); }
        .sc-sf-row:last-child { border-bottom:none; }
        .sc-sf-icon { font-size:13px; flex-shrink:0; width:18px; text-align:center; }
        @media(max-width:580px){
            .sc-season-card{ min-width:145px; }
        }
        /* Acordeão */
        .sc-accordion { border:1px solid rgba(99,102,241,.18); border-radius:var(--radius-sm); overflow:hidden; margin-top:14px; }
        .sc-acc-head { display:flex; align-items:center; gap:10px; padding:12px 16px; cursor:pointer; background:rgba(99,102,241,.07); border-bottom:1px solid rgba(99,102,241,.12); user-select:none; transition:background .15s; }
        .sc-acc-head:hover { background:rgba(99,102,241,.12); }
        .sc-acc-head:last-of-type { border-bottom:none; }
        .sc-acc-title { font-size:12px; font-weight:700; color:var(--text); flex:1; }
        .sc-acc-chevron { font-size:12px; color:#818cf8; transition:transform .2s; flex-shrink:0; }
        .sc-acc-chevron.open { transform:rotate(180deg); }
        .sc-acc-body { display:none; padding:16px; border-bottom:1px solid rgba(99,102,241,.1); }
        .sc-acc-body.open { display:block; }
        /* Projeção por temporada */
        .sc-season-block { margin-bottom:16px; }
        .sc-season-block:last-child { margin-bottom:0; }
        .sc-season-title { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.8px; color:#818cf8; margin-bottom:8px; }
        .sc-season-line { display:flex; align-items:center; gap:9px; padding:6px 0; border-bottom:1px solid rgba(99,102,241,.07); }
        .sc-season-line:last-child { border-bottom:none; }
        .sc-medal { font-size:14px; flex-shrink:0; width:20px; text-align:center; }
        /* Tabela de probabilidades */
        .sc-matrix-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; margin-top:4px; }
        .sc-matrix { width:100%; border-collapse:collapse; font-size:12px; }
        .sc-matrix th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#818cf8; padding:8px 6px; text-align:center; border-bottom:1px solid rgba(99,102,241,.2); white-space:nowrap; background:rgba(99,102,241,.06); }
        .sc-matrix th.tc { text-align:left; padding-left:10px; min-width:170px; position:sticky; left:0; z-index:2; background:var(--panel-2); }
        .sc-matrix th.pc { min-width:46px; }
        .sc-matrix td { padding:7px 5px; text-align:center; border-bottom:1px solid rgba(99,102,241,.07); vertical-align:middle; }
        .sc-matrix td.tc { text-align:left; padding-left:10px; position:sticky; left:0; z-index:1; background:var(--panel); }
        .sc-matrix tbody tr:hover td { background:rgba(99,102,241,.04); }
        .sc-matrix tbody tr:hover td.tc { background:var(--panel-2); }
        .sc-matrix tbody tr:last-child td { border-bottom:none; }
        .sc-pcell { border-radius:5px; padding:2px 5px; font-size:11px; font-weight:700; display:inline-block; min-width:36px; }
        .sc-pcell.hi   { background:rgba(34,197,94,.18);  color:#22c55e; }
        .sc-pcell.md   { background:rgba(245,158,11,.15); color:#f59e0b; }
        .sc-pcell.lo   { background:rgba(99,102,241,.12); color:#818cf8; }
        .sc-pcell.vlo  { color:var(--text-3); }
        .sc-pcell.peak { outline:2px solid rgba(99,102,241,.6); background:rgba(99,102,241,.22) !important; color:#a5b4fc !important; font-weight:900; }
        .sc-matrix th.promo { color:#22c55e; }
        .sc-matrix th.rele  { color:#f87171; }
        .sc-proj-cell { font-size:12px; font-weight:800; color:#f59e0b; white-space:nowrap; }
        .sc-proj-delta { font-size:9px; font-weight:700; color:#818cf8; margin-left:2px; }

        /* ── Responsive ─────────────────────────────────────── */
        @media (max-width: 992px) {
            :root { --sidebar-w: 0px; }
            .sidebar { transform:translateX(-260px); }
            .sidebar.open { transform:translateX(0); }
            .topbar { display:flex; }
            .main { margin-left:0; width:100%; padding-top:54px; }
            .page-hero { padding:20px 16px 16px; }
            .content { padding:16px 16px 48px; }
        }
        @media (max-width: 640px) {
            .champ-grid { grid-template-columns:1fr; }
            .stats-strip { grid-template-columns:1fr 1fr; }
            .league-tab { padding:10px 14px; font-size:12px; }
            .league-tab span { display:none; }
            .league-tab .tab-season { display:none; }
            .team-head { padding:12px 14px; gap:10px; }
            .cap-val { font-size:14px; }
            .starters-wrap { padding:0 14px 12px; }
            .starters-table td, .starters-table th { padding:7px 6px; font-size:12px; }
        }
    </style>
</head>
<body>
<div class="app">

<aside class="sidebar" id="sidebar">
    <div class="sb-brand">
        <div class="sb-logo">FBA</div>
        <div class="sb-brand-text">FBA Manager<span>Liga <?= htmlspecialchars($user['league']) ?></span></div>
    </div>

    <?php if ($myTeam): ?>
    <div class="sb-team">
        <img src="<?= htmlspecialchars(getTeamPhoto($myTeam['photo_url'] ?? null)) ?>"
             alt="<?= htmlspecialchars($myTeam['name']) ?>" onerror="this.src='/img/default-team.png'">
        <div>
            <div class="sb-team-name"><?= htmlspecialchars($myTeam['city'] . ' ' . $myTeam['name']) ?></div>
            <div class="sb-team-league"><?= htmlspecialchars($user['league']) ?></div>
        </div>
    </div>
    <?php endif; ?>

    <nav class="sb-nav">
        <div class="sb-section">Principal</div>
        <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
        <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
        <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
        <a href="/players.php"><i class="bi bi-person-lines-fill"></i> Jogadores</a>
        <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
        <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
            <a href="/mercado.php"><i class="bi bi-shop"></i> Mercado</a>
        <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
        <a href="/leilao.php"><i class="bi bi-hammer"></i> Leilão</a>
        <a href="/drafts.php"><i class="bi bi-trophy"></i> Draft</a>
            <a href="/tapas.php"><i class="bi bi-hand-index-thumb"></i> Tapas</a>

        <div class="sb-section">Liga</div>
        <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
        <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
        <a href="/hall-da-fama.php"><i class="bi bi-award-fill"></i> Hall da Fama</a>
        <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
        <a href="/mundo-fba.php" class="active"><i class="bi bi-globe2"></i> Mundo FBA</a>
        <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
        <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>
            <a href="/thepathetic.php"><i class="bi bi-newspaper"></i> The Pathetic</a>

        <?php if (hasAdminAccess($pdo, (int)$user['id'])): ?>
        <div class="sb-section">Admin</div>
        <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>

        <?php endif; ?>

        <div class="sb-section">Conta</div>
        <a href="/settings.php"><i class="bi bi-gear-fill"></i> Minha Conta</a>
    </nav>

    <button class="sb-theme-toggle" id="themeToggle" type="button">
        <i class="bi bi-moon"></i><span>Modo escuro</span>
    </button>

    <div class="sb-footer">
        <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url'] ?? null)) ?>"
             alt="<?= htmlspecialchars($user['name']) ?>" class="sb-avatar"
             onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name']) ?>&background=1c1c21&color=fc0025'">
        <span class="sb-username"><?= htmlspecialchars($user['name']) ?></span>
        <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</aside>

<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
    <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
    <div class="topbar-title">FBA <em>Manager</em></div>
    <span style="font-size:11px;font-weight:700;color:var(--red)"><?= $seasonDisplayYear ?></span>
</header>

<main class="main">
    <div class="page-hero">
        <div class="page-eyebrow">Visão Geral</div>
        <h1 class="page-title"><i class="bi bi-globe2"></i> Mundo FBA</h1>
        <p class="page-sub">Acompanhe o que está acontecendo em todas as ligas — times, elencos e história.</p>
    </div>

    <div class="content">

        <!-- Abas das ligas -->
        <div class="league-tabs">
            <?php foreach ($leagueOrder as $league): $meta = $leagueMeta[$league]; $ld = $leagueData[$league]; ?>
            <button class="league-tab <?= $league === $defaultTab ? 'active' : '' ?>"
                    data-league="<?= $league ?>" onclick="switchTab('<?= $league ?>')">
                <i class="bi <?= $meta['icon'] ?>"></i>
                <span><?= $meta['label'] ?></span>
                <?php if ($ld['currentSeasonNum'] !== null): ?>
                <span class="tab-season">T<?= $ld['currentSeasonNum'] ?><?= $ld['totalSeasons'] ? '/' . $ld['totalSeasons'] : '' ?></span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($leagueOrder as $league):
            $d    = $leagueData[$league];
            $meta = $leagueMeta[$league];
            $col  = $meta['color'];
        ?>
        <div class="league-pane <?= $league === $defaultTab ? 'active' : '' ?>" id="pane-<?= $league ?>">

            <?php /* ── Stats strip ── */ ?>
            <div class="stats-strip">
                <div class="stat-pill">
                    <span class="stat-val"><?= count($d['teams']) ?></span>
                    <span class="stat-lbl">Times</span>
                </div>
                <div class="stat-pill">
                    <span class="stat-val"><?= $d['playerCount'] ?></span>
                    <span class="stat-lbl">Jogadores</span>
                </div>
                <div class="stat-pill">
                    <span class="stat-val"><?= $d['tradesCount'] ?></span>
                    <span class="stat-lbl">Trocas</span>
                </div>
                <div class="stat-pill">
                    <span class="stat-val"><?= $d['avgCap'] ?></span>
                    <span class="stat-lbl">CAP Médio</span>
                </div>
            </div>

            <?php /* ── Campeão da última temporada ── */ ?>
            <?php if ($d['champion'] || $d['runnerUp']): ?>
            <div class="sec-hd" style="color:<?= $col ?>">
                <i class="bi bi-trophy-fill" style="color:<?= $col ?>"></i>
                ÚLTIMA TEMPORADA<?= $d['seasonYear'] ? ' · ' . $d['seasonYear'] : '' ?>
            </div>
            <div class="champ-grid" style="margin-bottom:0">
                <?php if ($d['champion']): $c = $d['champion']; ?>
                <div class="champ-card champion">
                    <?php if ($c['team_photo']): ?>
                    <img class="champ-logo" src="<?= htmlspecialchars(getTeamPhoto($c['team_photo'])) ?>"
                         alt="" onerror="this.src='/img/default-team.png'">
                    <?php else: ?>
                    <div class="champ-badge">🏆</div>
                    <?php endif; ?>
                    <div class="champ-info">
                        <div class="champ-label gold"><i class="bi bi-trophy-fill me-1"></i>Campeão</div>
                        <div class="champ-name"><?= htmlspecialchars($c['city'] . ' ' . $c['team_name']) ?></div>
                        <div class="champ-gm"><i class="bi bi-person-fill me-1"></i><?= htmlspecialchars($c['owner_name']) ?></div>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($d['runnerUp']): $r = $d['runnerUp']; ?>
                <div class="champ-card">
                    <?php if ($r['team_photo']): ?>
                    <img class="champ-logo" src="<?= htmlspecialchars(getTeamPhoto($r['team_photo'])) ?>"
                         alt="" onerror="this.src='/img/default-team.png'">
                    <?php else: ?>
                    <div class="champ-badge silver">🥈</div>
                    <?php endif; ?>
                    <div class="champ-info">
                        <div class="champ-label silver">Vice-Campeão</div>
                        <div class="champ-name"><?= htmlspecialchars($r['city'] . ' ' . $r['team_name']) ?></div>
                        <div class="champ-gm"><i class="bi bi-person-fill me-1"></i><?= htmlspecialchars($r['owner_name']) ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php /* ── Prêmios individuais ── */ ?>
            <?php
            $chipDefs = [
                'mvp'           => ['icon'=>'⭐','label'=>'MVP',        'cls'=>'chip-amber'],
                'dpoy'          => ['icon'=>'🛡️','label'=>'DPOY',       'cls'=>'chip-blue'],
                'mip'           => ['icon'=>'📈','label'=>'MIP',        'cls'=>'chip-green'],
                'smoy'          => ['icon'=>'👤','label'=>'6º Homem',   'cls'=>'chip-purple'],
                'roy'           => ['icon'=>'🌟','label'=>'Calouro',    'cls'=>'chip-red'],
                'fmvp'          => ['icon'=>'🏆','label'=>'MVP Finals', 'cls'=>'chip-amber'],
                'coach_of_year' => ['icon'=>'👔','label'=>'Técnico',    'cls'=>'chip-gray'],
            ];
            $awardsByType = [];
            foreach ($d['awards'] as $aw) $awardsByType[$aw['award_type']] = $aw['player_name'];
            ?>
            <?php if (!empty($d['awards'])): ?>
            <div class="sec-hd" style="color:<?= $col ?>;margin-top:24px">
                <i class="bi bi-award-fill" style="color:<?= $col ?>"></i>
                PRÊMIOS INDIVIDUAIS<?= $d['seasonYear'] ? ' · ' . $d['seasonYear'] : '' ?>
            </div>
            <div class="award-chips">
                <?php foreach ($chipDefs as $type => $chip):
                    if (!isset($awardsByType[$type])) continue;
                ?>
                <div class="award-chip <?= $chip['cls'] ?>">
                    <span class="chip-icon"><?= $chip['icon'] ?></span>
                    <div class="chip-body">
                        <div class="chip-lbl"><?= $chip['label'] ?></div>
                        <div class="chip-name"><?= htmlspecialchars($awardsByType[$type] ?? '—') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php
                // Show any other awards not in chipDefs
                foreach ($d['awards'] as $aw):
                    if (isset($chipDefs[$aw['award_type']])) continue;
                    $label = $awardLabels[$aw['award_type']] ?? ucwords(str_replace('_',' ',$aw['award_type']));
                ?>
                <div class="award-chip chip-gray">
                    <span class="chip-icon">🎖️</span>
                    <div class="chip-body">
                        <div class="chip-lbl"><?= htmlspecialchars($label) ?></div>
                        <div class="chip-name"><?= htmlspecialchars($aw['player_name'] ?? '—') ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php /* ── SuperComputador FBA ── */ ?>
            <?php $an = $d['analysis'] ?? []; if (!empty($an['has_data'])): ?>
            <div class="sec-hd" style="color:#818cf8;margin-top:24px">
                <i class="bi bi-cpu-fill" style="color:#818cf8"></i>
                SUPERCOMPUTADOR FBA · Análise Preditiva
            </div>
            <div class="sc-wrap">
                <!-- Header -->
                <div class="sc-header">
                    <div class="sc-icon">🤖</div>
                    <div>
                        <div class="sc-ttl">SuperComputador FBA</div>
                        <div class="sc-sub">Análise preditiva · Power Ranking · Elenco · Histórico</div>
                    </div>
                    <div class="sc-badges">
                        <?php if ($an['current_season'] !== null && $an['total_seasons'] !== null): ?>
                        <span class="sc-season-tag">T<?= $an['current_season'] ?>/<?= $an['total_seasons'] ?></span>
                        <?php endif; ?>
                        <span class="sc-ia-tag"><i class="bi bi-stars me-1"></i>IA</span>
                    </div>
                </div>

                <!-- Sprint timeline -->
                <?php if ($an['current_season'] !== null && $an['total_seasons'] !== null): ?>
                <?php
                    $sl  = $an['seasons_left'] ?? 0;
                    $cur = $an['current_season'];
                    $tot = $an['total_seasons'];
                    $pct = $tot > 0 ? round($cur / $tot * 100) : 0;
                    $slMsg = $sl === 0 ? '⚠️ Última temporada do sprint!' : ($sl === 1 ? '1 temporada restante' : "{$sl} temporadas restantes");
                ?>
                <div class="sc-sprint-bar">
                    <span class="sc-sprint-label"><i class="bi bi-hourglass-split me-1"></i>Sprint</span>
                    <div class="sc-sprint-track"><div class="sc-sprint-fill" style="width:<?= $pct ?>%"></div></div>
                    <span class="sc-sprint-info"><strong>T<?= $cur ?></strong>/<?= $tot ?> · <?= $slMsg ?></span>
                </div>
                <?php endif; ?>

                <!-- Cards centralizados: Campeão + Finalistas -->
                <div style="display:grid;grid-template-columns:repeat(2,minmax(0,380px));gap:12px;justify-content:center;margin-bottom:12px">

                    <!-- Projeção Campeão -->
                    <?php if (!empty($an['champ_proj'])):
                        $maxProb = $an['champ_proj'][0]['prob'] ?: 1; ?>
                    <div class="sc-block" style="text-align:center">
                        <div class="sc-block-title" style="justify-content:center"><i class="bi bi-trophy-fill"></i> Projeção Campeão</div>
                        <?php foreach ($an['champ_proj'] as $i => $cp):
                            $barW = round($cp['prob'] / $maxProb * 100);
                            $tagColors = ['Contending'=>'#10b981','Buying'=>'#3b82f6','Selling'=>'#f97316','Rebuilding'=>'#64748b'];
                            $tagC = $tagColors[($cp['ai_tag'] ?? '')] ?? '#64748b';
                        ?>
                        <div class="sc-row">
                            <div class="sc-num"><?= $i + 1 ?></div>
                            <img class="sc-tlogo"
                                 src="<?= htmlspecialchars(getTeamPhoto($cp['team_photo'] ?? null)) ?>"
                                 alt="" onerror="this.src='/img/default-team.png'">
                            <div class="sc-tinfo">
                                <div class="sc-tname"><?= htmlspecialchars($cp['team_name']) ?></div>
                                <div class="sc-tmeta">
                                    OVR <?= $cp['avg_ovr'] ?> · max <?= $cp['max_ovr'] ?>
                                    <?php if ($cp['ai_tag']): ?>
                                    <span style="color:<?= $tagC ?>;font-weight:700"> · <?= htmlspecialchars($cp['ai_tag']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="sc-prob-col">
                                <div class="sc-pct"><?= $cp['prob'] ?>%</div>
                                <div class="sc-bar"><div class="sc-bar-fill" style="width:<?= $barW ?>%"></div></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Finalistas Projetados -->
                    <?php if (!empty($an['finalists'])): ?>
                    <div class="sc-block" style="text-align:center">
                        <div class="sc-block-title" style="justify-content:center"><i class="bi bi-lightning-charge-fill"></i> Próximos Finalistas Projetados</div>
                        <?php foreach ($an['finalists'] as $i => $f):
                            $confLabel = !empty($f['conference']) ? htmlspecialchars($f['conference']) : ($i === 0 ? 'CONF 1' : 'CONF 2');
                        ?>
                        <div style="display:flex;flex-direction:column;align-items:center;gap:4px;padding:8px 0;border-bottom:1px solid rgba(99,102,241,.08)">
                            <img class="sc-tlogo" style="width:34px;height:34px;border-radius:8px" src="<?= htmlspecialchars(getTeamPhoto($f['team_photo'] ?? null)) ?>" alt="" onerror="this.src='/img/default-team.png'">
                            <div style="font-size:12px;font-weight:700;color:var(--text)"><?= htmlspecialchars($f['team_name']) ?></div>
                            <div style="font-size:10px;color:var(--text-2)"><?= htmlspecialchars($f['owner_name']) ?></div>
                            <span class="sc-fin-lbl <?= $i === 0 ? 'c1' : 'c2' ?>"><?= $i === 0 ? '🥇' : '🥈' ?> <?= $confLabel ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (!empty($an['nba_cup'])): $cup = $an['nba_cup']; ?>
                        <div style="margin-top:8px;padding-top:8px;border-top:1px solid rgba(245,158,11,.2)">
                            <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#f59e0b;margin-bottom:6px">🏀 NBA Cup · +2pts</div>
                            <div style="display:flex;flex-direction:column;align-items:center;gap:3px">
                                <img class="sc-tlogo" style="width:30px;height:30px;border-radius:7px" src="<?= htmlspecialchars(getTeamPhoto($cup['team_photo'] ?? null)) ?>" alt="" onerror="this.src='/img/default-team.png'">
                                <div style="font-size:11px;font-weight:700;color:var(--text)"><?= htmlspecialchars($cup['team_name']) ?></div>
                                <span style="font-size:14px">🏆</span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                </div><!-- /cards centralizados -->

                <!-- Grid: Promoção + Rebaixamento -->
                <div class="sc-grid" style="grid-template-columns:1fr 1fr">

                    <!-- Zona de Promoção (NEXT/RISE apenas) -->
                    <?php if (!empty($an['promo_proj'])):
                        $gC = ['#16a34a','#22c55e','#4ade80','#86efac','#bbf7d0','var(--text-3)'];
                    ?>
                    <div class="sc-block">
                        <div class="sc-block-title"><i class="bi bi-arrow-up-circle-fill" style="color:#22c55e"></i> <span style="color:#22c55e">Zona de Promoção — Top 4</span></div>
                        <?php foreach ($an['promo_proj'] as $gi => $t):
                            $p = $t['promo_prob'];
                            $gc = $p >= 5 ? ($gC[min($gi,5)]) : 'var(--text-3)';
                        ?>
                        <div class="sc-zone-row">
                            <img class="sc-tlogo" src="<?= htmlspecialchars(getTeamPhoto($t['team_photo'] ?? null)) ?>" alt="" onerror="this.src='/img/default-team.png'">
                            <div class="sc-tinfo"><div class="sc-tname"><?= htmlspecialchars($t['team_name']) ?></div></div>
                            <span class="sc-zone-pct" style="color:<?= $gc ?>"><?= $p ?>%</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Zona de Rebaixamento -->
                    <?php if (!empty($an['rele_proj'])):
                        $rC = ['#dc2626','#ef4444','#f87171','#fca5a5','#fecaca','var(--text-3)'];
                    ?>
                    <div class="sc-block">
                        <div class="sc-block-title"><i class="bi bi-arrow-down-circle-fill" style="color:#ef4444"></i> <span style="color:#ef4444">Rebaixamento — Bottom 4</span></div>
                        <?php foreach ($an['rele_proj'] as $ri => $t):
                            $p = $t['rele_prob'];
                            $rc = $p >= 5 ? ($rC[min($ri,5)]) : 'var(--text-3)';
                        ?>
                        <div class="sc-zone-row">
                            <img class="sc-tlogo" src="<?= htmlspecialchars(getTeamPhoto($t['team_photo'] ?? null)) ?>" alt="" onerror="this.src='/img/default-team.png'">
                            <div class="sc-tinfo">
                                <div class="sc-tname"><?= htmlspecialchars($t['team_name']) ?></div>
                                <div class="sc-tmeta">OVR <?= $t['avg_ovr'] ?> · <?= $t['picks_count'] ?> picks · <?= $t['ranking_pts'] ?>pts</div>
                            </div>
                            <span class="sc-zone-pct" style="color:<?= $rc ?>"><?= $p ?>%</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                </div><!-- /sc-grid -->

                <!-- Projeção por Temporada (sempre visível) -->
                <?php if (!empty($an['season_projs'])): ?>
                <div style="margin-top:14px">
                    <div class="sc-block-title" style="margin-bottom:8px">
                        <i class="bi bi-calendar3"></i> Projeção por Temporada
                    </div>
                    <div class="sc-season-row">
                        <?php foreach ($an['season_projs'] as $sNum => $sp):
                            $baseYear   = $d['seasonYear'] ?? 0;
                            $labelS     = ($an['current_season'] ?? 0) + $sNum;
                            $labelY     = $baseYear > 2000 ? ($baseYear + $sNum) : '';
                            $f1 = $sp['finalist1'] ?? null;
                            $f2 = $sp['finalist2'] ?? null;
                            $champ = $sp['champion'] ?? null;
                            $tagColors = ['Contending'=>'#10b981','Buying'=>'#3b82f6','Selling'=>'#f97316','Rebuilding'=>'#64748b'];
                        ?>
                        <div class="sc-season-card">
                            <div class="sc-season-label">T<?= $labelS ?><?= $labelY ? " · {$labelY}" : '' ?></div>

                            <?php if ($f1): $tc1 = $tagColors[$f1['ai_tag'] ?? ''] ?? '#818cf8'; ?>
                            <div class="sc-sf-row">
                                <div class="sc-sf-icon"><?= ($champ && $champ['team_name'] === $f1['team_name']) ? '🏆' : '🥇' ?></div>
                                <img class="sc-tlogo" src="<?= htmlspecialchars(getTeamPhoto($f1['team_photo'] ?? null)) ?>" alt="" onerror="this.src='/img/default-team.png'">
                                <div class="sc-tinfo">
                                    <div class="sc-tname" style="font-size:11px"><?= htmlspecialchars($f1['team_name']) ?></div>
                                    <span style="font-size:9px;font-weight:700;color:<?= $tc1 ?>"><?= htmlspecialchars($f1['ai_tag'] ?? '') ?></span>
                                </div>
                                <div style="font-size:11px;font-weight:800;color:#818cf8;flex-shrink:0"><?= $f1['sp'] ?>%</div>
                            </div>
                            <?php endif; ?>

                            <?php if ($f2): $tc2 = $tagColors[$f2['ai_tag'] ?? ''] ?? '#818cf8'; ?>
                            <div class="sc-sf-row">
                                <div class="sc-sf-icon" style="color:#94a3b8">🥈</div>
                                <img class="sc-tlogo" src="<?= htmlspecialchars(getTeamPhoto($f2['team_photo'] ?? null)) ?>" alt="" onerror="this.src='/img/default-team.png'">
                                <div class="sc-tinfo">
                                    <div class="sc-tname" style="font-size:11px"><?= htmlspecialchars($f2['team_name']) ?></div>
                                    <span style="font-size:9px;font-weight:700;color:<?= $tc2 ?>"><?= htmlspecialchars($f2['ai_tag'] ?? '') ?></span>
                                </div>
                                <div style="font-size:11px;font-weight:800;color:var(--text-3);flex-shrink:0"><?= $f2['sp'] ?>%</div>
                            </div>
                            <?php endif; ?>

                            <?php if ($champ): ?>
                            <div style="margin-top:6px;padding-top:6px;border-top:1px solid rgba(245,158,11,.2);display:flex;align-items:center;gap:5px">
                                <span style="font-size:9px;color:#f59e0b;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Campeão</span>
                                <span style="font-size:10px;font-weight:700;color:var(--text);flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($champ['team_name']) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ACORDEÃO: somente tabela de probabilidades -->
                <?php if (!empty($an['matrix'])): ?>
                <div class="sc-accordion">
                    <div class="sc-acc-head" onclick="scToggle(this)">
                        <i class="bi bi-table" style="color:#818cf8;font-size:13px"></i>
                        <span class="sc-acc-title">Previsão do Sprint Completo <span style="font-size:10px;color:var(--text-3);font-weight:600">· Monte Carlo · <?= $an['team_count'] ?> times · <?= $an['seasons_left'] ?? '?' ?> seasons · PROJ = pts atuais + todas as seasons restantes</span></span>
                        <i class="bi bi-chevron-down sc-acc-chevron"></i>
                    </div>
                    <div class="sc-acc-body">
                        <?php
                            // Define zonas de cor por coluna
                            $isEliteLeague = ($an['league'] ?? '') === 'ELITE';
                            $tc = $an['team_count'];
                            $promoLimit = $isEliteLeague ? 0 : 3; // pos 0 only for ELITE, 0-3 for others
                            $releStart  = max($tc - 4, 4);        // últimas 4 posições
                            // Paleta verde (índice por posição dentro da zona de promoção)
                            $gPal = ['#16a34a','#22c55e','#4ade80','#86efac'];
                            // Paleta vermelha
                            $rPal = ['#dc2626','#ef4444','#f87171','#fca5a5'];
                        ?>
                        <div class="sc-matrix-wrap">
                            <table class="sc-matrix">
                                <thead>
                                    <tr>
                                        <th class="tc">Time</th>
                                        <th class="pc" title="Pontos ranking">PTS</th>
                                        <th class="pc" title="Projeção de pontos ao final da temporada" style="color:#f59e0b">PROJ</th>
                                        <?php for ($pos = 1; $pos <= $tc; $pos++):
                                            $isP = ($pos - 1) <= $promoLimit;
                                            $isR = ($pos - 1) >= $releStart;
                                            $thCls = $isP ? 'promo' : ($isR ? 'rele' : '');
                                        ?>
                                        <th class="<?= $thCls ?>"><?= $pos ?>º</th>
                                        <?php endfor; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($an['matrix'] as $mIdx => $mrow):
                                        $mt      = $mrow['team'];
                                        $mprobs  = $mrow['probs'];
                                        $peakCol = $mrow['peak_col'] ?? $mIdx;
                                        $projPts = $mrow['proj_pts'] ?? ($mt['ranking_pts'] ?? 0);
                                        $curPts  = $mt['ranking_pts'] ?? 0;
                                        $delta   = $projPts - $curPts;
                                    ?>
                                    <tr>
                                        <td class="tc">
                                            <div style="display:flex;align-items:center;gap:7px">
                                                <span style="font-size:10px;font-weight:800;color:var(--text-3);flex-shrink:0;width:16px;text-align:right"><?= $mIdx+1 ?>º</span>
                                                <img style="width:22px;height:22px;border-radius:5px;object-fit:cover;flex-shrink:0;border:1px solid rgba(99,102,241,.2)"
                                                     src="<?= htmlspecialchars(getTeamPhoto($mt['team_photo'] ?? null)) ?>"
                                                     alt="" onerror="this.src='/img/default-team.png'">
                                                <span style="font-size:12px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:110px"><?= htmlspecialchars($mt['team_name']) ?></span>
                                            </div>
                                        </td>
                                        <td style="font-weight:700;color:#818cf8;font-size:12px"><?= $curPts ?></td>
                                        <td>
                                            <span class="sc-proj-cell"><?= $projPts ?></span>
                                            <?php if ($delta > 0): ?>
                                            <span class="sc-proj-delta">+<?= $delta ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <?php for ($pos = 0; $pos < $tc; $pos++):
                                            $p    = $mprobs[$pos] ?? 0;
                                            $peak = ($pos === $peakCol) ? ' peak' : '';
                                            // Cor por zona da coluna
                                            if ($pos <= $promoLimit) {
                                                // Verde: intensidade baseada no valor
                                                $gi = $p >= 20 ? 0 : ($p >= 10 ? 1 : ($p >= 5 ? 2 : 3));
                                                $gc = $p >= 2 ? $gPal[$gi] : 'var(--text-3)';
                                                $cellStyle = "color:{$gc}";
                                            } elseif ($pos >= $releStart) {
                                                // Vermelho: intensidade baseada no valor
                                                $ri = $p >= 20 ? 0 : ($p >= 10 ? 1 : ($p >= 5 ? 2 : 3));
                                                $rc = $p >= 2 ? $rPal[$ri] : 'var(--text-3)';
                                                $cellStyle = "color:{$rc}";
                                            } else {
                                                $cls = $p >= 15 ? 'md' : ($p >= 5 ? 'lo' : 'vlo');
                                                $cellStyle = '';
                                            }
                                        ?>
                                        <td>
                                            <?php if ($cellStyle): ?>
                                            <span class="sc-pcell<?= $peak ?>" style="<?= $cellStyle ?>;font-weight:800;font-size:11px"><?= $p ?>%</span>
                                            <?php else: ?>
                                            <span class="sc-pcell <?= $cls ?><?= $peak ?>"><?= $p ?>%</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endfor; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div><!-- /sc-accordion -->
                <?php endif; ?>

            </div><!-- /sc-wrap -->
            <?php endif; ?>

            <?php /* ── Times ── */ ?>
            <div class="sec-hd" style="color:<?= $col ?>;margin-top:24px">
                <i class="bi bi-list-ol" style="color:<?= $col ?>"></i>
                Times
            </div>

            <?php if (empty($d['teams'])): ?>
            <div class="empty-state">
                <i class="bi bi-people"></i>
                <p>Nenhum time cadastrado nesta liga.</p>
            </div>
            <?php else: ?>
            <div class="teams-list">
                <?php foreach ($d['teams'] as $rank => $t):
                    $rankNum = $rank + 1;
                    $rankClass = $rankNum === 1 ? 'gold' : ($rankNum === 2 ? 'silver' : ($rankNum === 3 ? 'bronze' : ''));
                    $rowId = 'team-' . $league . '-' . $t['id'];
                ?>
                <div class="team-row" id="<?= $rowId ?>">
                    <div class="team-head" onclick="toggleTeam('<?= $rowId ?>')">
                        <div class="team-rank <?= $rankClass ?>"><?= $rankNum ?></div>
                        <img class="team-logo"
                             src="<?= htmlspecialchars(getTeamPhoto($t['team_photo'] ?? null)) ?>"
                             alt="<?= htmlspecialchars($t['name']) ?>"
                             onerror="this.src='/img/default-team.png'">
                        <div class="team-info">
                            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                <div class="team-name"><?= htmlspecialchars($t['city'] . ' ' . $t['name']) ?></div>
                                <?php
                                $tagColors  = ['contending'=>'#10b981','buying'=>'#3b82f6','selling'=>'#f97316','rebuilding'=>'#64748b'];
                                $tagLabels  = ['contending'=>'Contending','buying'=>'Buying','selling'=>'Selling','rebuilding'=>'Rebuilding'];
                                $rawMfTag   = $t['team_tag'] ?? null;
                                if (!$rawMfTag) $rawMfTag = computeAiTagPHP(
                                    isset($t['starters_avg_ovr']) ? (float)$t['starters_avg_ovr'] : null,
                                    isset($t['starters_max_ovr']) ? (float)$t['starters_max_ovr'] : null,
                                    isset($t['avg_age'])          ? (float)$t['avg_age']          : null
                                );
                                $mfTag = strtolower($rawMfTag ?? '');
                                if ($mfTag && isset($tagColors[$mfTag])):
                                    $tc = $tagColors[$mfTag];
                                ?>
                                <span style="font-size:9px;font-weight:700;padding:1px 7px;border-radius:999px;border:1px solid <?= $tc ?>;color:<?= $tc ?>;background:<?= $tc ?>22;white-space:nowrap"><?= $tagLabels[$mfTag] ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="team-gm"><i class="bi bi-person-fill" style="font-size:10px;margin-right:3px"></i><?= htmlspecialchars($t['owner_name']) ?></div>
                        </div>
                        <div class="team-cap">
                            <div class="cap-val"><?= $t['cap_top8'] ?></div>
                            <div class="cap-lbl">CAP</div>
                        </div>
                        <?php if (!empty($t['public_enabled']) && !empty($t['public_slug'])): ?>
                        <a href="/times/<?= htmlspecialchars($t['public_slug']) ?>" target="_blank" rel="noopener" title="Ver página pública" onclick="event.stopPropagation()" style="color:var(--text-2);font-size:15px;padding:0 6px;display:flex;align-items:center">
                            <i class="bi bi-globe2"></i>
                        </a>
                        <?php endif; ?>
                        <div class="team-toggle"><i class="bi bi-chevron-down"></i></div>
                    </div>
                    <div class="starters-wrap">
                        <table class="starters-table">
                            <thead>
                                <tr>
                                    <th>POS</th>
                                    <th>Jogador</th>
                                    <th>OVR</th>
                                    <th>Idade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (['PG','SG','SF','PF','C'] as $pos):
                                    $p = $t['starters'][$pos] ?? null;
                                    if ($p):
                                        $ovr = (int)$p['ovr'];
                                        $ovrClass = $ovr >= 90 ? 'ovr-s' : ($ovr >= 85 ? 'ovr-a' : ($ovr >= 80 ? 'ovr-b' : 'ovr-c'));
                                ?>
                                <tr>
                                    <td><span class="pos-badge"><?= $pos ?></span></td>
                                    <td style="font-weight:600;color:var(--text)"><?= htmlspecialchars($p['name']) ?></td>
                                    <td><span class="ovr-badge <?= $ovrClass ?>"><?= $ovr ?></span></td>
                                    <td style="color:var(--text-2)"><?= htmlspecialchars($p['age'] ?? '—') ?></td>
                                </tr>
                                <?php else: ?>
                                <tr class="empty-row">
                                    <td><span class="pos-badge"><?= $pos ?></span></td>
                                    <td colspan="3" style="color:var(--text-3);font-style:italic">Vaga em aberto</td>
                                </tr>
                                <?php endif; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php /* ── Power Ranking ── */ ?>
            <div class="sec-hd" style="color:<?= $col ?>;margin-top:28px">
                <i class="bi bi-activity" style="color:<?= $col ?>"></i>
                POWER RANKING
            </div>
            <?php if (empty($d['powerRanking'])): ?>
            <div class="empty-state">
                <i class="bi bi-bar-chart"></i>
                <p>Sem dados suficientes para gerar o power ranking.</p>
            </div>
            <?php else: ?>
            <div class="power-wrap">
                <div class="power-grid">
                    <?php foreach ($d['powerRanking'] as $idx => $pr):
                        $rankNum = $idx + 1;
                        $rankClass = $rankNum === 1 ? 'gold' : ($rankNum === 2 ? 'silver' : ($rankNum === 3 ? 'bronze' : ''));
                    ?>
                    <div class="power-card">
                        <div class="power-rank <?= $rankClass ?>">#<?= $rankNum ?></div>
                        <div class="power-head">
                            <img class="power-logo"
                                 src="<?= htmlspecialchars(getTeamPhoto($pr['team_photo'] ?? null)) ?>"
                                 alt="" onerror="this.src='/img/default-team.png'">
                            <div>
                                <div class="power-name"><?= htmlspecialchars($pr['team_name']) ?></div>
                                <div class="power-owner"><?= htmlspecialchars($pr['owner_name'] ?? '') ?></div>
                            </div>
                        </div>
                        <div class="power-score">
                            <?= htmlspecialchars((string)$pr['score']) ?>
                            <span>Score</span>
                        </div>
                        <div class="power-badges">
                            <?php if (!empty($pr['is_champion'])): ?>
                                <span class="power-badge champ">Campeao</span>
                            <?php elseif (!empty($pr['is_runner_up'])): ?>
                                <span class="power-badge runner">Vice</span>
                            <?php endif; ?>
                        </div>
                        <div class="power-metrics">
                            <div class="power-metric">
                                <div class="val"><?= htmlspecialchars((string)$pr['avg_ovr']) ?></div>
                                <div class="lbl">OVR Medio</div>
                            </div>
                            <div class="power-metric">
                                <div class="val"><?= htmlspecialchars((string)$pr['max_ovr']) ?></div>
                                <div class="lbl">Max OVR</div>
                            </div>
                            <div class="power-metric">
                                <div class="val"><?= htmlspecialchars((string)$pr['avg_age']) ?></div>
                                <div class="lbl">Idade</div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php /* ── Hall da Fama ── */ ?>
            <?php if (!empty($d['hof'])): ?>
            <div class="sec-hd" style="color:<?= $col ?>;margin-top:28px">
                <i class="bi bi-stars" style="color:<?= $col ?>"></i>
                HALL DA FAMA
            </div>
            <div class="hof-list">
                <?php foreach ($d['hof'] as $h): ?>
                <div class="hof-card">
                    <img class="hof-logo"
                         src="<?= htmlspecialchars(getTeamPhoto($h['team_photo'] ?? null)) ?>"
                         alt="" onerror="this.src='/img/default-team.png'">
                    <div class="hof-info">
                        <div class="hof-team"><?= htmlspecialchars(trim($h['display_team'] ?? '—')) ?></div>
                        <div class="hof-gm"><?= htmlspecialchars($h['display_gm'] ?? '—') ?></div>
                    </div>
                    <div class="hof-titles">
                        <div class="hof-num"><?= (int)$h['titles'] ?></div>
                        <div class="hof-lbl"><?= (int)$h['titles'] === 1 ? 'título' : 'títulos' ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php /* ── Ranking da liga ── */ ?>
            <?php if (!empty($d['ranking'])): ?>
            <div class="sec-hd" style="color:<?= $col ?>;margin-top:28px">
                <i class="bi bi-bar-chart-fill" style="color:<?= $col ?>"></i>
                RANKING DA LIGA
            </div>
            <div class="ranking-wrap">
                <table class="ranking-table">
                    <thead>
                        <tr>
                            <th style="width:36px">#</th>
                            <th>Time</th>
                            <th class="right">Títulos</th>
                            <th class="right">Pontos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($d['ranking'] as $ri => $rk):
                            $rPos = $ri + 1;
                            $rCls = $rPos === 1 ? 'gold' : ($rPos === 2 ? 'silver' : ($rPos === 3 ? 'bronze' : ''));
                            $pts  = (int)$rk['total_points'];
                            $tit  = (int)$rk['total_titles'];
                        ?>
                        <tr>
                            <td class="rk-pos <?= $rCls ?>"><?= $rPos ?></td>
                            <td>
                                <div class="rk-team">
                                    <img class="rk-logo"
                                         src="<?= htmlspecialchars(getTeamPhoto($rk['team_photo'] ?? null)) ?>"
                                         alt="" onerror="this.src='/img/default-team.png'">
                                    <div>
                                        <div class="rk-name"><?= htmlspecialchars($rk['team_name']) ?></div>
                                        <div class="rk-gm"><?= htmlspecialchars($rk['owner_name'] ?? '') ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="right">
                                <?php if ($tit > 0): ?>
                                <span class="rk-titles">🏆 <?= $tit ?></span>
                                <?php else: ?>
                                <span style="color:var(--text-3)">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="right rk-pts"><?= $pts ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </div><!-- /league-pane -->
        <?php endforeach; ?>

    </div><!-- /content -->
</main>
</div><!-- /app -->

<script>
    // Sidebar
    (function () {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sbOverlay');
        const menuBtn = document.getElementById('menuBtn');
        if (!sidebar) return;
        const close = () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); };
        if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.add('open'); overlay.classList.add('show'); });
        if (overlay) overlay.addEventListener('click', close);
        document.querySelectorAll('.sb-nav a').forEach(a => a.addEventListener('click', close));
    })();

    // Theme toggle
    const themeKey = 'fba-theme';
    const themeToggle = document.getElementById('themeToggle');
    const applyTheme = (theme) => {
        if (theme === 'light') {
            document.documentElement.setAttribute('data-theme', 'light');
            if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-sun"></i><span>Modo claro</span>';
        } else {
            document.documentElement.removeAttribute('data-theme');
            if (themeToggle) themeToggle.innerHTML = '<i class="bi bi-moon"></i><span>Modo escuro</span>';
        }
    };
    applyTheme(localStorage.getItem(themeKey) || 'dark');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const next = document.documentElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            localStorage.setItem(themeKey, next);
            applyTheme(next);
        });
    }

    // League tab switching
    function switchTab(league) {
        document.querySelectorAll('.league-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.league-pane').forEach(p => p.classList.remove('active'));
        document.querySelector(`.league-tab[data-league="${league}"]`)?.classList.add('active');
        document.getElementById('pane-' + league)?.classList.add('active');
    }

    // Team row toggle
    function toggleTeam(rowId) {
        const row = document.getElementById(rowId);
        if (row) row.classList.toggle('open');
    }

    // SuperComputador accordion
    function scToggle(head) {
        const body = head.nextElementSibling;
        const icon = head.querySelector('.sc-acc-chevron');
        if (!body) return;
        const open = body.classList.toggle('open');
        if (icon) icon.classList.toggle('open', open);
    }
</script>
</body>
</html>
