<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
$user = getUserSession();
$pdo2 = db();
if (($user['user_type'] ?? '') !== 'admin' && !hasAdminAccess($pdo2, $user['id'])) {
    header('Location: index.php'); exit;
}
$pdo = db();

$leagues = ['ELITE','NEXT','RISE','ROOKIE'];

function queryByLeague(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $lg = $r['league'] ?? 'ALL';
        $out[$lg][] = $r;
    }
    return $out;
}

function sortLeagueData(array &$map, string $key = 'count', bool $desc = true): void {
    foreach ($map as &$arr) {
        usort($arr, fn($a,$b) => $desc ? $b[$key] - $a[$key] : $a[$key] - $b[$key]);
    }
}

// ── 1. Trades por time (agrupa pela liga da trade, não do time atual) ──
$tradesRaw = $pdo->query("
    SELECT tr.league, CONCAT(t.city,' ',t.name) AS name,
           COUNT(DISTINCT tr.id) AS count
    FROM trades tr
    JOIN teams t ON t.id = tr.from_team_id OR t.id = tr.to_team_id
    WHERE tr.status='accepted'
    GROUP BY tr.league, t.id, t.city, t.name
")->fetchAll(PDO::FETCH_ASSOC);
$tradesByLeague = [];
foreach ($tradesRaw as $r) {
    $tradesByLeague[$r['league']][] = ['name' => $r['name'], 'count' => (int)$r['count']];
}
sortLeagueData($tradesByLeague);

// ── 2. Pares que mais trocaram ───────────────────────────────────
$teamNamesShort = [];
$teamNamesLong  = [];
foreach ($pdo->query("SELECT id, name, CONCAT(city,' ',name) AS full FROM teams")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $teamNamesShort[$r['id']] = $r['name'];
    $teamNamesLong[$r['id']]  = $r['full'];
}
$pairsRaw = $pdo->query("
    SELECT league, LEAST(from_team_id,to_team_id) AS t1, GREATEST(from_team_id,to_team_id) AS t2, COUNT(*) AS count
    FROM trades WHERE status='accepted' AND from_team_id IS NOT NULL AND to_team_id IS NOT NULL
    GROUP BY league, t1, t2
")->fetchAll(PDO::FETCH_ASSOC);
$pairsByLeague = [];
foreach ($pairsRaw as $r) {
    $pairsByLeague[$r['league']][] = [
        'a' => $teamNamesShort[$r['t1']] ?? "#{$r['t1']}",
        'b' => $teamNamesShort[$r['t2']] ?? "#{$r['t2']}",
        'a_long' => $teamNamesLong[$r['t1']] ?? "#{$r['t1']}",
        'b_long' => $teamNamesLong[$r['t2']] ?? "#{$r['t2']}",
        'count'  => (int)$r['count'],
    ];
}
sortLeagueData($pairsByLeague);

// ── 3. Mais títulos (usa a liga da temporada, não a atual do time) ──
$titlesMap = queryByLeague($pdo, "
    SELECT sh.league, CONCAT(t.city,' ',t.name) AS name, COUNT(*) AS count
    FROM season_history sh JOIN teams t ON t.id=sh.champion_team_id
    GROUP BY sh.league, t.id, t.city, t.name ORDER BY count DESC
");
sortLeagueData($titlesMap);

// ── 4. Mais aparições no playoff ─────────────────────────────────
$playoffMap = queryByLeague($pdo, "
    SELECT tsp.league, CONCAT(t.city,' ',t.name) AS name, COUNT(DISTINCT tsp.season_id) AS count
    FROM team_season_points tsp JOIN teams t ON t.id=tsp.team_id
    WHERE tsp.points >= 3
    GROUP BY tsp.league, t.id, t.city, t.name ORDER BY count DESC
");
sortLeagueData($playoffMap);

// ── 5. Mais vices (liga da temporada) ────────────────────────────
$vicesMap = queryByLeague($pdo, "
    SELECT sh.league, CONCAT(t.city,' ',t.name) AS name, COUNT(*) AS count
    FROM season_history sh JOIN teams t ON t.id=sh.runner_up_team_id
    GROUP BY sh.league, t.id, t.city, t.name ORDER BY count DESC
");
sortLeagueData($vicesMap);

// ── 6. Mais prêmios individuais (liga via season_history) ────────
$awardsMap = queryByLeague($pdo, "
    SELECT sh.league, CONCAT(t.city,' ',t.name) AS name, COUNT(*) AS count
    FROM season_awards sa
    JOIN teams t ON t.id=sa.team_id
    JOIN season_history sh ON sh.season_id=sa.season_id
    GROUP BY sh.league, t.id, t.city, t.name ORDER BY count DESC
");
sortLeagueData($awardsMap);

// ── 7. Mais pontos acumulados (regular) ──────────────────────────
$ptsMap = queryByLeague($pdo, "
    SELECT tsp.league, CONCAT(t.city,' ',t.name) AS name, SUM(tsp.points) AS count
    FROM team_season_points tsp JOIN teams t ON t.id=tsp.team_id
    GROUP BY tsp.league, t.id, t.city, t.name ORDER BY count DESC
");
sortLeagueData($ptsMap);

// ── 8. Maior OVR médio atual (top 5 jogadores do elenco) ─────────
$ovrMap = queryByLeague($pdo, "
    SELECT t.league, CONCAT(t.city,' ',t.name) AS name,
           ROUND(AVG(ovr_top.ovr),1) AS count
    FROM teams t
    JOIN (
        SELECT p.team_id, COALESCE(p.ovr, 0) AS ovr
        FROM players p
        WHERE p.team_id IS NOT NULL AND COALESCE(p.ovr, 0) > 0
    ) ovr_top ON ovr_top.team_id = t.id
    GROUP BY t.id, t.league, t.city, t.name ORDER BY count DESC
");
sortLeagueData($ovrMap);

// ── 9. Elenco mais jovem ─────────────────────────────────────────
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

// ── 11. Mais tapas usados ────────────────────────────────────────
$tapasMap = queryByLeague($pdo, "
    SELECT t.league, CONCAT(t.city,' ',t.name) AS name, COALESCE(t.tapas_used,0) AS count
    FROM teams t WHERE COALESCE(t.tapas_used,0) > 0 ORDER BY count DESC
");
sortLeagueData($tapasMap);

$loginsMap  = [];
$fbaPtsMap  = [];

// ── 14. Mais jogadores draftados ─────────────────────────────────
$draftedMap = queryByLeague($pdo, "
    SELECT t.league, CONCAT(t.city,' ',t.name) AS name, COUNT(p.id) AS count
    FROM teams t JOIN players p ON p.drafted_by_team_id=t.id
    GROUP BY t.id, t.league, t.city, t.name ORDER BY count DESC
");
sortLeagueData($draftedMap);

// ── 15. Mais jogadores que passaram pelo clube (liga do log) ─────
$rotMap = queryByLeague($pdo, "
    SELECT psl.league, CONCAT(t.city,' ',t.name) AS name, COUNT(DISTINCT psl.player_id) AS count
    FROM player_season_log psl JOIN teams t ON t.id=psl.team_id
    GROUP BY psl.league, t.id, t.city, t.name ORDER BY count DESC
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

// ── Propostas de trade (todas, qualquer status) ──────────────────
$faPropostasMap = queryByLeague($pdo, "
    SELECT tr.league, CONCAT(t.city,' ',t.name) AS name, COUNT(DISTINCT tr.id) AS count
    FROM trades tr
    JOIN teams t ON t.id = tr.from_team_id
    GROUP BY tr.league, t.id, t.city, t.name ORDER BY count DESC
");
sortLeagueData($faPropostasMap);

// ── 16. Trades com picks incluídas (liga da trade) ───────────────
$pickTradesMap = queryByLeague($pdo, "
    SELECT tr.league, CONCAT(t.city,' ',t.name) AS name, COUNT(DISTINCT tr.id) AS count
    FROM trades tr
    JOIN teams t ON t.id = tr.from_team_id OR t.id = tr.to_team_id
    WHERE tr.status='accepted'
      AND (
        (tr.picks_given IS NOT NULL AND tr.picks_given != '[]' AND tr.picks_given != '')
        OR (tr.picks_received IS NOT NULL AND tr.picks_received != '[]' AND tr.picks_received != '')
      )
    GROUP BY tr.league, t.id, t.city, t.name ORDER BY count DESC
");
sortLeagueData($pickTradesMap);

// ── 17. Maior OVR individual já registrado ───────────────────────
$topOvrMap = queryByLeague($pdo, "
    SELECT psl.league, psl.player_name AS name, MAX(psl.ovr) AS count
    FROM player_season_log psl
    WHERE psl.ovr > 0
    GROUP BY psl.league, psl.player_name ORDER BY count DESC
");
sortLeagueData($topOvrMap);

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Infos & Forms · FBA</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:rgba(252,0,37,.10);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--border:rgba(255,255,255,.07);--border-md:rgba(255,255,255,.12);--text:#f0f0f3;--text-2:#868690;--text-3:#48484f;--amber:#f59e0b;--green:#22c55e;--purple:#a855f7;--blue:#60a5fa;--radius:14px;--font:'Poppins',sans-serif}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.topbar{position:sticky;top:0;z-index:300;height:54px;background:var(--panel);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 16px;gap:12px}
.topbar-logo{width:30px;height:30px;border-radius:8px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;color:#fff;flex-shrink:0}
.icon-btn{width:34px;height:34px;border-radius:10px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:15px;cursor:pointer;text-decoration:none;transition:all .2s}
.icon-btn:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}
main{max-width:1200px;margin:0 auto;padding:28px 16px 80px}

/* Section headers */
.section-block{margin-bottom:40px}
.section-head{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--border)}
.section-head h2{font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;color:var(--text)}
.section-head .section-icon{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.section-sub{font-size:11px;color:var(--text-3);margin-top:2px}

/* Grid */
.leagues-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
@media(max-width:900px){.leagues-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:520px){.leagues-grid{grid-template-columns:1fr}}

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
.rval{font-family:'Oswald',sans-serif;font-size:14px;font-weight:700;flex-shrink:0}
.rval.hi{color:var(--red)}
.rval.lo{color:var(--text-3)}
.rval.gold{color:var(--amber)}
.rval.green{color:var(--green)}
.rval.blue{color:var(--blue)}
.rval.purple{color:var(--purple)}
.divider{height:1px;background:var(--border);margin:2px 0}

/* Pair rows */
.pair-row{display:flex;align-items:center;gap:8px;padding:6px 14px;border-bottom:1px solid var(--border)}
.pair-row:last-child{border-bottom:none}
.pair-names{flex:1;min-width:0;display:flex;flex-direction:column;gap:1px}
.pair-a{font-size:11px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pair-b{font-size:10px;color:var(--text-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

.empty-state{padding:16px 14px;font-size:11px;color:var(--text-3);text-align:center}
</style>
</head>
<body>
<div class="topbar">
  <div class="topbar-logo">FBA</div>
  <span style="font-weight:800;font-size:14px;flex:1">Infos & Forms</span>
  <a href="admin.php" class="icon-btn"><i class="bi bi-arrow-left"></i></a>
</div>

<main>
<?php

// ─────────────────────────────────────────────────────────────────
// Helper: renders a 4-league grid with top5/bot5 rank rows
// $data = ['ELITE'=>[['name'=>...,'count'=>...], ...], ...]
// $opts = [ label_hi, label_lo, color_hi, label_copy_hi, label_copy_lo, suffix, reverse_bot ]
function renderSection(string $id, string $icon, string $icon_bg, string $title, string $subtitle,
                       array $data, array $leagues, array $opts = []): void {
    $label_hi     = $opts['label_hi']     ?? '🔥 Mais';
    $label_lo     = $opts['label_lo']     ?? '🧊 Menos';
    $color_hi     = $opts['color_hi']     ?? 'hi';
    $color_lo     = $opts['color_lo']     ?? 'lo';
    $copy_hi      = $opts['copy_hi']      ?? $title.' — Mais';
    $copy_lo      = $opts['copy_lo']      ?? $title.' — Menos';
    $suffix       = $opts['suffix']       ?? '';
    $show_lo      = $opts['show_lo']      ?? true;
    $pair_mode    = $opts['pair_mode']    ?? false;

    echo "<div class=\"section-block\" id=\"{$id}\">";
    echo "<div class=\"section-head\">";
    echo "<div class=\"section-icon\" style=\"background:{$icon_bg}\">{$icon}</div>";
    echo "<div><h2>{$title}</h2><div class=\"section-sub\">{$subtitle}</div></div>";
    echo "</div>";
    echo "<div class=\"leagues-grid\">";

    foreach ($leagues as $lg) {
        $arr  = $data[$lg] ?? [];
        $top5 = array_slice($arr, 0, 5);
        $bot5 = array_reverse(array_slice(array_reverse($arr), 0, 5));

        // Build copy text
        $cp  = "🏀 *{$copy_hi} — {$lg}*\n";
        foreach ($top5 as $i => $r) {
            $line = $pair_mode ? "{$r['a_long']} × {$r['b_long']}" : $r['name'];
            $cp .= ($i+1).". {$line} — {$r['count']}{$suffix}\n";
        }
        if ($show_lo) {
            $cp .= "\n*{$copy_lo} — {$lg}*\n";
            foreach ($bot5 as $i => $r) {
                $line = $pair_mode ? "{$r['a_long']} × {$r['b_long']}" : $r['name'];
                $cp .= ($i+1).". {$line} — {$r['count']}{$suffix}\n";
            }
        }
        $cpEsc = htmlspecialchars($cp, ENT_QUOTES);

        echo "<div class=\"league-card\">";
        echo "<div class=\"league-header\">";
        echo "<span class=\"league-badge badge-{$lg}\">{$lg}</span>";
        echo "<span style=\"font-size:11px;color:var(--text-3);flex:1\">".count($arr)." registros</span>";
        echo "<button class=\"copy-btn\" data-text=\"{$cpEsc}\"><i class=\"bi bi-clipboard\"></i> Copiar</button>";
        echo "</div>";

        echo "<div class=\"card-sub\">{$label_hi}</div>";
        if (empty($top5)) {
            echo "<div class=\"empty-state\">Sem dados</div>";
        } else {
            foreach ($top5 as $i => $r) {
                if ($pair_mode) {
                    echo "<div class=\"pair-row\">";
                    echo "<span class=\"rn ".($i===0?'gold':'')."\">" . ($i+1) . "</span>";
                    echo "<div class=\"pair-names\">";
                    echo "<span class=\"pair-a\" title=\"".htmlspecialchars($r['a_long'])."\">" . htmlspecialchars($r['a']) . "</span>";
                    echo "<span class=\"pair-b\">× " . htmlspecialchars($r['b']) . "</span>";
                    echo "</div>";
                    echo "<span class=\"rval {$color_hi}\">" . $r['count'] . $suffix . "</span>";
                    echo "</div>";
                } else {
                    echo "<div class=\"rank-row\">";
                    echo "<span class=\"rn ".($i===0?'gold':'')."\">" . ($i+1) . "</span>";
                    echo "<span class=\"rname\" title=\"".htmlspecialchars($r['name'])."\">" . htmlspecialchars($r['name']) . "</span>";
                    echo "<span class=\"rval {$color_hi}\">" . $r['count'] . $suffix . "</span>";
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
                foreach ($bot5 as $i => $r) {
                    if ($pair_mode) {
                        echo "<div class=\"pair-row\">";
                        echo "<span class=\"rn\">" . ($i+1) . "</span>";
                        echo "<div class=\"pair-names\">";
                        echo "<span class=\"pair-a\">" . htmlspecialchars($r['a']) . "</span>";
                        echo "<span class=\"pair-b\">× " . htmlspecialchars($r['b']) . "</span>";
                        echo "</div>";
                        echo "<span class=\"rval {$color_lo}\">" . $r['count'] . $suffix . "</span>";
                        echo "</div>";
                    } else {
                        echo "<div class=\"rank-row\">";
                        echo "<span class=\"rn\">" . ($i+1) . "</span>";
                        echo "<span class=\"rname\" title=\"".htmlspecialchars($r['name'])."\">" . htmlspecialchars($r['name']) . "</span>";
                        echo "<span class=\"rval {$color_lo}\">" . $r['count'] . $suffix . "</span>";
                        echo "</div>";
                    }
                }
            }
        }

        echo "</div>"; // .league-card
    }

    echo "</div></div>"; // .leagues-grid .section-block
}

// ─── Render all sections ─────────────────────────────────────────

renderSection('trades', '🔄', 'rgba(252,0,37,.15)', 'Trades por Time',
    'Quem mais e menos movimentou o mercado',
    $tradesByLeague, $leagues, [
        'label_hi' => '🔥 Mais ativos', 'label_lo' => '🧊 Menos ativos',
        'color_hi' => 'hi', 'color_lo' => 'lo',
        'copy_hi' => 'Mais trades', 'copy_lo' => 'Menos trades',
    ]);

renderSection('pares', '🤝', 'rgba(96,165,250,.12)', 'Pares que mais trocaram',
    'Duplas com mais trades entre si',
    $pairsByLeague, $leagues, [
        'label_hi' => '🤝 Mais parceiros', 'label_lo' => '❄️ Menos parceiros',
        'color_hi' => 'blue', 'color_lo' => 'lo',
        'copy_hi' => 'Pares mais ativos', 'copy_lo' => 'Pares menos ativos',
        'pair_mode' => true,
    ]);

renderSection('titulos', '🏆', 'rgba(251,191,36,.12)', 'Títulos',
    'Times com mais campeonatos',
    $titlesMap, $leagues, [
        'label_hi' => '🏆 Mais títulos', 'show_lo' => false,
        'color_hi' => 'gold',
        'copy_hi' => 'Mais títulos',
    ]);

renderSection('playoffs', '🎯', 'rgba(252,0,37,.12)', 'Aparições no Playoff',
    'Times que mais chegaram ao playoff',
    $playoffMap, $leagues, [
        'label_hi' => '🎯 Mais playoffs', 'label_lo' => '📉 Menos playoffs',
        'color_hi' => 'hi', 'color_lo' => 'lo',
        'copy_hi' => 'Mais playoffs', 'copy_lo' => 'Menos playoffs',
    ]);

renderSection('vices', '🥈', 'rgba(148,163,184,.10)', 'Vice-Campeões',
    'Times que mais foram vice',
    $vicesMap, $leagues, [
        'label_hi' => '🥈 Mais vices', 'show_lo' => false,
        'color_hi' => 'lo',
        'copy_hi' => 'Mais vices',
    ]);

renderSection('premios', '🏅', 'rgba(245,158,11,.12)', 'Prêmios Individuais',
    'Times com mais MVPs, DPOYs, ROYs, etc.',
    $awardsMap, $leagues, [
        'label_hi' => '🏅 Mais premiados', 'label_lo' => '📦 Menos premiados',
        'color_hi' => 'gold', 'color_lo' => 'lo',
        'copy_hi' => 'Mais prêmios individuais', 'copy_lo' => 'Menos prêmios individuais',
    ]);

renderSection('pontos', '📊', 'rgba(96,165,250,.10)', 'Pontos na Regular',
    'Total de pontos acumulados na temporada regular',
    $ptsMap, $leagues, [
        'label_hi' => '📊 Mais pontos', 'label_lo' => '📉 Menos pontos',
        'color_hi' => 'blue', 'color_lo' => 'lo',
        'copy_hi' => 'Mais pontos regular', 'copy_lo' => 'Menos pontos regular',
    ]);

renderSection('ovr', '⭐', 'rgba(34,197,94,.10)', 'Maior OVR Médio Atual',
    'Média do elenco atual (jogadores rosteriados)',
    $ovrMap, $leagues, [
        'label_hi' => '⭐ Maior OVR médio', 'label_lo' => '📉 Menor OVR médio',
        'color_hi' => 'green', 'color_lo' => 'lo',
        'copy_hi' => 'Maior OVR médio', 'copy_lo' => 'Menor OVR médio',
    ]);

renderSection('jovem', '🌱', 'rgba(168,85,247,.10)', 'Elenco Mais Jovem',
    'Idade média dos jogadores em contrato',
    $youngMap, $leagues, [
        'label_hi' => '🌱 Mais jovens', 'show_lo' => false,
        'color_hi' => 'purple',
        'copy_hi' => 'Elenco mais jovem',
        'suffix' => ' anos',
    ]);

renderSection('velho', '🧓', 'rgba(148,163,184,.08)', 'Elenco Mais Experiente',
    'Times com maior idade média',
    $oldMap, $leagues, [
        'label_hi' => '🧓 Mais experientes', 'show_lo' => false,
        'color_hi' => 'lo',
        'copy_hi' => 'Elenco mais experiente',
        'suffix' => ' anos',
    ]);

renderSection('tapas', '👋', 'rgba(252,0,37,.10)', 'Tapas Usados',
    'GMs que mais utilizaram tapas',
    $tapasMap, $leagues, [
        'label_hi' => '👋 Mais tapas', 'show_lo' => false,
        'color_hi' => 'hi',
        'copy_hi' => 'Mais tapas usados',
    ]);


renderSection('draftados', '🎓', 'rgba(168,85,247,.10)', 'Jogadores Draftados',
    'Times que mais desenvolveram jogadores pelo draft',
    $draftedMap, $leagues, [
        'label_hi' => '🎓 Mais draftados', 'label_lo' => '📦 Menos draftados',
        'color_hi' => 'purple', 'color_lo' => 'lo',
        'copy_hi' => 'Mais jogadores draftados', 'copy_lo' => 'Menos jogadores draftados',
    ]);

renderSection('rotatividade', '🔁', 'rgba(34,197,94,.08)', 'Rotatividade de Elenco',
    'Quantidade de jogadores diferentes que passaram pelo clube',
    $rotMap, $leagues, [
        'label_hi' => '🔁 Mais rotatividade', 'label_lo' => '🏠 Menos rotatividade',
        'color_hi' => 'green', 'color_lo' => 'lo',
        'copy_hi' => 'Mais rotatividade', 'copy_lo' => 'Menos rotatividade',
    ]);

renderSection('fapropostas', '📋', 'rgba(96,165,250,.10)', 'Propostas de Trade Enviadas',
    'Times que mais e menos iniciaram negociações (qualquer status)',
    $faPropostasMap, $leagues, [
        'label_hi' => '📋 Mais propostas', 'label_lo' => '📦 Menos propostas',
        'color_hi' => 'blue', 'color_lo' => 'lo',
        'copy_hi' => 'Mais propostas de trade', 'copy_lo' => 'Menos propostas de trade',
    ]);

renderSection('fa', '🖊️', 'rgba(34,197,94,.10)', 'Free Agency',
    'Times que mais e menos assinaram jogadores na FA',
    $faMap, $leagues, [
        'label_hi' => '🖊️ Mais contratações', 'label_lo' => '📦 Menos contratações',
        'color_hi' => 'green', 'color_lo' => 'lo',
        'copy_hi' => 'Mais FA pickups', 'copy_lo' => 'Menos FA pickups',
    ]);

renderSection('picktrades', '🃏', 'rgba(96,165,250,.08)', 'Trades com Picks',
    'Times que mais envolveram picks em negociações',
    $pickTradesMap, $leagues, [
        'label_hi' => '🃏 Mais picks negociadas', 'label_lo' => '📦 Menos picks',
        'color_hi' => 'blue', 'color_lo' => 'lo',
        'copy_hi' => 'Mais trades com picks', 'copy_lo' => 'Menos trades com picks',
    ]);

renderSection('topovr', '🌟', 'rgba(251,191,36,.10)', 'Maior OVR Individual',
    'Jogadores com o maior OVR já registrado no histórico',
    $topOvrMap, $leagues, [
        'label_hi' => '🌟 Maior OVR histórico', 'show_lo' => false,
        'color_hi' => 'gold',
        'copy_hi' => 'Maior OVR individual histórico',
    ]);

?>
</main>

<script>
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
