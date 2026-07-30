<?php
/**
 * Resolução automática da Free Agency (chamada pelo agendador ao "fechar FA").
 *
 * Para cada jogador em aberto (fa_requests.status='open'), o vencedor é a MAIOR
 * oferta em moedas; empate → maior prioridade do time (1=Alta) → quem ofertou
 * primeiro. Respeita o saldo de moedas e o limite de 3 contratações por time:
 * cada time honra suas ofertas de maior prioridade dentro do que cabe; o jogador
 * que ele não levar vai pro próximo maior lance. Sem oferta viável → segue livre.
 * Ao fim, fecha a janela da FA (fa_enabled = 0) da liga.
 */
require_once __DIR__ . '/db.php';

function faCol(PDO $pdo, string $table, string $col): bool
{
    try { return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch(); }
    catch (Exception $e) { return false; }
}

function faSetEnabled(PDO $pdo, string $league, int $enabled): void
{
    if (!faCol($pdo, 'league_settings', 'fa_enabled')) return;
    $st = $pdo->prepare("SELECT id FROM league_settings WHERE league = ?");
    $st->execute([$league]);
    if ($st->fetchColumn()) $pdo->prepare("UPDATE league_settings SET fa_enabled = ? WHERE league = ?")->execute([$enabled, $league]);
    else $pdo->prepare("INSERT INTO league_settings (league, fa_enabled) VALUES (?, ?)")->execute([$league, $enabled]);
}

function faTeamCoins(PDO $pdo, int $teamId): int
{
    $st = $pdo->prepare("SELECT COALESCE(moedas,0) FROM teams WHERE id = ?");
    $st->execute([$teamId]);
    return (int)$st->fetchColumn();
}

function faTeamSlotsLeft(PDO $pdo, int $teamId): int
{
    $used = 0;
    if (faCol($pdo, 'teams', 'fa_signings_used')) {
        $st = $pdo->prepare("SELECT COALESCE(fa_signings_used,0) FROM teams WHERE id = ?");
        $st->execute([$teamId]);
        $used = (int)$st->fetchColumn();
    }
    return max(0, 3 - $used);
}

/** Cria o jogador no time vencedor, desconta moedas, fecha o request e as ofertas. */
function faAssign(PDO $pdo, array $req, array $offer): void
{
    $teamId = (int)$offer['team_id'];
    $amount = (int)$offer['amount'];

    $cols = ['team_id', 'name', 'age', 'position', 'ovr'];
    $vals = [$teamId, $req['player_name'], (int)$req['age'], $req['position'], (int)$req['ovr']];
    if (faCol($pdo, 'players', 'secondary_position')) { $cols[] = 'secondary_position'; $vals[] = $req['secondary_position'] ?: null; }
    if (faCol($pdo, 'players', 'seasons_in_league'))   { $cols[] = 'seasons_in_league';   $vals[] = 0; }
    if (faCol($pdo, 'players', 'role'))                { $cols[] = 'role';                $vals[] = 'Banco'; }
    if (faCol($pdo, 'players', 'available_for_trade')) { $cols[] = 'available_for_trade';  $vals[] = 0; }
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO players (" . implode(',', $cols) . ") VALUES ($ph)")->execute($vals);

    $pdo->prepare("UPDATE teams SET moedas = COALESCE(moedas,0) - ? WHERE id = ?")->execute([$amount, $teamId]);
    if (faCol($pdo, 'teams', 'fa_signings_used')) {
        $pdo->prepare("UPDATE teams SET fa_signings_used = COALESCE(fa_signings_used,0) + 1 WHERE id = ?")->execute([$teamId]);
    }
    try {
        $bal = faTeamCoins($pdo, $teamId);
        $pdo->prepare("INSERT INTO team_coins_log (team_id, amount, balance_after, reason, type) VALUES (?,?,?,?,?)")
            ->execute([$teamId, -$amount, $bal, 'FA (fechamento): ' . $req['player_name'], 'fa']);
    } catch (Exception $e) {}

    $pdo->prepare("UPDATE fa_requests SET status='assigned', winner_team_id=?, resolved_at=NOW() WHERE id=?")->execute([$teamId, (int)$req['id']]);
    $pdo->prepare("UPDATE fa_request_offers SET status = CASE WHEN id=? THEN 'accepted' ELSE 'rejected' END WHERE request_id=? AND status='pending'")
        ->execute([(int)$offer['id'], (int)$req['id']]);
}

function resolveFreeAgencyForLeague(PDO $pdo, string $league): array
{
    $league = strtoupper($league);

    $rs = $pdo->prepare("SELECT id, player_name, position, secondary_position, age, ovr FROM fa_requests WHERE league = ? AND status = 'open'");
    $rs->execute([$league]);
    $requests = [];
    foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) $requests[(int)$r['id']] = $r;
    if (!$requests) { faSetEnabled($pdo, $league, 0); return ['assigned' => 0, 'no_winner' => 0]; }

    $ids = array_keys($requests);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $os = $pdo->prepare("SELECT id, request_id, team_id, amount, priority, created_at FROM fa_request_offers WHERE status='pending' AND request_id IN ($ph)");
    $os->execute($ids);
    $offers = [];
    foreach ($os->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $offers[(int)$o['request_id']][] = [
            'id' => (int)$o['id'], 'team_id' => (int)$o['team_id'], 'amount' => (int)$o['amount'],
            'priority' => (int)$o['priority'], 'created_at' => (string)$o['created_at'],
        ];
    }

    // Saldo e vagas por time (só os que ofertaram).
    $teamCoins = []; $teamSlots = [];
    foreach ($offers as $list) foreach ($list as $o) {
        $t = $o['team_id'];
        if (!isset($teamCoins[$t])) { $teamCoins[$t] = faTeamCoins($pdo, $t); $teamSlots[$t] = faTeamSlotsLeft($pdo, $t); }
    }

    $removed = []; // "rid:team" => true (oferta eliminada)
    $confirmed = []; // rid => offer
    $openIds = $ids;

    // Melhor oferta válida de um request (respeita saldo/vagas atuais e removidos).
    $topOffer = function ($rid) use (&$offers, &$removed, &$teamCoins, &$teamSlots) {
        $best = null;
        foreach ($offers[$rid] ?? [] as $o) {
            if (isset($removed[$rid . ':' . $o['team_id']])) continue;
            if ($teamSlots[$o['team_id']] <= 0) continue;
            if ($teamCoins[$o['team_id']] < $o['amount']) continue;
            if ($best === null) { $best = $o; continue; }
            if ($o['amount'] > $best['amount']) { $best = $o; continue; }
            if ($o['amount'] === $best['amount']) {
                if ($o['priority'] < $best['priority']) { $best = $o; continue; }            // 1 = mais alta
                if ($o['priority'] === $best['priority']) {
                    if ($o['created_at'] < $best['created_at']) { $best = $o; continue; }
                    if ($o['created_at'] === $best['created_at'] && $o['id'] < $best['id']) $best = $o;
                }
            }
        }
        return $best;
    };

    $pdo->beginTransaction();
    try {
        $guard = 0;
        while ($openIds && $guard++ < 100000) {
            // 1) provisório = top de cada request aberto
            $prov = [];
            $noWin = [];
            foreach ($openIds as $rid) {
                $t = $topOffer($rid);
                if ($t === null) $noWin[] = $rid; else $prov[$rid] = $t;
            }
            if ($noWin) $openIds = array_values(array_diff($openIds, $noWin));
            if (!$prov) break;

            // 2) agrupa por time; honra prioridade dentro do saldo/vagas
            $byTeam = [];
            foreach ($prov as $rid => $o) $byTeam[$o['team_id']][] = $rid;

            $confirmRound = []; $removedRound = 0;
            foreach ($byTeam as $tid => $rids) {
                usort($rids, function ($a, $b) use ($prov) {
                    $oa = $prov[$a]; $ob = $prov[$b];
                    if ($oa['priority'] !== $ob['priority']) return $oa['priority'] <=> $ob['priority']; // 1 primeiro
                    if ($oa['amount']   !== $ob['amount'])   return $ob['amount']   <=> $oa['amount'];
                    return strcmp($oa['created_at'], $ob['created_at']);
                });
                $coins = $teamCoins[$tid]; $slots = $teamSlots[$tid];
                foreach ($rids as $rid) {
                    $o = $prov[$rid];
                    if ($slots > 0 && $coins >= $o['amount']) { $confirmRound[$rid] = $o; $coins -= $o['amount']; $slots--; }
                    else { $removed[$rid . ':' . $tid] = true; $removedRound++; }
                }
            }

            // 3) commit dos confirmados
            foreach ($confirmRound as $rid => $o) {
                faAssign($pdo, $requests[$rid], $o);
                $teamCoins[$o['team_id']] -= $o['amount'];
                $teamSlots[$o['team_id']] -= 1;
                $confirmed[$rid] = $o;
            }
            $openIds = array_values(array_diff($openIds, array_keys($confirmRound)));

            if (!$confirmRound && !$removedRound) break; // estável
        }

        faSetEnabled($pdo, $league, 0);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    $withOffers = array_keys($offers);
    $noWinner = count(array_filter($withOffers, fn($rid) => !isset($confirmed[$rid])));
    return ['assigned' => count($confirmed), 'no_winner' => $noWinner];
}

/** Reabre a janela da FA da liga. */
function openFreeAgencyForLeague(PDO $pdo, string $league): void
{
    faSetEnabled($pdo, strtoupper($league), 1);
}
