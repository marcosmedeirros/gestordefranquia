<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();
$user = getUserSession();
$pdo = db();

$leagues = ['ELITE','NEXT','RISE','ROOKIE'];

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

// Toda estatística baseada em temporada olha só a sprint em andamento de cada
// liga — misturar sprints antigas com a atual inflava contagens e sequências.
// Elenco/picks atuais e trocas não precisam do filtro: são zerados pelo
// finalize_sprint. As sprints são por liga, então basta pegar as ativas.
$TEMPORADAS_DA_SPRINT = "(SELECT id FROM seasons WHERE sprint_id IN (SELECT id FROM sprints WHERE status = 'active'))";

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

// Correção: picks/escolhas atribuídas ao Utah Coyotes que na verdade pertenciam
// ao St. Louis Musketeers (mudança de nome/cidade de franquia refletida manualmente
// nos dados históricos do draft). Reutilizado nas seções "Pick Origem no Top 5" e
// "Mais Escolhas no Top 5".
function applyCoyotesMusketeersFix(array &$map): void {
    foreach ($map as $lg => &$arr) {
        $hasMusk = false;
        foreach ($arr as &$row) {
            if (str_contains($row['name'], 'Coyotes') && str_contains($row['name'], 'Utah')) $row['count'] = max(0, $row['count'] - 1);
            if (str_contains($row['name'], 'Musketeers') || str_contains($row['name'], 'Musketters')) { $row['count']++; $hasMusk = true; }
        } unset($row);
        if (!$hasMusk) $arr[] = ['name' => 'St. Louis Musketeers', 'count' => 1];
    } unset($arr);
}

// ── 3. Mais aparições no playoff ─────────────────────────────────
// Foi ao playoff = tem resultado de playoff registrado na temporada (qualquer
// fase). Mesma conta do bot (/idasplayoffs), em backend/estatisticas_playoff.php.
require_once __DIR__ . '/backend/estatisticas_playoff.php';
$playoffMap = epSeguro(fn() => epAparicoes($pdo), 'aparicoes');

// ── 5. Elenco mais jovem ─────────────────────────────────────────
// LEFT JOIN (em vez de INNER JOIN) para times sem elenco/jogadores elegíveis
// ainda aparecerem na lista (com count NULL = "sem dados"), em vez de sumir.
$youngMap = queryByLeague($pdo, "
    SELECT t.league, CONCAT(t.city,' ',t.name) AS name,
           ROUND(AVG(p.age),1) AS count
    FROM teams t
    LEFT JOIN players p ON p.team_id=t.id AND p.age > 0
    GROUP BY t.id, t.league, t.city, t.name ORDER BY count ASC
");
$sortAgeNullsLast = function($a, $b) {
    if ($a['count'] === null && $b['count'] === null) return 0;
    if ($a['count'] === null) return 1;
    if ($b['count'] === null) return -1;
    return $a['count'] <=> $b['count'];
};
foreach ($youngMap as &$arr) usort($arr, $sortAgeNullsLast);
unset($arr);

// ── 10. Elenco mais velho ────────────────────────────────────────
// Times sem dados ficam sempre ao final (nem entre os "mais jovens", nem entre os "mais velhos").
$oldMap = [];
foreach ($youngMap as $lg => $arr) {
    $withData = array_values(array_filter($arr, fn($r) => $r['count'] !== null));
    $noData   = array_values(array_filter($arr, fn($r) => $r['count'] === null));
    $oldMap[$lg] = array_merge(array_reverse($withData), $noData);
}

// ── 14. Mais jogadores draftados ─────────────────────────────────
// Conta pelo draft_pool, não pela tabela players.
//
// O draft inicial — aquele que montou os elencos quando a liga nasceu — também
// grava players.drafted_by_team_id, então contar por ali somava 12 ou 15
// jogadores de uma vez pra todo mundo e afogava o draft de verdade. E não dá
// pra separar os dois na players: ela não guarda de qual draft o jogador veio.
//
// O draft_pool é só do draft padrão (o inicial tem o initdraft_pool dele), o
// que torna a separação estrutural em vez de um filtro que alguém pode
// esquecer de repetir na próxima consulta.
$draftedMap = queryByLeague($pdo, "
    SELECT t.league, CONCAT(t.city,' ',t.name) AS name, COUNT(dp.id) AS count
    FROM teams t
    LEFT JOIN draft_pool dp ON dp.drafted_by_team_id = t.id
    GROUP BY t.id, t.league, t.city, t.name ORDER BY count DESC
");
sortLeagueData($draftedMap);

// ── 15. Mais jogadores que passaram pelo clube ───────────────────
$rotMap = queryByLeague($pdo, "
    SELECT t.league, CONCAT(t.city,' ',t.name) AS name, COUNT(DISTINCT psl.player_id) AS count
    FROM teams t
    LEFT JOIN player_season_log psl ON psl.team_id=t.id AND psl.league COLLATE utf8mb4_unicode_ci=t.league COLLATE utf8mb4_unicode_ci
         AND psl.season_id IN $TEMPORADAS_DA_SPRINT
    GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
");
sortLeagueData($rotMap);

// ── FA pickups (inclui times com 0 contratações) ─────────────────
$faMap = queryByLeague($pdo, "
    SELECT t.league, CONCAT(t.city,' ',t.name) AS name,
           COUNT(far.id) AS count
    FROM teams t
    LEFT JOIN fa_requests far ON far.winner_team_id = t.id AND far.status = 'assigned'
         AND far.season_id IN $TEMPORADAS_DA_SPRINT
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
          AND ds.season_id IN $TEMPORADAS_DA_SPRINT
        GROUP BY ds.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ot5Raw as $r) $origTop5Map[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    applyCoyotesMusketeersFix($origTop5Map);
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
          AND ds.season_id IN $TEMPORADAS_DA_SPRINT
        GROUP BY ds.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    // Quem nunca escolheu no top 5 fica de fora: a lista é de quem chegou
    // lá, e uma fila de zeros embaixo só empurra os que interessam pra cima
    // da tela sem dizer nada.
    foreach ($tp5Raw as $r) {
        if ((int)$r['count'] <= 0) continue;
        $top5PicksMap[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    }
    applyCoyotesMusketeersFix($top5PicksMap);
    sortLeagueData($top5PicksMap);
} catch (Exception) {}



// ── Maior sequência de playoffs + maior jejum ────────────────────
// Mesma régua das aparições (resultado de playoff registrado), temporada a
// temporada. Conta compartilhada com o bot (/sequencia e /jejum).
$streakMap = epSeguro(fn() => epSequenciaPlayoff($pdo), 'sequencia');
$jejumMap  = epSeguro(fn() => epJejumPlayoff($pdo), 'jejum');
// ── Jogadores que passaram por mais times ─────────────────────────
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
            WHERE ovr >= 78 AND season_id IN $TEMPORADAS_DA_SPRINT
            GROUP BY team_id, player_id
        ) sub ON sub.team_id=t.id
        GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($retRaw as $r) $retencaoMap[$r['league']][] = ['name'=>$r['name'],'count'=>(float)$r['count']];
    sortLeagueData($retencaoMap);
} catch (Exception) {}

// ── Aproveitamento do draft (OVR médio dos jogadores draftados) ───
// Mesma troca do "mais draftados": pelo draft_pool, senão o OVR médio é
// puxado pelos jogadores do draft inicial, que ninguém escolheu.
//
// Aqui a diferença pesa ainda mais que na contagem: o inicial distribuiu
// elencos inteiros, então a média virava a do elenco de origem do time e não
// dizia nada sobre quem acerta a mão no draft.
$draftOvrMap = [];
try {
    $doRaw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name,
               ROUND(AVG(dp.ovr), 1) AS count
        FROM teams t
        LEFT JOIN draft_pool dp ON dp.drafted_by_team_id = t.id AND dp.ovr > 0
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

// ── Pares direcionais: quem mais ofereceu para quem ─────────────
$direcionalMap = [];
try {
    // Só a sprint ativa, pelo created_at (trade não tem season_id). O fim de
    // sprint já apaga as trades simples, mas o corte fica explícito: a conta
    // não pode depender de uma limpeza feita em outro arquivo pra estar certa.
    $dirRaw = $pdo->query("
        SELECT t1.league,
               CONCAT(t1.city,' ',t1.name) AS a_long, t1.name AS a,
               CONCAT(t2.city,' ',t2.name) AS b_long, t2.name AS b,
               COUNT(*) AS count
        FROM trades tr
        JOIN teams t1 ON t1.id = tr.from_team_id
        JOIN teams t2 ON t2.id = tr.to_team_id
        WHERE tr.status = 'accepted'
          AND tr.created_at >= (SELECT COALESCE(MAX(sp.start_date),'1900-01-01') FROM sprints sp WHERE sp.league = t1.league AND sp.status='active')
        GROUP BY t1.league, t1.id, t2.id
        ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($dirRaw as $r) {
        $count = (int)$r['count'];
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
    // Mesmo corte das direcionais acima: só a sprint ativa.
    $prRaw = $pdo->query("
        SELECT t1.league,
               CONCAT(t1.city,' ',t1.name) AS a_long, t1.name AS a,
               CONCAT(t2.city,' ',t2.name) AS b_long, t2.name AS b,
               COUNT(*) AS count
        FROM trades tr
        JOIN teams t1 ON t1.id = LEAST(tr.from_team_id, tr.to_team_id)
        JOIN teams t2 ON t2.id = GREATEST(tr.from_team_id, tr.to_team_id)
        WHERE tr.status = 'accepted' AND tr.from_team_id <> tr.to_team_id
          AND tr.created_at >= (SELECT COALESCE(MAX(sp.start_date),'1900-01-01') FROM sprints sp WHERE sp.league = t1.league AND sp.status='active')
        GROUP BY t1.league, t1.id, t2.id ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($prRaw as $r) $pairsMap[$r['league']][] = ['a'=>$r['a'],'b'=>$r['b'],'a_long'=>$r['a_long'],'b_long'=>$r['b_long'],'count'=>(int)$r['count'],'name'=>$r['a_long'].' × '.$r['b_long']];
    sortLeagueData($pairsMap);
} catch (Exception) {}

// ── Times com mais parceiros distintos de trade ───────────────────
$parceirosMap = [];
try {
    // Soma `trades` (dois times) com `multi_trades` (três ou mais). Cada
    // troca vira duas arestas — ida e volta — pra o time aparecer dos dois
    // lados sem IF no meio da contagem.
    // Período: a sprint ativa inteira, sem recorte por ciclo (ver o bloco
    // das trades aceitas, logo abaixo).
    $pcRaw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name, COUNT(DISTINCT e.parceiro) AS count
        FROM teams t
        LEFT JOIN (
            SELECT tr.from_team_id AS eu, tr.to_team_id AS parceiro, tr.created_at FROM trades tr WHERE tr.status='accepted'
            UNION ALL
            SELECT tr.to_team_id, tr.from_team_id, tr.created_at FROM trades tr WHERE tr.status='accepted'
            UNION ALL
            SELECT mi.from_team_id, mi.to_team_id, mt.created_at FROM multi_trade_items mi
              JOIN multi_trades mt ON mt.id = mi.trade_id WHERE mt.status='accepted'
            UNION ALL
            SELECT mi.to_team_id, mi.from_team_id, mt.created_at FROM multi_trade_items mi
              JOIN multi_trades mt ON mt.id = mi.trade_id WHERE mt.status='accepted'
        ) e ON e.eu = t.id AND e.parceiro <> t.id
             AND e.created_at >= (SELECT COALESCE(MAX(sp.start_date),'1900-01-01') FROM sprints sp WHERE sp.league = t.league AND sp.status='active')
        GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pcRaw as $r) $parceirosMap[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    sortLeagueData($parceirosMap);
} catch (Exception) {}

// ── Maior número de ofertas de trade enviadas ────────────────────
$ofertasMap = [];
try {
    // Na multi quem propõe é o created_by_team_id: os outros participantes
    // entraram na proposta de alguém, não fizeram uma.
    $ofRaw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name,
               (SELECT COUNT(*) FROM trades tr
                 WHERE tr.from_team_id = t.id
                   AND tr.created_at >= (SELECT COALESCE(MAX(sp.start_date),'1900-01-01') FROM sprints sp WHERE sp.league = t.league AND sp.status='active'))
             + (SELECT COUNT(*) FROM multi_trades mt
                 WHERE mt.created_by_team_id = t.id
                   AND mt.created_at >= (SELECT COALESCE(MAX(sp.start_date),'1900-01-01') FROM sprints sp WHERE sp.league = t.league AND sp.status='active')) AS count
        FROM teams t
        ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ofRaw as $r) $ofertasMap[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    sortLeagueData($ofertasMap);
} catch (Exception) {}

// ── Trades aceitas ─────────────────────────────────────────────────
$tradesAceitasMap = [];
try {
    // Conta pra TODO time envolvido, quem propôs e quem topou: os dois
    // aceitaram a mesma troca. Na multi, vale pros N participantes — e o
    // DISTINCT no mt.id evita contar a mesma multi uma vez por item, senão
    // uma troca de cinco jogadores viraria cinco trades.
    // ── A SPRINT ATIVA INTEIRA ──────────────────────────────────────
    // Recorta pela sprint aberta, igual ao bot. Estas contas eram as únicas
    // somando desde o começo da liga, e diziam 33 numa liga onde o limite
    // POR CICLO é 10 — número que ninguém reconhecia.
    //
    // O recorte é por DATA porque trade não tem season_id. Até 14/09/2026
    // também cortava pelo ciclo corrente; saiu a pedido do Marcos: toda
    // estatística é da sprint inteira, todos os ciclos dela. Por isso o
    // número pode passar do max_trades, que é por ciclo — não é bug, é outro
    // período. Vale pras quatro contas de trade daqui (parceiros, ofertas,
    // aceitas e recusadas), e o bot (backend/estatisticas_bot.php) segue igual.
    $taRaw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name,
               (SELECT COUNT(*) FROM trades tr
                 WHERE tr.status='accepted' AND (tr.from_team_id=t.id OR tr.to_team_id=t.id)
                   AND tr.created_at >= (SELECT COALESCE(MAX(sp.start_date),'1900-01-01') FROM sprints sp WHERE sp.league = t.league AND sp.status='active'))
             + (SELECT COUNT(DISTINCT mt.id) FROM multi_trades mt
                  JOIN multi_trade_items mi ON mi.trade_id = mt.id
                 WHERE mt.status='accepted' AND (mi.from_team_id=t.id OR mi.to_team_id=t.id)
                   AND mt.created_at >= (SELECT COALESCE(MAX(sp.start_date),'1900-01-01') FROM sprints sp WHERE sp.league = t.league AND sp.status='active')) AS count
        FROM teams t
        ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($taRaw as $r) $tradesAceitasMap[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    sortLeagueData($tradesAceitasMap);
} catch (Exception) {}

// ── Picks de 1ª rodada movimentadas em trocas ──────────────────────
//
// "Quem mexe com pick de 1ª" é outra pergunta que "quem faz mais trades":
// um time pode fechar dez trocas de banco e nunca tocar numa escolha de
// primeira, e outro faz duas trocas levando quatro picks. Por isso a conta é
// de PICKS, e não de trades — uma troca que leva três picks de 1ª vale três.
//
// Só 1ª rodada (picks.round = '1'), só troca ACEITA, e a mesma sprint ativa
// das outras contas de trade daqui.
//
// Nas trocas de dois times a pick sempre passa de um pro outro, então ela
// conta pros dois lados. Na multi-trade o corte é pelo ITEM: conta a pick que
// ESTE time deu ou recebeu, não a que dois terceiros trocaram entre si no
// mesmo acordo.
$picks1rMap = [];
try {
    $p1Raw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name,
               (SELECT COUNT(*) FROM trades tr
                  JOIN trade_items ti ON ti.trade_id = tr.id
                  JOIN picks pk ON pk.id = ti.pick_id
                 WHERE tr.status='accepted' AND pk.round = '1'
                   AND (tr.from_team_id=t.id OR tr.to_team_id=t.id)
                   AND tr.created_at >= (SELECT COALESCE(MAX(sp.start_date),'1900-01-01') FROM sprints sp WHERE sp.league = t.league AND sp.status='active'))
             + (SELECT COUNT(*) FROM multi_trades mt
                  JOIN multi_trade_items mi ON mi.trade_id = mt.id
                  JOIN picks pk ON pk.id = mi.pick_id
                 WHERE mt.status='accepted' AND pk.round = '1'
                   AND (mi.from_team_id=t.id OR mi.to_team_id=t.id)
                   AND mt.created_at >= (SELECT COALESCE(MAX(sp.start_date),'1900-01-01') FROM sprints sp WHERE sp.league = t.league AND sp.status='active')) AS count
        FROM teams t
        ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($p1Raw as $r) $picks1rMap[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    sortLeagueData($picks1rMap);
} catch (Exception) {}

// ── Trades recusadas ───────────────────────────────────────────────
$tradesRecusadasMap = [];
try {
    $trRaw = $pdo->query("
        SELECT t.league, CONCAT(t.city,' ',t.name) AS name, COUNT(tr.id) AS count
        FROM teams t
        LEFT JOIN trades tr ON (tr.from_team_id=t.id OR tr.to_team_id=t.id)
                           AND tr.status='rejected'
                           AND tr.created_at >= (SELECT COALESCE(MAX(sp.start_date),'1900-01-01') FROM sprints sp WHERE sp.league = t.league AND sp.status='active')
        GROUP BY t.league, t.id, t.city, t.name ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($trRaw as $r) $tradesRecusadasMap[$r['league']][] = ['name'=>$r['name'],'count'=>(int)$r['count']];
    sortLeagueData($tradesRecusadasMap);
} catch (Exception) {}



?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Estatísticas · FBA</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:color-mix(in srgb, var(--red) 10%, transparent);--border-red:color-mix(in srgb, var(--red) 25%, transparent);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--border:rgba(255,255,255,.07);--border-md:rgba(255,255,255,.12);--text:#f0f0f3;--text-2:#868690;--text-3:#7d7d85;--amber:#f59e0b;--green:#22c55e;--purple:#a855f7;--blue:#60a5fa;--radius-sm:10px;--font:'Montserrat', sans-serif;--sidebar-w:260px;--t:.2s;--ease:cubic-bezier(.4,0,.2,1)}
:root[data-theme="light"]{--bg:#f6f7fb;--panel:#ffffff;--panel-2:#f2f4f8;--border:#e3e6ee;--border-md:#d7dbe6;--border-red:color-mix(in srgb, var(--red) 18%, transparent);--text:#111217;--text-2:#5b6270;--text-3:#657080}
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
.sb-nav a { font-family:'Inter',sans-serif;display:flex;align-items:center;gap:10px;padding:10px 10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t) var(--ease)}
.sb-nav a i{font-size:15px;width:18px;text-align:center;flex-shrink:0}
.sb-nav a:hover{background:var(--panel-2);color:var(--text)}
.sb-nav a.active{background:var(--red-soft);color:var(--red);font-weight:600}
.sb-nav a.active i{color:var(--red)}
.sb-theme-toggle{margin:0 14px 12px;padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--panel-2);color:var(--text);display:flex;align-items:center;justify-content:center;gap:8px;font-size:12px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease)}
.sb-theme-toggle:hover{border-color:var(--border-red);color:var(--red)}
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
.dash-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px}
.page-title{font-family:'Oswald',sans-serif;font-size:24px;font-weight:700;margin-bottom:4px;display:flex;align-items:center;gap:10px}
.page-title i{color:var(--red)}
.dash-sub{font-size:13px;color:var(--text-2);margin-bottom:16px}

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

/* As seções lado a lado: três por linha na tela cheia, duas no meio-termo,
   uma no celular. Largura fixa por coluna (1fr) em vez de auto-fill pra que
   a linha seja sempre a mesma — auto-fill dava duas colunas gordas em 1280. */
.stats-flow{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px;align-items:start;margin-top:8px}
@media (max-width:1180px){.stats-flow{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:760px){.stats-flow{grid-template-columns:minmax(0,1fr);gap:14px}}
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
.league-badge{font-family:'Oswald',sans-serif;font-size:11px;font-weight:700;padding:2px 10px;border-radius:999px;letter-spacing:.5px;flex-shrink:0}
.badge-ELITE{background:rgba(251,191,36,.12);color:#fbbf24;border:1px solid rgba(251,191,36,.25)}
.badge-NEXT{background:rgba(99,102,241,.12);color:#818cf8;border:1px solid rgba(99,102,241,.25)}
.badge-RISE{background:rgba(34,197,94,.12);color:#4ade80;border:1px solid rgba(34,197,94,.25)}
.badge-ROOKIE{background:rgba(168,85,247,.12);color:#c084fc;border:1px solid rgba(168,85,247,.25)}
.copy-btn{margin-left:auto;background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-3);border-radius:7px;padding:4px 10px;font-size:10px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:4px;transition:all .2s;white-space:nowrap;flex-shrink:0}
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
.rank-row.my-team{background:color-mix(in srgb, var(--red) 10%, transparent);border-left:3px solid var(--red)}
.rank-row.my-team .rname{color:#fff;font-weight:700}
.rank-row.my-team .rn{color:var(--red)}
.my-team-sep{height:1px;background:color-mix(in srgb, var(--red) 20%, transparent);margin:2px 0}
.my-team-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--red);padding:5px 14px 2px}

/* Pair rows */
.pair-row{display:flex;align-items:center;gap:8px;padding:6px 14px;border-bottom:1px solid var(--border)}
.pair-row:last-child{border-bottom:none}
.pair-names{flex:1;min-width:0;display:flex;flex-direction:column;gap:1px}
.pair-a{font-size:11px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pair-b{font-size:11px;font-weight:500;color:var(--text-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pair-row.my-team{background:color-mix(in srgb, var(--red) 10%, transparent);border-left:3px solid var(--red)}
.pair-row.my-team .pair-a{color:#fff;font-weight:700}
.pair-row.my-team .rn{color:var(--red)}

.empty-state{padding:16px 14px;color:var(--text-3);text-align:center}
.empty-state i{font-size:22px;display:block;margin-bottom:6px}
.empty-state p{font-size:11px;margin:0}
<?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
  <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
  <div class="topbar-title">FBA <em>Estatísticas</em></div>
</header>

<div class="main">
<div class="main-inner">
<div class="dash-eyebrow">FBA Brasil · Liga</div>
<div class="page-title"><i class="bi bi-bar-chart-line-fill"></i> Estatísticas</div>
<div class="dash-sub">Recordes e curiosidades das três ligas do sistema</div>
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

// ═══════════════════════════════════════════════════════════════════════
// PLAYOFF — títulos, dinastia, eterno vice, seed médio, rivalidades e domínio.
//
// Saem do registro de fim de temporada (playoff_results, team_ranking_points
// e playoff_series), com a MESMA conta do bot: está toda em
// backend/estatisticas_playoff.php. Antes liam playoff_brackets e
// playoff_matches, que o fechamento de temporada não preenche mais — na
// sprint ativa as duas estão vazias e as seis seções saíam "Sem dados".
// Sweeps, jogo 7 e margem nas finais, logo abaixo, leem playoff_series direto.
// ═══════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/backend/estatisticas_playoff.php';
$titulosMap    = epSeguro(fn() => epTitulos($pdo), 'titulos');
$dinastiaMap   = epSeguro(fn() => epDinastia($pdo), 'dinastia');
$eternoViceMap = epSeguro(fn() => epEternoVice($pdo), 'eterno vice');
$seedMap       = epSeguro(fn() => epSeedMedio($pdo), 'seed medio');
$rivaisMap     = epSeguro(fn() => epRivalidades($pdo), 'rivalidades');
$dominioMap    = epSeguro(fn() => epDominio($pdo), 'dominio');

// ── As que dependem do número de jogos da série ──────────────────────
//
// Só playoff_series sabe isso. Enquanto a série não for lançada com o
// adversário, estas quatro aparecem vazias — de propósito: melhor uma
// seção sem dado que um número que não é verdade.
$serieOk = false;
try {
    foreach ($pdo->query("SHOW COLUMNS FROM playoff_series") as $c) {
        if ($c['Field'] === 'jogos') { $serieOk = true; break; }
    }
} catch (Exception) {}

$sweepsDadosMap = $sweepsSofridosMap = $jogo7Map = $margemMap = [];
if ($serieOk) {
    // Sweep aplicado: ganhou a série em 4 jogos.
    $sweepsDadosMap = queryByLeague($pdo, "
        SELECT ps.league, t.id AS team_id, TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS name,
               COUNT(*) AS count
        FROM playoff_series ps
        JOIN teams t ON t.id = ps.winner_team_id
        WHERE ps.jogos = 4 AND ps.season_id IN {$TEMPORADAS_DA_SPRINT}
        GROUP BY ps.league, t.id, name
        ORDER BY ps.league, count DESC, name");

    // Sweep sofrido: a série acabou em 4 e o time não é o vencedor.
    $sweepsSofridosMap = queryByLeague($pdo, "
        SELECT ps.league, t.id AS team_id, TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS name,
               COUNT(*) AS count
        FROM playoff_series ps
        JOIN teams t ON t.id = IF(ps.winner_team_id = ps.team_a_id, ps.team_b_id, ps.team_a_id)
        WHERE ps.jogos = 4 AND ps.season_id IN {$TEMPORADAS_DA_SPRINT}
        GROUP BY ps.league, t.id, name
        ORDER BY ps.league, count DESC, name");

    // Jogo 7: a série foi aos sete, para os DOIS lados — estar num jogo 7 é
    // o feito, ganhar ou perder.
    $jogo7Map = queryByLeague($pdo, "
        SELECT ps.league, t.id AS team_id, TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS name,
               COUNT(*) AS count
        FROM playoff_series ps
        JOIN teams t ON t.id IN (ps.team_a_id, ps.team_b_id)
        WHERE ps.jogos = 7 AND ps.season_id IN {$TEMPORADAS_DA_SPRINT}
        GROUP BY ps.league, t.id, name
        ORDER BY ps.league, count DESC, name");

    // Margem nas finais: jogos da série decisiva. Menos jogos = atropelo.
    // Fica no campeão, que é de quem é o feito.
    $margemMap = queryByLeague($pdo, "
        SELECT ps.league, t.id AS team_id, TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS name,
               ROUND(AVG(ps.jogos), 1) AS count
        FROM playoff_series ps
        JOIN teams t ON t.id = ps.winner_team_id
        WHERE ps.fase = 'final' AND ps.season_id IN {$TEMPORADAS_DA_SPRINT}
        GROUP BY ps.league, t.id, name
        ORDER BY ps.league, count ASC, name");
}

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

        echo "<div class=\"league-card\" data-league=\"".htmlspecialchars($lg)."\">";
        echo "<div class=\"league-header\">";
        echo "<span class=\"league-badge badge-".htmlspecialchars($lg)."\">".htmlspecialchars($lg)."</span>";
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
            echo "<div class=\"empty-state\"><i class=\"bi bi-inbox\"></i><p>Sem dados</p></div>";
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
                    $valDisplay = $r['count'] === null ? 'Sem dados' : $r['count'] . $suffix;
                    echo "<span class=\"rval {$color_hi}\">" . $valDisplay . "</span>";
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
                    $myValDisplay = $myRow['count'] === null ? 'Sem dados' : $myRow['count'] . $suffix;
                    echo "<span class=\"rval {$color_hi}\">" . $myValDisplay . "</span>";
                    echo "</div>";
                }
            }
        }

        if ($show_lo) {
            echo "<div class=\"divider\"></div>";
            echo "<div class=\"card-sub\">{$label_lo}</div>";
            if (empty($bot5)) {
                echo "<div class=\"empty-state\"><i class=\"bi bi-inbox\"></i><p>Sem dados</p></div>";
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
                            echo "<span class=\"rname\" title=\"".htmlspecialchars($r['name'])."\">" . htmlspecialchars($r['name']) . " <span class=\"rteam\">- " . htmlspecialchars($r['team']) . "</span></span>";
                        } else {
                            echo "<span class=\"rname\" title=\"".htmlspecialchars($r['name'])."\">" . htmlspecialchars($r['name']) . "</span>";
                        }
                        $botValDisplay = $r['count'] === null ? 'Sem dados' : $r['count'] . $suffix;
                        echo "<span class=\"rval {$color_lo}\">" . $botValDisplay . "</span>";
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

renderSection('playoffs', '🎯', 'color-mix(in srgb, var(--red) 12%, transparent)', 'Aparições no Playoff',
    'Times que mais chegaram ao playoff',
    $playoffMap, $leagues, [
        'label_hi' => '🎯 Mais playoffs', 'label_lo' => '📉 Menos playoffs',
        'color_hi' => 'hi', 'color_lo' => 'lo',
        'copy_hi' => 'Mais playoffs', 'copy_lo' => 'Menos playoffs',
    ], $myTeamName);


renderSection('streak', '🔥', 'rgba(251,191,36,.10)', 'Maior Sequência de Playoffs',
    'Máximo de temporadas consecutivas classificadas ao playoff',
    $streakMap, $leagues, [
        'label_hi' => '🔥 Maior sequência', 'label_lo' => '📦 Menor sequência',
        'color_hi' => 'gold', 'color_lo' => 'lo',
        'copy_hi' => 'Maior sequência de playoffs', 'copy_lo' => 'Menor sequência',
        'suffix' => ' temp',
    ], $myTeamName);

renderSection('jejum', '😴', 'rgba(148,163,184,.10)', 'Maior Jejum de Playoffs',
    'Maior sequência de temporadas consecutivas sem chegar ao playoff',
    $jejumMap, $leagues, [
        'label_hi' => '😴 Maior jejum', 'label_lo' => '✅ Menor jejum',
        'color_hi' => 'lo', 'color_lo' => 'green',
        'copy_hi' => 'Maior jejum de playoffs', 'copy_lo' => 'Menor jejum',
        'suffix' => ' temp',
    ], $myTeamName);


renderSection('titulos', '🏆', 'rgba(251,191,36,.12)', 'Ranking de Títulos',
    'Quem mais foi campeão na sprint atual',
    $titulosMap, $leagues, [
        'label_hi' => '🏆 Mais títulos', 'show_lo' => false, 'color_hi' => 'gold',
        'suffix' => '', 'copy_hi' => 'Ranking de títulos',
    ], $myTeamName);

renderSection('dinastia', '🔥', 'color-mix(in srgb, var(--red) 12%, transparent)', 'Maior Dinastia',
    'Maior sequência de títulos em temporadas seguidas',
    $dinastiaMap, $leagues, [
        'label_hi' => '🔥 Maior sequência', 'show_lo' => false, 'color_hi' => 'hi',
        'copy_hi' => 'Maior dinastia',
    ], $myTeamName);

renderSection('eterno-vice', '🥈', 'rgba(148,163,184,.10)', 'Eterno Vice',
    'Times com vice-campeonatos e nenhum título',
    $eternoViceMap, $leagues, [
        'label_hi' => '🥈 Mais vices sem taça', 'show_lo' => false, 'color_hi' => 'lo',
        'copy_hi' => 'Eterno vice',
    ], $myTeamName);

renderSection('seed-medio', '🌡️', 'rgba(96,165,250,.10)', 'Seed Médio no Playoff',
    'Posição média com que o time entra no playoff — quanto menor, mais favorito',
    $seedMap, $leagues, [
        'label_hi' => '🌡️ Melhor seed médio', 'label_lo' => '📉 Pior seed médio',
        'color_hi' => 'blue', 'color_lo' => 'lo',
        'copy_hi' => 'Melhor seed médio', 'copy_lo' => 'Pior seed médio',
    ], $myTeamName);

renderSection('margem-finais', '🎖️', 'rgba(251,191,36,.10)', 'Margem nas Finais',
    'Jogos que o campeão precisou na série decisiva — menos jogos, mais atropelo',
    $margemMap, $leagues, [
        'label_hi' => '🎖️ Mais dominante', 'label_lo' => '😅 Mais sofrido',
        'color_hi' => 'gold', 'color_lo' => 'lo',
        'copy_hi' => 'Finais mais dominantes', 'copy_lo' => 'Finais mais sofridas',
    ], $myTeamName);

renderSection('sweeps-dados', '🧹', 'rgba(34,197,94,.10)', 'Sweeps Aplicados (4-0)',
    'Séries vencidas sem perder um jogo',
    $sweepsDadosMap, $leagues, [
        'label_hi' => '🧹 Mais sweeps', 'show_lo' => false, 'color_hi' => 'green',
        'copy_hi' => 'Sweeps aplicados',
    ], $myTeamName);

renderSection('sweeps-sofridos', '🧹', 'color-mix(in srgb, var(--red) 10%, transparent)', 'Sweeps Sofridos (0-4)',
    'Séries perdidas sem vencer um jogo',
    $sweepsSofridosMap, $leagues, [
        'label_hi' => '🧹 Mais sweeps sofridos', 'show_lo' => false, 'color_hi' => 'hi',
        'copy_hi' => 'Sweeps sofridos',
    ], $myTeamName);

renderSection('jogo7', '🎬', 'rgba(251,191,36,.10)', 'Guerreiros do Jogo 7',
    'Séries que foram até o jogo decisivo (4-3) — vale pros dois lados',
    $jogo7Map, $leagues, [
        'label_hi' => '🎬 Mais jogos 7', 'show_lo' => false, 'color_hi' => 'gold',
        'copy_hi' => 'Guerreiros do jogo 7',
    ], $myTeamName);


renderSection('rivais', '⚔️', 'rgba(96,165,250,.12)', 'Maiores Rivalidades',
    'Duplas que mais se enfrentaram no playoff',
    $rivaisMap, $leagues, [
        'label_hi' => '⚔️ Mais confrontos', 'show_lo' => false, 'color_hi' => 'blue',
        'pair_mode' => true, 'copy_hi' => 'Maiores rivalidades',
    ], $myTeamName);

renderSection('dominio', '💀', 'color-mix(in srgb, var(--red) 10%, transparent)', 'Domínio Total',
    'Duplas em que um time venceu TODOS os confrontos do playoff',
    $dominioMap, $leagues, [
        'label_hi' => '💀 Freguesia', 'show_lo' => false, 'color_hi' => 'hi',
        'pair_mode' => true, 'pair_sep' => 'sobre', 'copy_hi' => 'Domínio total',
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

renderSection('draft-ovr', '📈', 'rgba(168,85,247,.10)', 'Aproveitamento do Draft',
    'OVR médio dos jogadores draftados pelo time',
    $draftOvrMap, $leagues, [
        'label_hi' => '📈 Melhor aproveitamento', 'label_lo' => '📉 Menor aproveitamento',
        'color_hi' => 'purple', 'color_lo' => 'lo',
        'copy_hi' => 'Melhor aproveitamento do draft', 'copy_lo' => 'Menor aproveitamento do draft',
    ], $myTeamName);




renderSection('fa', '🖊️', 'rgba(34,197,94,.10)', 'Free Agency',
    'Times que mais e menos assinaram jogadores na FA',
    $faMap, $leagues, [
        'label_hi' => '🖊️ Mais contratações', 'label_lo' => '📦 Menos contratações',
        'color_hi' => 'green', 'color_lo' => 'lo',
        'copy_hi' => 'Mais FA pickups', 'copy_lo' => 'Menos FA pickups',
    ], $myTeamName);


// ─── Novas seções ─────────────────────────────────────────────────

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

renderSection('parceiros', '🌐', 'rgba(168,85,247,.10)', 'Diversidade de Parceiros de Trade',
    'Times que negociaram com mais (ou menos) franquias diferentes (só aceitas)',
    $parceirosMap, $leagues, [
        'label_hi' => '🌐 Mais parceiros', 'label_lo' => '🏝️ Menos interativos',
        'color_hi' => 'purple', 'color_lo' => 'lo',
        'copy_hi' => 'Mais parceiros de trade', 'copy_lo' => 'Menos interativos',
        'suffix' => ' times',
    ], $myTeamName);

renderSection('ofertas', '📤', 'rgba(251,191,36,.10)', 'Mais Ofertas de Trade Enviadas',
    'Times que mais propuseram trades',
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

renderSection('picks-1a-rodada', '🎯', 'rgba(56,189,248,.10)', 'Picks de 1ª Rodada Trocadas',
    'Quantas escolhas de 1ª rodada cada time deu ou recebeu em trocas aceitas',
    $picks1rMap, $leagues, [
        // Sem o lado "menos": não trocar pick de 1ª é o normal, e uma coluna
        // de zeros não é notícia — é a mesma régua dos títulos e dos sweeps.
        'label_hi' => '🎯 Mais picks de 1ª', 'show_lo' => false, 'color_hi' => 'blue',
        'copy_hi' => 'Mais picks de 1ª rodada trocadas',
    ], $myTeamName);

// ─── Playoff: as dez que antes existiam só pra RISE, agora nas quatro ligas ─
//
// "Mais Aparições em Playoff — Histórico" saiu: é a mesma coisa que a seção
// "Aparições no Playoff" lá em cima, que já vale pra todas as ligas.

renderSection('trades-recusadas', '❌', 'color-mix(in srgb, var(--red) 10%, transparent)', 'Trades Recusadas',
    'Times envolvidos no maior número de trades rejeitadas',
    $tradesRecusadasMap, $leagues, [
        'label_hi' => '❌ Mais recusadas', 'label_lo' => '✅ Menos recusadas',
        'color_hi' => 'hi', 'color_lo' => 'green',
        'copy_hi' => 'Mais trades recusadas', 'copy_lo' => 'Menos trades recusadas',
    ], $myTeamName);

renderSection('punicoes', '⚠️', 'color-mix(in srgb, var(--red) 12%, transparent)', 'Punições Recebidas',
    'Times que mais receberam punições ativas na liga',
    $punicoesMap, $leagues, [
        'label_hi' => '⚠️ Mais punições', 'label_lo' => '✅ Menos punições',
        'color_hi' => 'hi', 'color_lo' => 'green',
        'copy_hi' => 'Mais punições', 'copy_lo' => 'Menos punições',
    ], $myTeamName);

echo '</div>'; // .stats-flow


?>
</div><!-- .main-inner -->
</div><!-- .main -->

<script>
// Tema (claro/escuro)
const themeToggle = document.getElementById('themeToggle');
const themeKey = 'fba-theme';
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
