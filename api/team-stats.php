<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/salary_cap.php';
require_once __DIR__ . '/../backend/team_punishments.php'; // sqlSoPunicoes()
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo  = db();
$user = getUserSession();

// A ficha do time é da sprint em andamento, não da vida inteira da
// franquia: no fim de sprint o elenco é desmontado, as picks refeitas e o
// campeão anterior não tem relação com o time de hoje. Misturar as duas
// infla título, campanha e média de OVR com dados de outro jogo. É a mesma
// régua do estatisticas.php. As sprints são por liga, então as ativas
// bastam — cada temporada já sabe de qual sprint é.
$TEMPORADAS_DA_SPRINT = "(SELECT id FROM seasons WHERE sprint_id IN (SELECT id FROM sprints WHERE status = 'active'))";

$teamId = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
if (!$teamId) { echo json_encode(['error' => 'team_id obrigatório']); exit; }

$stmtTeam = $pdo->prepare("SELECT t.*, CONCAT(t.city,' ',t.name) AS full_name,
                                  u.name AS owner_name, u.id AS owner_id, u.phone AS owner_phone
                             FROM teams t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?");
$stmtTeam->execute([$teamId]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
if (!$team) { echo json_encode(['error' => 'Time não encontrado']); exit; }

/* O TELEFONE SÓ SAI DAQUI COMO "DÁ PRA CHAMAR" OU "NÃO DÁ".
   O número em si é dado de cadastro, e a página só precisa saber se o botão
   do WhatsApp aparece e pra onde ele leva — quem quiser o número clica e o vê
   no próprio WhatsApp, como aconteceria se tivesse pedido no grupo. Mandar a
   coluna crua junto do resto da resposta espalharia a agenda inteira da liga
   por qualquer tela que consumisse esta API.

   O formato guardado já é E.164 sem o "+" (normalizeBrazilianPhone), que é
   exatamente o que o wa.me espera. Os cinco GMs de fora do Brasil ficam com o
   DDI deles, e é por isso que o link é montado do valor guardado e não com um
   "55" grudado aqui. */
$fone = preg_replace('/\D+/', '', (string)($team['owner_phone'] ?? ''));
unset($team['owner_phone']);
$team['owner_whatsapp'] = preg_match('/^\d{10,15}$/', $fone) ? 'https://wa.me/' . $fone : null;

// ── 1. Pontos por temporada ───────────────────────────────────────
$stmtPts = $pdo->prepare("SELECT COUNT(*) AS seasons_played, SUM(points) AS total_points, MAX(points) AS best_season_pts FROM team_season_points WHERE team_id = ? AND season_id IN $TEMPORADAS_DA_SPRINT");
$stmtPts->execute([$teamId]);
$ptsData = $stmtPts->fetch(PDO::FETCH_ASSOC);

// ── 2. Playoffs (≥2 pts) ─────────────────────────────────────────
$stmtPlayoffs = $pdo->prepare("SELECT COUNT(*) AS playoff_appearances FROM team_season_points WHERE team_id = ? AND points >= 2 AND season_id IN $TEMPORADAS_DA_SPRINT");
$stmtPlayoffs->execute([$teamId]);
$playoffData = $stmtPlayoffs->fetch(PDO::FETCH_ASSOC);

// ── 3. Temporada regular via team_ranking_points ─────────────────
$stmtStandings = $pdo->prepare("SELECT regular_season_points, playoff_champion, playoff_runner_up, playoff_conference_finals, playoff_second_round, playoff_first_round FROM team_ranking_points WHERE team_id = ? AND season_id IN $TEMPORADAS_DA_SPRINT");
$stmtStandings->execute([$teamId]);
$standingsRaw = $stmtStandings->fetchAll(PDO::FETCH_ASSOC);
$top1Regular = 0; $top4Regular = 0; $top8Regular = 0;
foreach ($standingsRaw as $r) {
    if ((int)$r['regular_season_points'] > 0)  $top8Regular++;
    if ((int)$r['regular_season_points'] >= 4) $top4Regular++;
    if ((int)$r['regular_season_points'] >= 8) $top1Regular++;
}

// ── 4. Títulos e playoffs por fase ───────────────────────────────
$stmtTitles = $pdo->prepare("SELECT SUM(champion_team_id=?) AS titles, SUM(runner_up_team_id=?) AS runner_ups FROM season_history WHERE season_id IN $TEMPORADAS_DA_SPRINT");
$stmtTitles->execute([$teamId, $teamId]);
$titlesData = $stmtTitles->fetch(PDO::FETCH_ASSOC);

$stmtChampSeasons = $pdo->prepare("SELECT season_id, year, season_number FROM season_history WHERE champion_team_id = ? AND season_id IN $TEMPORADAS_DA_SPRINT ORDER BY year DESC");
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
$stmtAllPlayers = $pdo->prepare("SELECT COUNT(DISTINCT player_id) AS total FROM player_season_log WHERE team_id = ? AND season_id IN $TEMPORADAS_DA_SPRINT");
$stmtAllPlayers->execute([$teamId]);
$totalPlayers = (int)$stmtAllPlayers->fetch(PDO::FETCH_ASSOC)['total'];

$stmtBest = $pdo->prepare("SELECT player_name, ovr, position, year FROM player_season_log WHERE team_id = ? AND season_id IN $TEMPORADAS_DA_SPRINT ORDER BY ovr DESC LIMIT 1");
$stmtBest->execute([$teamId]);
$bestPlayer = $stmtBest->fetch(PDO::FETCH_ASSOC);

$stmtWorst = $pdo->prepare("SELECT player_name, ovr, position, year FROM player_season_log WHERE team_id = ? AND ovr > 0 AND season_id IN $TEMPORADAS_DA_SPRINT ORDER BY ovr ASC LIMIT 1");
$stmtWorst->execute([$teamId]);
$worstPlayer = $stmtWorst->fetch(PDO::FETCH_ASSOC);

$bestByPos = [];
foreach (['PG','SG','SF','PF','C'] as $pos) {
    $s = $pdo->prepare("SELECT player_name, ovr, year FROM player_season_log WHERE team_id = ? AND position = ? AND season_id IN $TEMPORADAS_DA_SPRINT ORDER BY ovr DESC LIMIT 1");
    $s->execute([$teamId, $pos]);
    $bestByPos[$pos] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Média de OVR e idade usando apenas top 5 (titulares) por temporada
$stmtSeasons = $pdo->prepare("SELECT DISTINCT season_id, year FROM player_season_log WHERE team_id = ? AND year IS NOT NULL AND season_id IN $TEMPORADAS_DA_SPRINT ORDER BY year ASC");
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
            WHERE ss.team_id = ? AND ss.season_id IN $TEMPORADAS_DA_SPRINT
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

// ── 7d. Trades por ciclo (trades simples + multi-trades) ─────────
// multi_trades só passou a registrar o ciclo depois desta atualização;
// trocas multi-time antigas caem no grupo "cycle = null" (sem ciclo
// conhecido) em vez de serem contadas erradas ou descartadas.
$tradesByCycle = [];
try {
    // PHP converte chave de array null em "" — usamos um sentinel numérico
    // interno pro grupo "sem ciclo" e só voltamos a null na saída.
    $SEM_CICLO = -1;
    $cycleCounts = [];
    $sTC = $pdo->prepare("
        SELECT cycle, COUNT(*) AS total
        FROM trades
        WHERE status = 'accepted' AND (from_team_id = ? OR to_team_id = ?)
        GROUP BY cycle
    ");
    $sTC->execute([$teamId, $teamId]);
    foreach ($sTC->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $key = $r['cycle'] !== null ? (int)$r['cycle'] : $SEM_CICLO;
        $cycleCounts[$key] = ($cycleCounts[$key] ?? 0) + (int)$r['total'];
    }

    $hasMultiCycle = (bool)$pdo->query("SHOW COLUMNS FROM multi_trades LIKE 'cycle'")->fetch();
    $cycleCol = $hasMultiCycle ? 'mt.cycle' : 'NULL';
    $sMTC = $pdo->prepare("
        SELECT {$cycleCol} AS cycle, COUNT(*) AS total
        FROM multi_trades mt
        JOIN multi_trade_teams mtt ON mtt.trade_id = mt.id
        WHERE mt.status = 'accepted' AND mtt.team_id = ?
        GROUP BY {$cycleCol}
    ");
    $sMTC->execute([$teamId]);
    foreach ($sMTC->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $key = $r['cycle'] !== null ? (int)$r['cycle'] : $SEM_CICLO;
        $cycleCounts[$key] = ($cycleCounts[$key] ?? 0) + (int)$r['total'];
    }

    ksort($cycleCounts);
    // "sem ciclo" vai pro fim da lista em vez de ficar antes do ciclo 1
    if (array_key_exists($SEM_CICLO, $cycleCounts)) {
        $semCiclo = $cycleCounts[$SEM_CICLO];
        unset($cycleCounts[$SEM_CICLO]);
        $cycleCounts[$SEM_CICLO] = $semCiclo;
    }
    foreach ($cycleCounts as $cycle => $total) {
        $tradesByCycle[] = ['cycle' => ($cycle === $SEM_CICLO ? null : $cycle), 'total' => $total];
    }
} catch (Exception $e) { $tradesByCycle = []; }

// ── 7e. Trades por time parceiro (trades simples + multi-trades) ──
// Numa multi-trade com 3+ times, cada outro participante conta como
// parceiro — o time negociou com todos eles naquele evento.
$tradesByPartner = [];
try {
    $stmtLeague = $pdo->prepare('SELECT league FROM teams WHERE id = ?');
    $stmtLeague->execute([$teamId]);
    $league = $stmtLeague->fetchColumn();

    $teamRows = [];
    if ($league) {
        $stmtTeams = $pdo->prepare("SELECT id AS team_id, CONCAT(city, ' ', name) AS team_name, photo_url FROM teams WHERE league = ? AND id <> ? ORDER BY city ASC, name ASC");
        $stmtTeams->execute([$league, $teamId]);
        $teamRows = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);
    }

    $countByTeam = [];
    $sTP = $pdo->prepare("
        SELECT other.id AS team_id, COUNT(*) AS total
        FROM trades tr
        JOIN teams other ON other.id = CASE WHEN tr.from_team_id = ? THEN tr.to_team_id ELSE tr.from_team_id END
        WHERE tr.status = 'accepted' AND (tr.from_team_id = ? OR tr.to_team_id = ?)
        GROUP BY other.id
    ");
    $sTP->execute([$teamId, $teamId, $teamId]);
    foreach ($sTP->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $countByTeam[(int)$r['team_id']] = (int)$r['total'];
    }

    $sMTP = $pdo->prepare("
        SELECT other.team_id AS team_id, COUNT(*) AS total
        FROM multi_trade_teams mine
        JOIN multi_trades mt ON mt.id = mine.trade_id AND mt.status = 'accepted'
        JOIN multi_trade_teams other ON other.trade_id = mine.trade_id AND other.team_id <> mine.team_id
        WHERE mine.team_id = ?
        GROUP BY other.team_id
    ");
    $sMTP->execute([$teamId]);
    foreach ($sMTP->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tid = (int)$r['team_id'];
        $countByTeam[$tid] = ($countByTeam[$tid] ?? 0) + (int)$r['total'];
    }

    foreach ($teamRows as $r) {
        $tid = (int)$r['team_id'];
        $tradesByPartner[] = [
            'team_id'   => $tid,
            'team_name' => $r['team_name'],
            'photo_url' => $r['photo_url'],
            'total'     => $countByTeam[$tid] ?? 0,
        ];
    }
    usort($tradesByPartner, static function(array $a, array $b): int {
        if ($a['total'] !== $b['total']) return $b['total'] <=> $a['total'];
        return strcmp($a['team_name'], $b['team_name']);
    });
} catch (Exception $e) { $tradesByPartner = []; }

// ── 7g. Hall dos aposentados ──────────────────────────────────────
// Jogadores que passaram pelo time, bateram 86+ de OVR em ALGUM momento
// (não precisa ser no fim da carreira) e hoje já saíram da liga de vez
// (removidos da tabela players — não só trocados pra outro time).
$retiredLegends = [];
try {
    $sRL = $pdo->prepare("
        SELECT psl.player_id, psl.player_name, MAX(psl.ovr) AS peak_ovr,
               MIN(psl.year) AS first_year, MAX(psl.year) AS last_year
        FROM player_season_log psl
        WHERE psl.team_id = ? AND psl.season_id IN $TEMPORADAS_DA_SPRINT
          AND NOT EXISTS (SELECT 1 FROM players p WHERE p.id = psl.player_id)
        GROUP BY psl.player_id, psl.player_name
        HAVING peak_ovr >= 86
        ORDER BY peak_ovr DESC
    ");
    $sRL->execute([$teamId]);
    $retiredLegends = $sRL->fetchAll(PDO::FETCH_ASSOC);
    foreach ($retiredLegends as &$rl) {
        $rl['peak_ovr'] = (int)$rl['peak_ovr'];
        $rl['first_year'] = $rl['first_year'] !== null ? (int)$rl['first_year'] : null;
        $rl['last_year'] = $rl['last_year'] !== null ? (int)$rl['last_year'] : null;
    }
    unset($rl);
} catch (Exception $e) { $retiredLegends = []; }

// ── 7f. Estatísticas comparativas da liga (mesmas métricas do estatisticas.php) ──
// Para cada métrica: valor do time + posição dele entre os times da mesma liga.
$leagueStats = [];
$teamLeague = $team['league'] ?? null;
if ($teamLeague) {
    $metricQueries = [
        // Pelo draft_pool, que é só do draft padrão. Contar pela players
        // somaria o draft inicial junto — ele grava o mesmo campo, e a players
        // não guarda de qual draft o jogador veio. Mesma regra do
        // estatisticas.php, que mostra este número lado a lado.
        'drafted' => "SELECT t.id, COUNT(dp.id) AS val FROM teams t
                      LEFT JOIN draft_pool dp ON dp.drafted_by_team_id = t.id AND dp.season_id IN $TEMPORADAS_DA_SPRINT
                      WHERE t.league = ? GROUP BY t.id",
        'turnover' => "SELECT t.id, COUNT(DISTINCT psl.player_id) AS val FROM teams t
                       LEFT JOIN player_season_log psl ON psl.team_id = t.id AND psl.season_id IN $TEMPORADAS_DA_SPRINT
                       WHERE t.league = ? GROUP BY t.id",
        'fa' => "SELECT t.id, COUNT(far.id) AS val FROM teams t
                 LEFT JOIN fa_requests far ON far.winner_team_id = t.id AND far.status = 'assigned' AND far.season_id IN $TEMPORADAS_DA_SPRINT
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
$stmtAwards = $pdo->prepare("SELECT award_type, COUNT(*) AS total FROM season_awards WHERE team_id = ? AND season_id IN $TEMPORADAS_DA_SPRINT GROUP BY award_type ORDER BY total DESC");
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
// Do draft_pool, pelo mesmo motivo da métrica lá em cima: a players mistura os
// jogadores do draft inicial, que não são escolha de ninguém — vieram com o
// time pronto quando a liga nasceu.
//
// O OVR aqui é o do dia do draft, não o de hoje, e é o número certo pra esta
// lista: ela conta o que o time escolheu, e quanto o jogador evoluiu depois é
// outra história. A temporada sai do season_number, que é o mesmo que a
// players guardava em drafted_season_number.
$draftedPlayers = [];
try {
    $sD = $pdo->prepare("
        SELECT dp.name, dp.position, dp.age, dp.ovr,
               s.season_number AS drafted_season_number
        FROM draft_pool dp
        LEFT JOIN seasons s ON s.id = dp.season_id
        WHERE dp.drafted_by_team_id = ? AND dp.season_id IN $TEMPORADAS_DA_SPRINT
        ORDER BY drafted_season_number DESC, dp.ovr DESC
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

// ── Campanhas de playoff (placares das séries de playoff_series, ex.: 4x2) ──
$playoffCampaigns = [];
try {
    $stmtSeries = $pdo->prepare("
        SELECT ps.season_id, ps.round, ps.games, s.year, s.season_number,
               pr.position AS result
        FROM playoff_series ps
        JOIN seasons s ON s.id = ps.season_id
        LEFT JOIN playoff_results pr ON pr.season_id = ps.season_id AND pr.team_id = ps.team_id
        WHERE ps.team_id = ? AND ps.season_id IN $TEMPORADAS_DA_SPRINT
        ORDER BY s.year DESC, s.season_number DESC, FIELD(ps.round,'r1','r2','cf','fin')");
    $stmtSeries->execute([$teamId]);
    $bySeason = [];
    foreach ($stmtSeries->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = (int)$r['season_id'];
        if (!isset($bySeason[$sid])) {
            $bySeason[$sid] = [
                'season_id'     => $sid,
                'year'          => $r['year'] !== null ? (int)$r['year'] : null,
                'season_number' => $r['season_number'] !== null ? (int)$r['season_number'] : null,
                'result'        => $r['result'],
                'series'        => [],
            ];
        }
        $bySeason[$sid]['series'][] = ['round' => $r['round'], 'games' => (int)$r['games']];
    }
    $roundOrder = ['r1', 'r2', 'cf', 'fin'];
    foreach ($bySeason as &$c) {
        // O time venceu todas as séries menos a última que jogou (a eliminação),
        // exceto o campeão, que venceu todas — inclusive a final.
        $lostRound = null;
        if ($c['result'] !== 'champion') {
            $rs = array_map(fn($s) => $s['round'], $c['series']);
            usort($rs, fn($a, $b) => array_search($a, $roundOrder) <=> array_search($b, $roundOrder));
            $lostRound = end($rs) ?: null;
        }
        foreach ($c['series'] as &$s) {
            $won = ($s['round'] !== $lostRound);
            $teamW = $won ? 4 : max(0, $s['games'] - 4);
            $oppW  = $won ? max(0, $s['games'] - 4) : 4;
            $s['won']   = $won;
            $s['score'] = $teamW . 'x' . $oppW;
        }
        unset($s);
    }
    unset($c);
    $playoffCampaigns = array_values($bySeason);
} catch (Exception $e) {
    $playoffCampaigns = [];
}

// ── Salary Cap (só ELITE) ─────────────────────────────────────────
$capSummary = null;
if (strtoupper((string)($team['league'] ?? '')) === 'ELITE') {
    try { $capSummary = getTeamCapSummary($pdo, $teamId); } catch (Exception $e) { $capSummary = null; }
}

// ── Reputação / punições (FBA SERASA) ────────────────────────────
$punishments = ['total' => 0, 'active' => 0, 'recent' => []];
try {
    $stmtPun = $pdo->prepare("SELECT COUNT(*) AS total, SUM(reverted_at IS NULL) AS active
                              FROM team_punishments WHERE team_id = ?" . sqlSoPunicoes(''));
    $stmtPun->execute([$teamId]);
    $pr = $stmtPun->fetch(PDO::FETCH_ASSOC);
    $punishments['total'] = (int)($pr['total'] ?? 0);
    $punishments['active'] = (int)($pr['active'] ?? 0);

    $stmtRecent = $pdo->prepare("SELECT punishment_label, motive, type, created_at, reverted_at
                                  FROM team_punishments WHERE team_id = ?" . sqlSoPunicoes('') . "
                                  ORDER BY created_at DESC LIMIT 5");
    $stmtRecent->execute([$teamId]);
    $punishments['recent'] = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

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
        'campaigns'     => $playoffCampaigns,
    ],
    'regular'  => [
        'top1' => $top1Regular,
        'top4' => $top4Regular,
        'top8' => $top8Regular,
    ],
    'positions' => $positionsByYear,
    'cap_summary' => $capSummary,
    'punishments' => $punishments,
    'best_roster' => $bestRoster,
    'trades_by_cycle' => $tradesByCycle,
    'trades_by_partner' => $tradesByPartner,
    'retired_legends' => $retiredLegends,
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
