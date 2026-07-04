<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/rise_history.php';
requireAuth();
$user = getUserSession();
$pdo2 = db();
$pdo = db();

$leagues = ['ELITE','NEXT','RISE'];
$riseHistory = loadRiseHistoryStats();

// Time e dados do usuário logado
$myTeamName = '';
$myTeamShortName = '';
$myTeamLeague = $user['league'] ?? '';
$myTeam = null;
try {
    $stmtMy = $pdo->prepare("SELECT * FROM teams WHERE user_id = ? LIMIT 1");
    $stmtMy->execute([$user['id']]);
    $myTeam = $stmtMy->fetch(PDO::FETCH_ASSOC);
    $myTeamName = $myTeam ? ($myTeam['city'] . ' ' . $myTeam['name']) : '';
    $myTeamShortName = $myTeam ? $myTeam['name'] : '';
    $myTeamLeague = $myTeam['league'] ?? $myTeamLeague;
} catch (Exception) {}

function queryByLeague(PDO $pdo, string $sql, array $params = []): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $lg = $r['league'] ?? 'ALL';
            $out[$lg][] = $r;
        }
        return $out;
    } catch (Exception) { return []; }
}

function sortLeagueData(array &$map, string $key = 'count', bool $desc = true): void {
    foreach ($map as &$arr) {
        usort($arr, fn($a,$b) => $desc ? $b[$key] - $a[$key] : $a[$key] - $b[$key]);
    }
}

// ── 3. Mais aparições no playoff ─────────────────────────────────
$playoffMap = queryByLeague($pdo, "
    SELECT t.league, CONCAT(t.city,' ',t.name) AS name, COUNT(DISTINCT tsp.season_id) AS count
    FROM teams t
    LEFT JOIN team_season_points tsp ON tsp.team_id=t.id AND tsp.points>=3 AND tsp.league COLLATE utf8mb4_unicode_ci=t.league COLLATE utf8mb4_unicode_ci
    GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
");
sortLeagueData($playoffMap);

// ── 5. Elenco mais jovem ─────────────────────────────────────────
$youngMap = queryByLeague($pdo, "
    SELECT t.league, CONCAT(t.city,' ',t.name) AS name,
           ROUND(AVG(p.age),1) AS count
    FROM teams t
    JOIN players p ON p.team_id=t.id AND p.age > 0
    GROUP BY t.id, t.league, t.city, t.name ORDER BY count ASC
");
foreach ($youngMap as &$arr) usort($arr, fn($a,$b) => $a['count'] <=> $b['count']);
unset($arr);

// ── 10. Elenco mais velho ────────────────────────────────────────
$oldMap = [];
foreach ($youngMap as $lg => $arr) {
    $oldMap[$lg] = array_reverse($arr);
}

$loginsMap  = [];
$fbaPtsMap  = [];

// ── 14. Mais jogadores draftados ─────────────────────────────────
$draftedMap = queryByLeague($pdo, "
    SELECT t.league, CONCAT(t.city,' ',t.name) AS name, COUNT(p.id) AS count
    FROM teams t LEFT JOIN players p ON p.drafted_by_team_id=t.id
    GROUP BY t.id, t.league, t.city, t.name ORDER BY count DESC
");
sortLeagueData($draftedMap);

// ── 15. Mais jogadores que passaram pelo clube ───────────────────
$rotMap = queryByLeague($pdo, "
    SELECT t.league, CONCAT(t.city,' ',t.name) AS name, COUNT(DISTINCT psl.player_id) AS count
    FROM teams t
    LEFT JOIN player_season_log psl ON psl.team_id=t.id AND psl.league COLLATE utf8mb4_unicode_ci=t.league COLLATE utf8mb4_unicode_ci
    GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
");
sortLeagueData($rotMap);

// ── FA pickups (inclui times com 0 contratações) ─────────────────
$faMap = queryByLeague($pdo, "
    SELECT t.league, CONCAT(t.city,' ',t.name) AS name,
           COUNT(far.id) AS count
    FROM teams t
    LEFT JOIN fa_requests far ON far.winner_team_id = t.id AND far.status = 'assigned'
    GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
");
sortLeagueData($faMap);


// ── Pick origem no top5 (draft_order, original_team_id, pos <= 5) ─
$origTop5Map = [];
try {
    $ot5Raw = $pdo->query("
        SELECT ds.league, CONCAT(t.city,' ',t.name) AS name, COUNT(*) AS count
        FROM draft_order do_
        JOIN draft_sessions ds ON ds.id=do_.draft_session_id
        JOIN teams t ON t.id=do_.original_team_id
        WHERE do_.pick_position <= 5 AND do_.round = 1 AND do_.picked_player_id IS NOT NULL
        GROUP BY ds.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ot5Raw as $r) $origTop5Map[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    // Correção: 1 pick atribuída ao Utah Coyotes pertencia ao St. Louis Musketeers
    foreach ($origTop5Map as $lg => &$arr) {
        $hasMusk = false;
        foreach ($arr as &$row) {
            if (str_contains($row['name'], 'Coyotes') && str_contains($row['name'], 'Utah')) $row['count'] = max(0, $row['count'] - 1);
            if (str_contains($row['name'], 'Musketeers') || str_contains($row['name'], 'Musketters')) { $row['count']++; $hasMusk = true; }
        } unset($row);
        if (!$hasMusk) $arr[] = ['name' => 'St. Louis Musketeers', 'count' => 1];
    } unset($arr);
    foreach ($origTop5Map as &$arr) {
        usort($arr, fn($a,$b) => $b['count'] !== $a['count'] ? $b['count'] - $a['count']
            : ((str_contains($a['name'],'Coyotes') && str_contains($a['name'],'Utah')) ? 1
            : ((str_contains($b['name'],'Coyotes') && str_contains($b['name'],'Utah')) ? -1 : 0)));
    } unset($arr);
} catch (Exception) {}

// ── Quem mais escolheu no top5 (draft_order, team_id, pos <= 5) ───
$top5PicksMap = [];
try {
    $tp5Raw = $pdo->query("
        SELECT ds.league, CONCAT(t.city,' ',t.name) AS name, COUNT(*) AS count
        FROM draft_order do_
        JOIN draft_sessions ds ON ds.id=do_.draft_session_id
        JOIN teams t ON t.id=do_.team_id
        WHERE do_.pick_position <= 5 AND do_.round = 1 AND do_.picked_player_id IS NOT NULL
        GROUP BY ds.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tp5Raw as $r) $top5PicksMap[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    // Correção: 1 escolha atribuída ao Utah Coyotes pertencia ao St. Louis Musketeers
    foreach ($top5PicksMap as $lg => &$arr) {
        $hasMusk = false;
        foreach ($arr as &$row) {
            if (str_contains($row['name'], 'Coyotes') && str_contains($row['name'], 'Utah')) $row['count'] = max(0, $row['count'] - 1);
            if (str_contains($row['name'], 'Musketeers') || str_contains($row['name'], 'Musketters')) { $row['count']++; $hasMusk = true; }
        } unset($row);
        if (!$hasMusk) $arr[] = ['name' => 'St. Louis Musketeers', 'count' => 1];
    } unset($arr);
    sortLeagueData($top5PicksMap);
} catch (Exception) {}

// ── Times que nunca escolheram no top5 do draft ──────────────────
$neverTop5Map = [];
try {
    $nt5Raw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name
        FROM teams t
        WHERE t.id NOT IN (
            SELECT DISTINCT do_.team_id
            FROM draft_order do_
            JOIN draft_sessions ds ON ds.id=do_.draft_session_id
            WHERE do_.pick_position <= 5 AND do_.round = 1 AND do_.picked_player_id IS NOT NULL
        )
        ORDER BY t.league, t.city, t.name
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($nt5Raw as $r) $neverTop5Map[$r['league']][] = $r['name'];
} catch (Exception) {}



// ── Mais playoff consecutivos (streak em PHP) ────────────────────
$streakMap = [];
try {
    $psRows = $pdo->query("
        SELECT tsp.league, tsp.team_id, CONCAT(t.city,' ',t.name) AS name,
               s.season_number, tsp.points
        FROM team_season_points tsp
        JOIN teams t ON t.id=tsp.team_id
        JOIN seasons s ON s.id=tsp.season_id
        ORDER BY tsp.league, tsp.team_id, s.season_number ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $byTeam = [];
    foreach ($psRows as $r) {
        $byTeam[$r['league']][$r['team_id']]['name'] = $r['name'];
        $byTeam[$r['league']][$r['team_id']]['pts'][$r['season_number']] = (int)$r['points'];
    }
    // Include teams with 0 playoffs
    $allT = $pdo->query("SELECT id, league, CONCAT(city,' ',name) AS nm FROM teams")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allT as $t) {
        if (!isset($byTeam[$t['league']][$t['id']])) {
            $byTeam[$t['league']][$t['id']] = ['name'=>$t['nm'],'pts'=>[]];
        }
    }
    foreach ($byTeam as $lg => $teams) {
        foreach ($teams as $tid => $data) {
            $pts = $data['pts']; ksort($pts);
            $maxStreak = 0; $cur = 0;
            foreach ($pts as $p) { if ($p >= 3) { $cur++; $maxStreak = max($maxStreak, $cur); } else $cur = 0; }
            $streakMap[$lg][] = ['name'=>$data['name'],'count'=>$maxStreak];
        }
    }
    sortLeagueData($streakMap);
} catch (Exception) {}

// ── Jogadores que passaram por mais times ─────────────────────────
$playerTeamsMap = [];
try {
    $ptRaw = $pdo->query("
        SELECT psl.league, psl.player_name AS name, COUNT(DISTINCT psl.team_id) AS count,
               pt.name AS team
        FROM player_season_log psl
        LEFT JOIN players p ON p.name = psl.player_name
        LEFT JOIN teams pt ON pt.id = p.team_id
        GROUP BY psl.league, psl.player_name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ptRaw as $r) $playerTeamsMap[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count'],'team'=>$r['team'] ?? ''];
    sortLeagueData($playerTeamsMap);
} catch (Exception) {}

// ── Retenção: média de temporadas por jogador no mesmo time ───────
$retencaoMap = [];
try {
    $retRaw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name,
               ROUND(AVG(sub.seasons), 1) AS count
        FROM teams t
        LEFT JOIN (
            SELECT team_id, player_id, COUNT(*) AS seasons
            FROM player_season_log
            WHERE ovr >= 78
            GROUP BY team_id, player_id
        ) sub ON sub.team_id=t.id
        GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($retRaw as $r) $retencaoMap[$r['league']][] = ['name'=>$r['name'],'count'=>(float)$r['count']];
    sortLeagueData($retencaoMap);
} catch (Exception) {}

// ── Aproveitamento do draft (OVR médio dos jogadores draftados) ───
$draftOvrMap = [];
try {
    $doRaw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name,
               ROUND(AVG(p.ovr), 1) AS count
        FROM teams t LEFT JOIN players p ON p.drafted_by_team_id=t.id AND p.ovr > 0
        GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($doRaw as $r) $draftOvrMap[$r['league']][] = ['name'=>$r['name'],'count'=>(float)$r['count']];
    sortLeagueData($draftOvrMap);
} catch (Exception) {}


// ── Times que mais tomaram punições ──────────────────────────────
$punicoesMap = [];
try {
    $punRaw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name, COUNT(tp.id) AS count
        FROM teams t
        LEFT JOIN team_punishments tp ON tp.team_id = t.id AND tp.reverted_at IS NULL
        GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($punRaw as $r) $punicoesMap[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    sortLeagueData($punicoesMap);
} catch (Exception) {}

// ── Maior jejum (sequência de temporadas sem playoff) ────────────
$jejumMap = [];
try {
    $jejRows = $pdo->query("
        SELECT tsp.league, tsp.team_id, CONCAT(t.city,' ',t.name) AS name,
               s.season_number, tsp.points
        FROM team_season_points tsp
        JOIN teams t ON t.id=tsp.team_id
        JOIN seasons s ON s.id=tsp.season_id
        ORDER BY tsp.league, tsp.team_id, s.season_number ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $byTeamJ = [];
    foreach ($jejRows as $r) {
        $byTeamJ[$r['league']][$r['team_id']]['name'] = $r['name'];
        $byTeamJ[$r['league']][$r['team_id']]['pts'][$r['season_number']] = (int)$r['points'];
    }
    $allTJ = $pdo->query("SELECT id, league, CONCAT(city,' ',name) AS nm FROM teams")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allTJ as $t) {
        if (!isset($byTeamJ[$t['league']][$t['id']])) {
            $byTeamJ[$t['league']][$t['id']] = ['name'=>$t['nm'],'pts'=>[]];
        }
    }
    foreach ($byTeamJ as $lg => $teams) {
        foreach ($teams as $tid => $data) {
            $pts = $data['pts']; ksort($pts);
            $maxJejum = 0; $cur = 0;
            foreach ($pts as $p) { if ($p < 3) { $cur++; $maxJejum = max($maxJejum, $cur); } else $cur = 0; }
            $jejumMap[$lg][] = ['name'=>$data['name'],'count'=>$maxJejum];
        }
    }
    sortLeagueData($jejumMap);
} catch (Exception) {}

// ── Pares direcionais: quem mais ofereceu para quem ─────────────
$direcionalMap = [];
try {
    $dirRaw = $pdo->query("
        SELECT t1.league,
               CONCAT(t1.city,' ',t1.name) AS a_long, t1.name AS a,
               CONCAT(t2.city,' ',t2.name) AS b_long, t2.name AS b,
               COUNT(*) AS count
        FROM trades tr
        JOIN teams t1 ON t1.id = tr.from_team_id
        JOIN teams t2 ON t2.id = tr.to_team_id
        WHERE tr.status = 'accepted'
        GROUP BY t1.league, t1.id, t2.id
        ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dirRaw as $r) {
        $count = (int)$r['count'];
        // Correção: distribui −220 do Utah Coyotes proporcionalmente entre os destinos
        // (omitida aqui — ver seção "Mais Ofertas" para total corrigido)
        $direcionalMap[$r['league']][] = [
            'a'=>$r['a'], 'b'=>$r['b'],
            'a_long'=>$r['a_long'], 'b_long'=>$r['b_long'],
            'count'=>$count, 'name'=>$r['a_long'].' → '.$r['b_long']
        ];
    }
    sortLeagueData($direcionalMap);
} catch (Exception) {}

// ── Pares de times que mais fizeram trade entre si ───────────────
$pairsMap = [];
try {
    $prRaw = $pdo->query("
        SELECT t1.league,
               CONCAT(t1.city,' ',t1.name) AS a_long, t1.name AS a,
               CONCAT(t2.city,' ',t2.name) AS b_long, t2.name AS b,
               COUNT(*) AS count
        FROM trades tr
        JOIN teams t1 ON t1.id = LEAST(tr.from_team_id, tr.to_team_id)
        JOIN teams t2 ON t2.id = GREATEST(tr.from_team_id, tr.to_team_id)
        WHERE tr.status = 'accepted' AND tr.from_team_id <> tr.to_team_id
        GROUP BY t1.league, t1.id, t2.id ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($prRaw as $r) $pairsMap[$r['league']][] = ['a'=>$r['a'],'b'=>$r['b'],'a_long'=>$r['a_long'],'b_long'=>$r['b_long'],'count'=>(int)$r['count'],'name'=>$r['a_long'].' × '.$r['b_long']];
    sortLeagueData($pairsMap);
} catch (Exception) {}

// ── Times com mais parceiros distintos de trade ───────────────────
$parceirosMap = [];
try {
    $pcRaw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name,
               COUNT(DISTINCT CASE WHEN tr.from_team_id=t.id THEN tr.to_team_id ELSE tr.from_team_id END) AS count
        FROM teams t
        LEFT JOIN trades tr ON (tr.from_team_id=t.id OR tr.to_team_id=t.id) AND tr.status='accepted'
        GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pcRaw as $r) $parceirosMap[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    sortLeagueData($parceirosMap);
} catch (Exception) {}

// ── Maior número de ofertas de trade enviadas ────────────────────
$ofertasMap = [];
try {
    $ofRaw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name, COUNT(tr.id) AS count
        FROM teams t
        LEFT JOIN trades tr ON tr.from_team_id = t.id
        GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ofRaw as $r) $ofertasMap[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    foreach ($ofertasMap as $lg => &$arr) {
        foreach ($arr as &$row) {
            if (str_contains($row['name'],'Coyotes') && str_contains($row['name'],'Utah'))
                $row['count'] = max(0, $row['count'] - 220);
        } unset($row);
        usort($arr, fn($a,$b) => $b['count'] <=> $a['count']);
    } unset($arr);
} catch (Exception) {}

// ── Trades aceitas ─────────────────────────────────────────────────
$tradesAceitasMap = [];
try {
    $taRaw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name, COUNT(tr.id) AS count
        FROM teams t
        LEFT JOIN trades tr ON (tr.from_team_id=t.id OR tr.to_team_id=t.id) AND tr.status='accepted'
        GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($taRaw as $r) $tradesAceitasMap[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    sortLeagueData($tradesAceitasMap);
} catch (Exception) {}

// ── Trades recusadas ───────────────────────────────────────────────
$tradesRecusadasMap = [];
try {
    $trRaw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name, COUNT(tr.id) AS count
        FROM teams t
        LEFT JOIN trades tr ON (tr.from_team_id=t.id OR tr.to_team_id=t.id) AND tr.status='rejected'
        GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($trRaw as $r) $tradesRecusadasMap[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    sortLeagueData($tradesRecusadasMap);
} catch (Exception) {}

// ── Trades revertidas (desfeitas por admin) ─────────────────────────
$tradesRevertidasMap = [];
try {
    $trvRaw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name, COUNT(tr.id) AS count
        FROM teams t
        LEFT JOIN trades tr ON (tr.from_team_id=t.id OR tr.to_team_id=t.id) AND tr.status='cancelled' AND tr.notes LIKE '%revertida%'
        GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($trvRaw as $r) $tradesRevertidasMap[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    sortLeagueData($tradesRevertidasMap);
} catch (Exception) {}



?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Estatísticas · FBA</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:rgba(252,0,37,.10);--border-red:rgba(252,0,37,.25);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1e1e24;--border:rgba(255,255,255,.07);--border-md:rgba(255,255,255,.12);--text:#f0f0f3;--text-2:#868690;--text-3:#48484f;--amber:#f59e0b;--green:#22c55e;--purple:#a855f7;--blue:#60a5fa;--radius:14px;--radius-sm:10px;--font:'Poppins',sans-serif;--sidebar-w:260px;--t:.2s;--ease:cubic-bezier(.4,0,.2,1)}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

/* ── Sidebar ── */
.sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:300;transition:transform var(--t) var(--ease);overflow-y:auto;scrollbar-width:none}
.sidebar::-webkit-scrollbar{display:none}
.sb-brand{padding:22px 18px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-shrink:0}
.sb-logo{width:34px;height:34px;border-radius:9px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;color:#fff;flex-shrink:0}
.sb-brand-text{font-weight:700;font-size:15px;line-height:1.1}
.sb-brand-text span{display:block;font-size:11px;font-weight:400;color:var(--text-2)}
.sb-team{margin:14px 14px 0;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-team img{width:40px;height:40px;border-radius:9px;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-team-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.2}
.sb-team-league{font-size:11px;color:var(--red);font-weight:600}
.sb-nav{flex:1;padding:12px 10px 8px}
.sb-section{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:12px 10px 5px}
.sb-nav a{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t) var(--ease)}
.sb-nav a i{font-size:15px;width:18px;text-align:center;flex-shrink:0}
.sb-nav a:hover{background:var(--panel-2);color:var(--text)}
.sb-nav a.active{background:var(--red-soft);color:var(--red);font-weight:600}
.sb-nav a.active i{color:var(--red)}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0}
.sb-username{font-size:12px;font-weight:500;color:var(--text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-logout{width:26px;height:26px;border-radius:7px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;transition:all var(--t) var(--ease);text-decoration:none;flex-shrink:0}
.sb-logout:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}

/* ── Mobile topbar ── */
.topbar{display:none;position:fixed;top:0;left:0;right:0;height:54px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 16px;gap:12px;z-index:240}
.topbar-title{font-weight:700;font-size:15px;flex:1}
.topbar-title em{color:var(--red);font-style:normal}
.menu-btn{width:34px;height:34px;border-radius:9px;background:var(--panel-2);border:1px solid var(--border);color:var(--text);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:17px}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:250}
.sb-overlay.show{display:block}

/* ── Main wrapper ── */
.main{margin-left:var(--sidebar-w);min-height:100vh;width:calc(100% - var(--sidebar-w))}
.main-inner{max-width:1200px;margin:0 auto;padding:28px 24px 80px}
.page-title{font-family:'Oswald',sans-serif;font-size:24px;font-weight:700;margin-bottom:20px;display:flex;align-items:center;gap:10px}
.page-title i{color:var(--red)}

/* League tabs */
.league-tabs{position:sticky;top:0;z-index:90;display:flex;justify-content:center;gap:8px;flex-wrap:wrap;background:var(--bg);padding:12px 0 16px;margin-bottom:8px;border-bottom:1px solid var(--border)}
.league-tab{font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;letter-spacing:.5px;padding:8px 18px;border-radius:999px;border:1px solid var(--border-md);background:var(--panel-2);color:var(--text-2);cursor:pointer;transition:all var(--t) var(--ease)}
.league-tab:hover{color:var(--text);border-color:var(--text-2)}
.league-tab.active{background:var(--red);border-color:var(--red);color:#fff}
.league-tab.active[data-league="ELITE"]{background:#fbbf24;border-color:#fbbf24;color:#1a1305}
.league-tab.active[data-league="NEXT"]{background:#818cf8;border-color:#818cf8;color:#0c0d1f}
.league-tab.active[data-league="RISE"]{background:#4ade80;border-color:#4ade80;color:#062812}
@media(max-width:992px){.league-tabs{top:54px}}

@media(max-width:992px){
  :root{--sidebar-w:0px}
  .sidebar{transform:translateX(-260px)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0;width:100%;padding-top:54px}
  .topbar{display:flex}
}

/* Sections flow — cards from different stats sit side by side */
.stats-flow{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:20px;align-items:start;margin-top:8px}
/* Agrupa por altura de card: só top5 primeiro, top5+bot5 depois — evita linhas com alturas misturadas */
.section-block[data-size="short"]{order:1}
.stats-flow-break{order:2;grid-column:1/-1;height:0;margin:0;padding:0}
.section-block[data-size="tall"]{order:3}

/* Section headers */
.section-block{margin-bottom:0}
.section-head{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--border)}
.section-head h2{font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;color:var(--text)}
.section-head .section-icon{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.section-sub{font-size:11px;color:var(--text);margin-top:2px}

/* Grid — sempre 1 card por vez (filtrado por liga) */
.leagues-grid{display:block}

/* Card */
.league-card{background:var(--panel);border:1px solid var(--border);border-radius:12px;overflow:hidden;display:flex;flex-direction:column}
.league-header{padding:10px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-shrink:0}
.league-badge{font-family:'Oswald',sans-serif;font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px;letter-spacing:.5px;flex-shrink:0}
.badge-ELITE{background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.25)}
.badge-NEXT{background:rgba(99,102,241,.12);color:#818cf8;border:1px solid rgba(99,102,241,.25)}
.badge-RISE{background:rgba(34,197,94,.12);color:#4ade80;border:1px solid rgba(34,197,94,.25)}
.badge-ROOKIE{background:rgba(168,85,247,.12);color:#c084fc;border:1px solid rgba(168,85,247,.25)}
.copy-btn{margin-left:auto;background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-3);border-radius:7px;padding:3px 9px;font-size:10px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:4px;transition:all .2s;white-space:nowrap;flex-shrink:0}
.copy-btn:hover{border-color:var(--red);color:var(--red)}

/* Sub-section label inside card */
.card-sub{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-3);padding:8px 14px 4px;display:flex;align-items:center;gap:5px}

/* Rank rows */
.rank-row{display:flex;align-items:center;gap:8px;padding:6px 14px;border-bottom:1px solid var(--border)}
.rank-row:last-child{border-bottom:none}
.rn{width:16px;font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;color:var(--text-3);flex-shrink:0;text-align:right}
.rn.gold{color:var(--amber)}
.rname{flex:1;font-size:11px;font-weight:500;color:var(--text);min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rteam{color:var(--text-2);font-weight:400}
.rval{font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;flex-shrink:0}
.rval.hi{color:var(--red)}
.rval.lo{color:var(--text-3)}
.rval.gold{color:var(--amber)}
.rval.green{color:var(--green)}
.rval.blue{color:var(--blue)}
.rval.purple{color:var(--purple)}
.divider{height:1px;background:var(--border);margin:2px 0}
.rank-row.my-team{background:rgba(252,0,37,.10);border-left:3px solid var(--red)}
.rank-row.my-team .rname{color:#fff;font-weight:700}
.rank-row.my-team .rn{color:var(--red)}
.my-team-sep{height:1px;background:rgba(252,0,37,.2);margin:2px 0}
.my-team-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--red);padding:5px 14px 2px}

/* Pair rows */
.pair-row{display:flex;align-items:center;gap:8px;padding:6px 14px;border-bottom:1px solid var(--border)}
.pair-row:last-child{border-bottom:none}
.pair-names{flex:1;min-width:0;display:flex;flex-direction:column;gap:1px}
.pair-a{font-size:11px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pair-b{font-size:11px;font-weight:500;color:var(--text-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pair-row.my-team{background:rgba(252,0,37,.10);border-left:3px solid var(--red)}
.pair-row.my-team .pair-a{color:#fff;font-weight:700}
.pair-row.my-team .rn{color:var(--red)}

.empty-state{padding:16px 14px;font-size:11px;color:var(--text-3);text-align:center}
</style>
</head>
<body>

<aside class="sidebar" id="sidebar">
  <div class="sb-brand">
    <div class="sb-logo">FBA</div>
    <div class="sb-brand-text">FBA Manager<span>Liga <?= htmlspecialchars($user['league'] ?? '') ?></span></div>
  </div>
  <?php if ($myTeam): ?>
  <div class="sb-team">
    <img src="<?= htmlspecialchars($myTeam['photo_url'] ?? '/img/default-team.png') ?>" alt="" onerror="this.src='/img/default-team.png'">
    <div>
      <div class="sb-team-name"><?= htmlspecialchars($myTeamName) ?></div>
      <div class="sb-team-league"><?= htmlspecialchars($user['league'] ?? '') ?></div>
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
    <a href="/mundo-fba.php"><i class="bi bi-globe2"></i> Mundo FBA</a>
    <a href="/estatisticas.php" class="active"><i class="bi bi-bar-chart-line-fill"></i> Estatísticas</a>
    <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
    <a href="https://games.fbabrasil.com.br/auth/login.php" target="_blank" rel="noopener"><i class="bi bi-controller"></i> FBA Games</a>
    <a href="/thepathetic.php"><i class="bi bi-newspaper"></i> The Pathetic</a>
    <?php if (hasAdminAccess($pdo, (int)$user['id'])): ?>
    <div class="sb-section">Admin</div>
    <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
    <?php endif; ?>
    <div class="sb-section">Conta</div>
    <a href="/settings.php"><i class="bi bi-gear-fill"></i> Minha Conta</a>
    <a href="/team-public-page.php"><i class="bi bi-globe2"></i> Página do Time</a>
  </nav>
  <div class="sb-footer">
    <img src="<?= htmlspecialchars(getUserPhoto($user['photo_url'] ?? null)) ?>"
         alt="<?= htmlspecialchars($user['name']) ?>"
         class="sb-avatar"
         onerror="this.src='https://ui-avatars.com/api/?name=<?= rawurlencode($user['name']) ?>&background=1c1c21&color=fc0025'">
    <span class="sb-username"><?= htmlspecialchars($user['name']) ?></span>
    <a href="/logout.php" class="sb-logout" title="Sair"><i class="bi bi-box-arrow-right"></i></a>
  </div>
</aside>

<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
  <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
  <div class="topbar-title">FBA <em>Estatísticas</em></div>
</header>

<div class="main">
<div class="main-inner">
<div class="page-title"><i class="bi bi-bar-chart-line-fill"></i> Estatísticas</div>
<div class="league-tabs" id="leagueTabs" data-default-league="<?= htmlspecialchars($myTeamLeague ?: $leagues[0]) ?>">
  <?php foreach ($leagues as $lg): ?>
  <button class="league-tab<?= $lg === $myTeamLeague ? ' active' : '' ?>" data-league="<?= htmlspecialchars($lg) ?>"><?= htmlspecialchars($lg) ?></button>
  <?php endforeach; ?>
</div>
<?php

// ─────────────────────────────────────────────────────────────────
// Helper: renders a 4-league grid with top5/bot5 rank rows
// $data = ['ELITE'=>[['name'=>...,'count'=>...], ...], ...]
// $opts = [ label_hi, label_lo, color_hi, label_copy_hi, label_copy_lo, suffix, reverse_bot ]
function renderSection(string $id, string $icon, string $icon_bg, string $title, string $subtitle,
                       array $data, array $leagues, array $opts = [], string $myTeam = ''): void {
    $label_hi     = $opts['label_hi']     ?? '🔥 Mais';
    $label_lo     = $opts['label_lo']     ?? '🧊 Menos';
    $color_hi     = $opts['color_hi']     ?? 'hi';
    $color_lo     = $opts['color_lo']     ?? 'lo';
    $copy_hi      = $opts['copy_hi']      ?? $title.' — Mais';
    $copy_lo      = $opts['copy_lo']      ?? $title.' — Menos';
    $suffix       = $opts['suffix']       ?? '';
    $show_lo      = $opts['show_lo']      ?? true;
    $pair_mode    = $opts['pair_mode']    ?? false;
    $pair_sep     = $opts['pair_sep']     ?? '×';
    $only_league  = $opts['only_league']  ?? '';

    $onlyAttr = $only_league !== '' ? " data-only-league=\"{$only_league}\"" : '';
    $sizeGroup = $show_lo ? 'tall' : 'short';
    echo "<div class=\"section-block\" id=\"{$id}\" data-size=\"{$sizeGroup}\"{$onlyAttr}>";
    echo "<div class=\"section-head\">";
    echo "<div class=\"section-icon\" style=\"background:{$icon_bg}\">{$icon}</div>";
    echo "<div><h2>{$title}</h2><div class=\"section-sub\">{$subtitle}</div></div>";
    echo "</div>";
    echo "<div class=\"leagues-grid\">";

    global $myTeamLeague;
    foreach ($leagues as $lg) {
        // Só destaca "seu time" na liga em que o time realmente joga (evita colisão de nomes entre ligas)
        $myTeamActive = ($lg === $myTeamLeague) ? $myTeam : '';
        $arr  = $data[$lg] ?? [];
        $top5 = array_slice($arr, 0, 5);
        $bot5 = array_reverse(array_slice(array_reverse($arr), 0, 5));

        // Build copy text
        $cp  = "🏀 *{$copy_hi} — {$lg}*\n";
        foreach ($top5 as $i => $r) {
            $line = $pair_mode ? "{$r['a_long']} {$pair_sep} {$r['b_long']}" : $r['name'];
            $cp .= ($i+1).". {$line} — {$r['count']}{$suffix}\n";
        }
        if ($show_lo) {
            $cp .= "\n*{$copy_lo} — {$lg}*\n";
            foreach ($bot5 as $i => $r) {
                $line = $pair_mode ? "{$r['a_long']} {$pair_sep} {$r['b_long']}" : $r['name'];
                $cp .= ($i+1).". {$line} — {$r['count']}{$suffix}\n";
            }
        }
        $cpEsc = htmlspecialchars($cp, ENT_QUOTES);

        echo "<div class=\"league-card\" data-league=\"{$lg}\">";
        echo "<div class=\"league-header\">";
        echo "<span class=\"league-badge badge-{$lg}\">{$lg}</span>";
        echo "<span style=\"font-size:11px;color:var(--text-3);flex:1\">".count($arr)." registros</span>";
        echo "<button class=\"copy-btn\" data-text=\"{$cpEsc}\"><i class=\"bi bi-clipboard\"></i> Copiar</button>";
        echo "</div>";

        // Find myTeam position in full array (1-indexed)
        $isPlayerSection = !$pair_mode && !empty($arr) && isset($arr[0]['team']);
        $myPos = 0;
        if ($myTeamActive !== '') {
            foreach ($arr as $idx => $r) {
                if ($pair_mode) {
                    if (($r['a_long'] ?? null) === $myTeamActive || ($r['b_long'] ?? null) === $myTeamActive) { $myPos = $idx + 1; break; }
                } elseif ($isPlayerSection) {
                    if (!empty($r['team']) && $r['team'] === $myTeamActive) { $myPos = $idx + 1; break; }
                } else {
                    if ($r['name'] === $myTeamActive) { $myPos = $idx + 1; break; }
                }
            }
        }
        $myInTop5 = $myPos > 0 && $myPos <= 5;

        echo "<div class=\"card-sub\">{$label_hi}</div>";
        if (empty($top5)) {
            echo "<div class=\"empty-state\">Sem dados</div>";
        } else {
            foreach ($top5 as $i => $r) {
                $isMyTeam = $myTeamActive !== '' && ($pair_mode
                    ? (($r['a_long'] ?? null) === $myTeamActive || ($r['b_long'] ?? null) === $myTeamActive)
                    : ($isPlayerSection ? (!empty($r['team']) && $r['team'] === $myTeamActive) : $r['name'] === $myTeamActive));
                if ($pair_mode) {
                    echo "<div class=\"pair-row".($isMyTeam ? ' my-team' : '')."\">";
                    echo "<span class=\"rn ".($i===0?'gold':'')."\">" . ($i+1) . "</span>";
                    echo "<div class=\"pair-names\">";
                    echo "<span class=\"pair-a\" title=\"".htmlspecialchars($r['a_long'])."\">" . htmlspecialchars($r['a']) . "</span>";
                    echo "<span class=\"pair-b\">{$pair_sep} " . htmlspecialchars($r['b']) . "</span>";
                    echo "</div>";
                    echo "<span class=\"rval {$color_hi}\">" . $r['count'] . $suffix . "</span>";
                    echo "</div>";
                } else {
                    $cls = $isMyTeam ? ' my-team' : '';
                    echo "<div class=\"rank-row{$cls}\">";
                    echo "<span class=\"rn ".($i===0?'gold':'')."\">" . ($i+1) . "</span>";
                    if (!empty($r['team'])) {
                        echo "<span class=\"rname\" title=\"".htmlspecialchars($r['name'])."\">" . htmlspecialchars($r['name']) . " <span class=\"rteam\">- " . htmlspecialchars($r['team']) . "</span></span>";
                    } else {
                        echo "<span class=\"rname\" title=\"".htmlspecialchars($r['name'])."\">" . htmlspecialchars($r['name']) . "</span>";
                    }
                    echo "<span class=\"rval {$color_hi}\">" . $r['count'] . $suffix . "</span>";
                    echo "</div>";
                }
            }
            // Show myTeam outside top5
            if ($myPos > 0 && !$myInTop5) {
                $myRow = $arr[$myPos - 1];
                echo "<div class=\"my-team-sep\"></div>";
                if ($pair_mode) {
                    echo "<div class=\"my-team-label\">Seu time</div>";
                    $aLong = $myRow['a_long']; $bLong = $myRow['b_long'];
                    $aShort = $myRow['a']; $bShort = $myRow['b'];
                    if ($aLong !== $myTeamActive) {
                        [$aLong, $bLong] = [$bLong, $aLong];
                        [$aShort, $bShort] = [$bShort, $aShort];
                    }
                    echo "<div class=\"pair-row my-team\">";
                    echo "<span class=\"rn\">{$myPos}</span>";
                    echo "<div class=\"pair-names\">";
                    echo "<span class=\"pair-a\" title=\"".htmlspecialchars($aLong)."\">" . htmlspecialchars($aShort) . "</span>";
                    echo "<span class=\"pair-b\">{$pair_sep} " . htmlspecialchars($bShort) . "</span>";
                    echo "</div>";
                    echo "<span class=\"rval {$color_hi}\">" . $myRow['count'] . $suffix . "</span>";
                    echo "</div>";
                } else {
                    $myLabel = $isPlayerSection ? "Seu time — " . htmlspecialchars($myTeamActive) : "Seu time";
                    echo "<div class=\"my-team-label\">{$myLabel}</div>";
                    echo "<div class=\"rank-row my-team\">";
                    echo "<span class=\"rn\">{$myPos}</span>";
                    if (!empty($myRow['team'])) {
                        echo "<span class=\"rname\" title=\"".htmlspecialchars($myRow['name'])."\">" . htmlspecialchars($myRow['name']) . " <span class=\"rteam\">- " . htmlspecialchars($myRow['team']) . "</span></span>";
                    } else {
                        echo "<span class=\"rname\" title=\"".htmlspecialchars($myRow['name'])."\">" . htmlspecialchars($myRow['name']) . "</span>";
                    }
                    echo "<span class=\"rval {$color_hi}\">" . $myRow['count'] . $suffix . "</span>";
                    echo "</div>";
                }
            }
        }

        if ($show_lo) {
            echo "<div class=\"divider\"></div>";
            echo "<div class=\"card-sub\">{$label_lo}</div>";
            if (empty($bot5)) {
                echo "<div class=\"empty-state\">Sem dados</div>";
            } else {
                $bot5Full = array_reverse(array_slice(array_reverse($arr), 0, 5));
                $bot5Positions = range(count($arr) - count($bot5Full) + 1, count($arr));
                foreach ($bot5Full as $i => $r) {
                    $isMyTeam = $myTeamActive !== '' && ($pair_mode
                        ? (($r['a_long'] ?? null) === $myTeamActive || ($r['b_long'] ?? null) === $myTeamActive)
                        : ($isPlayerSection ? (!empty($r['team']) && $r['team'] === $myTeamActive) : $r['name'] === $myTeamActive));
                    if ($pair_mode) {
                        echo "<div class=\"pair-row".($isMyTeam ? ' my-team' : '')."\">";
                        echo "<span class=\"rn\">" . ($i+1) . "</span>";
                        echo "<div class=\"pair-names\">";
                        echo "<span class=\"pair-a\">" . htmlspecialchars($r['a']) . "</span>";
                        echo "<span class=\"pair-b\">{$pair_sep} " . htmlspecialchars($r['b']) . "</span>";
                        echo "</div>";
                        echo "<span class=\"rval {$color_lo}\">" . $r['count'] . $suffix . "</span>";
                        echo "</div>";
                    } else {
                        $cls = $isMyTeam ? ' my-team' : '';
                        $pos = $bot5Positions[$i];
                        echo "<div class=\"rank-row{$cls}\">";
                        echo "<span class=\"rn\">{$pos}</span>";
                        if (!empty($r['team'])) {
                            echo "<div class=\"rname-wrap\"><span class=\"rname\" title=\"".htmlspecialchars($r['name'])."\">" . htmlspecialchars($r['name']) . "</span><span class=\"rteam\">" . htmlspecialchars($r['team']) . "</span></div>";
                        } else {
                            echo "<span class=\"rname\" title=\"".htmlspecialchars($r['name'])."\">" . htmlspecialchars($r['name']) . "</span>";
                        }
                        echo "<span class=\"rval {$color_lo}\">" . $r['count'] . $suffix . "</span>";
                        echo "</div>";
                    }
                }
                // Se o time está no bot5, já aparece destacado acima; não duplicar
            }
        }

        echo "</div>"; // .league-card
    }

    echo "</div></div>"; // .leagues-grid .section-block
}

// ─── Render all sections ─────────────────────────────────────────
echo '<div class="stats-flow" id="statsFlow">';
echo '<div class="stats-flow-break"></div>';

renderSection('playoffs', '🎯', 'rgba(252,0,37,.12)', 'Aparições no Playoff',
    'Times que mais chegaram ao playoff',
    $playoffMap, $leagues, [
        'label_hi' => '🎯 Mais playoffs', 'label_lo' => '📉 Menos playoffs',
        'color_hi' => 'hi', 'color_lo' => 'lo',
        'copy_hi' => 'Mais playoffs', 'copy_lo' => 'Menos playoffs',
    ], $myTeamName);

renderSection('jovem', '🌱', 'rgba(168,85,247,.10)', 'Elenco Mais Jovem',
    'Idade média dos jogadores em contrato',
    $youngMap, $leagues, [
        'label_hi' => '🌱 Mais jovens', 'show_lo' => false,
        'color_hi' => 'purple',
        'copy_hi' => 'Elenco mais jovem',
        'suffix' => ' anos',
    ], $myTeamName);

renderSection('velho', '🧓', 'rgba(148,163,184,.08)', 'Elenco Mais Experiente',
    'Times com maior idade média',
    $oldMap, $leagues, [
        'label_hi' => '🧓 Mais experientes', 'show_lo' => false,
        'color_hi' => 'lo',
        'copy_hi' => 'Elenco mais experiente',
        'suffix' => ' anos',
    ], $myTeamName);

renderSection('draftados', '🎓', 'rgba(168,85,247,.10)', 'Jogadores Draftados',
    'Times que mais desenvolveram jogadores pelo draft',
    $draftedMap, $leagues, [
        'label_hi' => '🎓 Mais draftados', 'label_lo' => '📦 Menos draftados',
        'color_hi' => 'purple', 'color_lo' => 'lo',
        'copy_hi' => 'Mais jogadores draftados', 'copy_lo' => 'Menos jogadores draftados',
    ], $myTeamName);

renderSection('rotatividade', '🔁', 'rgba(34,197,94,.08)', 'Rotatividade de Elenco',
    'Quantidade de jogadores diferentes que passaram pelo clube',
    $rotMap, $leagues, [
        'label_hi' => '🔁 Mais rotatividade', 'label_lo' => '🏠 Menos rotatividade',
        'color_hi' => 'green', 'color_lo' => 'lo',
        'copy_hi' => 'Mais rotatividade', 'copy_lo' => 'Menos rotatividade',
    ], $myTeamName);


renderSection('fa', '🖊️', 'rgba(34,197,94,.10)', 'Free Agency',
    'Times que mais e menos assinaram jogadores na FA',
    $faMap, $leagues, [
        'label_hi' => '🖊️ Mais contratações', 'label_lo' => '📦 Menos contratações',
        'color_hi' => 'green', 'color_lo' => 'lo',
        'copy_hi' => 'Mais FA pickups', 'copy_lo' => 'Menos FA pickups',
    ], $myTeamName);


// ─── Novas seções ─────────────────────────────────────────────────


renderSection('streak', '🔥', 'rgba(251,191,36,.10)', 'Maior Sequência de Playoffs',
    'Máximo de temporadas consecutivas classificadas ao playoff',
    $streakMap, $leagues, [
        'label_hi' => '🔥 Maior sequência', 'label_lo' => '📦 Menor sequência',
        'color_hi' => 'gold', 'color_lo' => 'lo',
        'copy_hi' => 'Maior sequência de playoffs', 'copy_lo' => 'Menor sequência',
        'suffix' => ' temp',
    ], $myTeamName);

renderSection('player-teams', '🌍', 'rgba(96,165,250,.10)', 'Jogadores mais Itinerantes',
    'Jogadores que passaram por mais times diferentes',
    $playerTeamsMap, $leagues, [
        'label_hi' => '✈️ Mais times', 'show_lo' => false,
        'color_hi' => 'blue',
        'copy_hi' => 'Jogadores mais itinerantes',
        'suffix' => ' times',
    ], $myTeamShortName);

renderSection('retencao', '🏠', 'rgba(34,197,94,.10)', 'Retenção de Elenco',
    'Média de temporadas que cada jogador fica no mesmo time',
    $retencaoMap, $leagues, [
        'label_hi' => '🏠 Mais fiéis', 'label_lo' => '📤 Mais rotativos',
        'color_hi' => 'green', 'color_lo' => 'lo',
        'copy_hi' => 'Maior retenção', 'copy_lo' => 'Menor retenção',
        'suffix' => ' temp',
    ], $myTeamName);

renderSection('draft-ovr', '📈', 'rgba(168,85,247,.10)', 'Aproveitamento do Draft',
    'OVR médio dos jogadores draftados pelo time',
    $draftOvrMap, $leagues, [
        'label_hi' => '📈 Melhor aproveitamento', 'label_lo' => '📉 Menor aproveitamento',
        'color_hi' => 'purple', 'color_lo' => 'lo',
        'copy_hi' => 'Melhor aproveitamento do draft', 'copy_lo' => 'Menor aproveitamento do draft',
    ], $myTeamName);



renderSection('punicoes', '⚠️', 'rgba(252,0,37,.12)', 'Punições Recebidas',
    'Times que mais receberam punições ativas na liga',
    $punicoesMap, $leagues, [
        'label_hi' => '⚠️ Mais punições', 'label_lo' => '✅ Menos punições',
        'color_hi' => 'hi', 'color_lo' => 'green',
        'copy_hi' => 'Mais punições', 'copy_lo' => 'Menos punições',
    ], $myTeamName);

renderSection('orig-top5', '🎯', 'rgba(251,191,36,.12)', 'Pick Origem no Top 5',
    'Times cuja pick original foi usada no top 5 do draft',
    $origTop5Map, $leagues, [
        'label_hi' => '🎯 Mais vezes', 'show_lo' => false,
        'color_hi' => 'gold',
        'copy_hi' => 'Pick origem no top 5 do draft',
    ], $myTeamName);

renderSection('top5picks', '⭐', 'rgba(96,165,250,.12)', 'Mais Escolhas no Top 5',
    'Times que mais escolheram jogadores nas 5 primeiras posições',
    $top5PicksMap, $leagues, [
        'label_hi' => '⭐ Mais escolhas top 5', 'show_lo' => false,
        'color_hi' => 'blue',
        'copy_hi' => 'Mais escolhas no top 5 do draft',
    ], $myTeamName);

if (!empty(array_filter($neverTop5Map))) {
    echo '<div class="section-block" id="never-top5" data-size="short">';
    echo '<div class="section-head"><div class="section-icon" style="background:rgba(148,163,184,.10)">🚫</div>';
    echo '<div><h2>Nunca Escolheram no Top 5</h2><div class="section-sub">Times sem nenhuma escolha nas 5 primeiras posições do draft</div></div></div>';
    echo '<div class="leagues-grid">';
    foreach ($leagues as $lg) {
        $arr = $neverTop5Map[$lg] ?? [];
        $cp = "🚫 *Nunca escolheram no top 5 — {$lg}*\n";
        foreach ($arr as $nm) $cp .= "• {$nm}\n";
        $cpEsc = htmlspecialchars($cp, ENT_QUOTES);
        echo '<div class="league-card" data-league="'.htmlspecialchars($lg).'">';
        echo '<div class="league-header"><span class="league-badge badge-'.$lg.'">'.$lg.'</span>';
        echo '<span style="font-size:11px;color:var(--text-3);flex:1">'.count($arr).' time(s)</span>';
        echo '<button class="copy-btn" data-text="'.$cpEsc.'"><i class="bi bi-clipboard"></i> Copiar</button></div>';
        if (empty($arr)) {
            echo '<div class="empty-state">Todos já escolheram no top 5</div>';
        } else {
            foreach ($arr as $nm) {
                $isMe = ($lg === $myTeamLeague) && ($nm === $myTeamName);
                echo '<div class="rank-row'.($isMe ? ' my-team' : '').'"><span class="rname">'.htmlspecialchars($nm).'</span></div>';
            }
        }
        echo '</div>';
    }
    echo '</div></div>';
}



renderSection('jejum', '😴', 'rgba(148,163,184,.10)', 'Maior Jejum de Playoffs',
    'Maior sequência de temporadas consecutivas sem chegar ao playoff',
    $jejumMap, $leagues, [
        'label_hi' => '😴 Maior jejum', 'label_lo' => '✅ Menor jejum',
        'color_hi' => 'lo', 'color_lo' => 'green',
        'copy_hi' => 'Maior jejum de playoffs', 'copy_lo' => 'Menor jejum',
        'suffix' => ' temp',
    ], $myTeamName);

renderSection('trade-pairs', '🔄', 'rgba(96,165,250,.12)', 'Duplas que Mais Trocaram',
    'Pares de times com maior número de trades aceitas entre si',
    $pairsMap, $leagues, [
        'label_hi' => '🔄 Maiores parceiros', 'label_lo' => '🥶 Menos trocas',
        'color_hi' => 'blue', 'color_lo' => 'lo',
        'copy_hi' => 'Duplas que mais trocaram', 'copy_lo' => 'Duplas que menos trocaram',
        'pair_mode' => true, 'pair_sep' => '×',
    ], $myTeamName);

renderSection('trade-dir', '➡️', 'rgba(96,165,250,.10)', 'Trades Unidirecionais',
    'Pares onde um time enviou mais propostas para o outro (de → para)',
    $direcionalMap, $leagues, [
        'label_hi' => '📤 Mais unidirecionais', 'label_lo' => '📭 Menos',
        'color_hi' => 'blue', 'color_lo' => 'lo',
        'copy_hi' => 'Mais trades em uma direção', 'copy_lo' => 'Menos',
        'pair_mode' => true, 'pair_sep' => '→',
    ], $myTeamName);

renderSection('parceiros', '🌐', 'rgba(168,85,247,.10)', 'Diversidade de Parceiros de Trade',
    'Times que negociaram com mais (ou menos) franquias diferentes (só aceitas)',
    $parceirosMap, $leagues, [
        'label_hi' => '🌐 Mais parceiros', 'label_lo' => '🏝️ Menos interativos',
        'color_hi' => 'purple', 'color_lo' => 'lo',
        'copy_hi' => 'Mais parceiros de trade', 'copy_lo' => 'Menos interativos',
        'suffix' => ' times',
    ], $myTeamName);

renderSection('ofertas', '📤', 'rgba(251,191,36,.10)', 'Mais Ofertas de Trade Enviadas',
    'Times que mais propuseram trades (correção aplicada ao Utah Coyotes)',
    $ofertasMap, $leagues, [
        'label_hi' => '📤 Mais ofertas', 'label_lo' => '🤐 Menos ofertas',
        'color_hi' => 'gold', 'color_lo' => 'lo',
        'copy_hi' => 'Mais ofertas de trade', 'copy_lo' => 'Menos ofertas de trade',
    ], $myTeamName);

renderSection('trades-aceitas', '🤝', 'rgba(34,197,94,.10)', 'Trades Aceitas',
    'Times envolvidos no maior número de trades concluídas',
    $tradesAceitasMap, $leagues, [
        'label_hi' => '🤝 Mais trades aceitas', 'label_lo' => '🧊 Menos trades aceitas',
        'color_hi' => 'green', 'color_lo' => 'lo',
        'copy_hi' => 'Mais trades aceitas', 'copy_lo' => 'Menos trades aceitas',
    ], $myTeamName);

// ─── Histórico RISE (11 temporadas, extraído de vídeos de simulação) ─
renderSection('rise-titulos', '🏆', 'rgba(251,191,36,.12)', 'Ranking de Títulos — Histórico RISE',
    '11 temporadas de histórico: quem mais foi campeão',
    ['RISE' => $riseHistory['titulos']], ['RISE'], [
        'label_hi' => '🏆 Mais títulos', 'show_lo' => false,
        'color_hi' => 'gold',
        'copy_hi' => 'Mais títulos — histórico RISE',
        'only_league' => 'RISE',
    ], $myTeamName);

renderSection('rise-dinastia', '🔥', 'rgba(252,0,37,.12)', 'Maior Dinastia — Títulos Seguidos',
    'Maior sequência de títulos consecutivos na história da RISE',
    ['RISE' => $riseHistory['dinastia']], ['RISE'], [
        'label_hi' => '🔥 Maior sequência', 'show_lo' => false,
        'color_hi' => 'hi', 'suffix' => 'x seguido(s)',
        'copy_hi' => 'Maior dinastia — títulos seguidos',
        'only_league' => 'RISE',
    ], $myTeamName);

renderSection('rise-rivalidades', '⚔️', 'rgba(96,165,250,.12)', 'Maiores Rivalidades — Histórico',
    'Confrontos com mais jogos disputados entre si (qualquer round)',
    ['RISE' => $riseHistory['rivalidades']], ['RISE'], [
        'label_hi' => '⚔️ Mais confrontos', 'show_lo' => false,
        'color_hi' => 'blue', 'suffix' => ' jogos',
        'copy_hi' => 'Maiores rivalidades — histórico RISE',
        'pair_mode' => true, 'pair_sep' => '×',
        'only_league' => 'RISE',
    ], $myTeamName);

renderSection('rise-dominio', '💀', 'rgba(252,0,37,.10)', 'Domínio Total — Rivalidade Mais Desequilibrada',
    'Maior saldo de vitórias em confrontos diretos (mínimo 3 jogos)',
    ['RISE' => $riseHistory['dominio']], ['RISE'], [
        'label_hi' => '💀 Maior domínio', 'show_lo' => false,
        'color_hi' => 'hi', 'suffix' => ' de saldo',
        'copy_hi' => 'Domínio total — histórico RISE',
        'pair_mode' => true, 'pair_sep' => 'sobre',
        'only_league' => 'RISE',
    ], $myTeamName);

renderSection('rise-aparicoes', '📊', 'rgba(168,85,247,.10)', 'Mais Aparições em Playoff — Histórico',
    'Quantas vezes cada time chegou ao playoff nas 11 temporadas',
    ['RISE' => $riseHistory['aparicoes']], ['RISE'], [
        'label_hi' => '📊 Mais aparições', 'show_lo' => false,
        'color_hi' => 'purple', 'suffix' => 'x',
        'copy_hi' => 'Mais aparições em playoff — histórico RISE',
        'only_league' => 'RISE',
    ], $myTeamName);

renderSection('rise-eterno-vice', '🥈', 'rgba(148,163,184,.10)', 'Eterno Vice — Sem Título',
    'Times com mais vice-campeonatos sem nunca vencer o título',
    ['RISE' => $riseHistory['eterno_vice']], ['RISE'], [
        'label_hi' => '🥈 Mais vices sem título', 'show_lo' => false,
        'color_hi' => 'lo', 'suffix' => 'x',
        'copy_hi' => 'Eterno vice — histórico RISE',
        'only_league' => 'RISE',
    ], $myTeamName);

renderSection('rise-finais-margem', '🎖️', 'rgba(251,191,36,.10)', 'Margem nas Finais — Histórico',
    'Diferença de vitórias na NBA Finals: maiores atropelos x finais mais equilibradas',
    ['RISE' => $riseHistory['finais_margem']], ['RISE'], [
        'label_hi' => '🎖️ Maior atropelo', 'label_lo' => '⚖️ Mais equilibrada',
        'color_hi' => 'gold', 'color_lo' => 'lo', 'suffix' => ' de saldo',
        'copy_hi' => 'Finais mais dominantes', 'copy_lo' => 'Finais mais equilibradas',
        'pair_mode' => true, 'pair_sep' => 'x', 'show_lo' => true,
        'only_league' => 'RISE',
    ], $myTeamName);

renderSection('rise-seed-medio', '🌡️', 'rgba(96,165,250,.10)', 'Seed Médio Histórico',
    'Seed médio no round 1 (quanto menor, mais favorito o time costuma ser)',
    ['RISE' => $riseHistory['seed_medio']], ['RISE'], [
        'label_hi' => '🥇 Favoritos históricos (seed baixo)', 'label_lo' => '🎲 Zebras históricas (seed alto)',
        'color_hi' => 'blue', 'color_lo' => 'lo',
        'copy_hi' => 'Favoritos históricos — seed médio mais baixo', 'copy_lo' => 'Zebras históricas — seed médio mais alto',
        'show_lo' => true,
        'only_league' => 'RISE',
    ], $myTeamName);

renderSection('rise-sweeps-dados', '🧹', 'rgba(34,197,94,.10)', 'Sweeps Aplicados (4-0)',
    'Séries vencidas sem perder um jogo sequer',
    ['RISE' => $riseHistory['sweeps_dados']], ['RISE'], [
        'label_hi' => '🧹 Mais sweeps dados', 'show_lo' => false,
        'color_hi' => 'green', 'suffix' => 'x',
        'copy_hi' => 'Mais sweeps aplicados — histórico RISE',
        'only_league' => 'RISE',
    ], $myTeamName);

renderSection('rise-sweeps-sofridos', '🧹', 'rgba(252,0,37,.10)', 'Sweeps Sofridos (0-4)',
    'Séries perdidas sem vencer um jogo sequer',
    ['RISE' => $riseHistory['sweeps_sofridos']], ['RISE'], [
        'label_hi' => '🧹 Mais sweeps sofridos', 'show_lo' => false,
        'color_hi' => 'hi', 'suffix' => 'x',
        'copy_hi' => 'Mais sweeps sofridos — histórico RISE',
        'only_league' => 'RISE',
    ], $myTeamName);

renderSection('rise-jogo7', '🎬', 'rgba(251,191,36,.10)', 'Guerreiros do Jogo 7',
    'Times envolvidos em mais séries decididas no jogo decisivo (4-3)',
    ['RISE' => $riseHistory['jogo7']], ['RISE'], [
        'label_hi' => '🎬 Mais jogos 7', 'show_lo' => false,
        'color_hi' => 'gold', 'suffix' => 'x',
        'copy_hi' => 'Mais séries decididas no jogo 7 — histórico RISE',
        'only_league' => 'RISE',
    ], $myTeamName);

renderSection('trades-recusadas', '❌', 'rgba(252,0,37,.10)', 'Trades Recusadas',
    'Times envolvidos no maior número de trades rejeitadas',
    $tradesRecusadasMap, $leagues, [
        'label_hi' => '❌ Mais recusadas', 'label_lo' => '✅ Menos recusadas',
        'color_hi' => 'hi', 'color_lo' => 'green',
        'copy_hi' => 'Mais trades recusadas', 'copy_lo' => 'Menos trades recusadas',
    ], $myTeamName);

renderSection('trades-revertidas', '↩️', 'rgba(148,163,184,.10)', 'Trades Revertidas',
    'Times envolvidos em trades desfeitas por um admin',
    $tradesRevertidasMap, $leagues, [
        'label_hi' => '↩️ Mais revertidas', 'show_lo' => false,
        'color_hi' => 'lo',
        'copy_hi' => 'Mais trades revertidas',
    ], $myTeamName);

echo '</div>'; // .stats-flow





?>
</div><!-- .main-inner -->
</div><!-- .main -->

<script>
// Sidebar toggle
const sidebar = document.getElementById('sidebar');
const menuBtn = document.getElementById('menuBtn');
const overlay = document.getElementById('sbOverlay');
if (menuBtn) menuBtn.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('show'); });
if (overlay) overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); });

// League tabs filter
(function() {
  const tabs = document.querySelectorAll('.league-tab');
  function applyFilter(lg) {
    document.querySelectorAll('.league-card').forEach(card => {
      card.style.display = (card.dataset.league === lg) ? '' : 'none';
    });
    document.querySelectorAll('[data-only-league]').forEach(sec => {
      sec.style.display = (sec.dataset.onlyLeague === lg) ? '' : 'none';
    });
  }
  function activate(lg) {
    tabs.forEach(t => t.classList.toggle('active', t.dataset.league === lg));
    applyFilter(lg);
  }
  tabs.forEach(tab => tab.addEventListener('click', () => activate(tab.dataset.league)));
  const tabsEl = document.getElementById('leagueTabs');
  const initial = (tabsEl && tabsEl.dataset.defaultLeague) || (tabs.length ? tabs[0].dataset.league : null);
  if (initial) activate(initial);
})();

document.querySelectorAll('.copy-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    const text = btn.getAttribute('data-text');
    const orig = btn.innerHTML;
    const ok = () => {
      btn.innerHTML = '<i class="bi bi-check2"></i> Copiado!';
      btn.style.color = 'var(--green)';
      btn.style.borderColor = 'var(--green)';
      setTimeout(() => { btn.innerHTML = orig; btn.style.color = ''; btn.style.borderColor = ''; }, 2000);
    };
    navigator.clipboard.writeText(text).then(ok).catch(() => {
      const ta = document.createElement('textarea');
      ta.value = text; ta.style.cssText = 'position:fixed;opacity:0';
      document.body.appendChild(ta); ta.focus(); ta.select();
      document.execCommand('copy'); document.body.removeChild(ta);
      ok();
    });
  });
});
</script>
</body>
</html>
