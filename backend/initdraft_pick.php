<?php
/**
 * Núcleo compartilhado do Draft Inicial: fazer uma pick e avisar quem interessa.
 *
 * Isto existia duplicado em api/initdraft.php e cron/initdraft-daily.php, e as
 * duas cópias tinham divergido no que mais importa. A do cron:
 *   - não tinha as travas atômicas, então duas execuções simultâneas podiam
 *     gravar a mesma pick ou draftar o mesmo jogador duas vezes;
 *   - inseria o jogador sem draft_round/draft_pick_position, e sem isso a
 *     rookie scale não sabe a posição do pick e cai no piso de 2M;
 *   - não fixava loyal_override=0, deixando o calouro virar "Leal" por
 *     coincidência de nome com o pool de outra temporada.
 *
 * Agora é uma implementação só — a da API, que era a completa — usada pela API
 * e pelos crons. Quem mexer na regra da pick mexe aqui e vale para todos.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';   // ensurePlayerRestrictionColumns
require_once __DIR__ . '/push.php';      // sendPushToLeague / sendPushToTeam

/**
 * Avisa a liga do draft que uma pick foi feita — só quem está na mesma liga
 * da sessão (nunca vaza pra outra liga). Best-effort: nunca derruba a pick.
 */
function notificarInitDraftPick(PDO $pdo, array $session, int $teamId, string $playerName, int $round, int $pickPosition): void {
    try {
        $stmtTeam = $pdo->prepare('SELECT city, name FROM teams WHERE id = ? LIMIT 1');
        $stmtTeam->execute([$teamId]);
        $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
        $teamName = $team ? trim(($team['city'] ?? '') . ' ' . ($team['name'] ?? '')) : 'Um time';

        sendPushToLeague($pdo, (string)$session['league'], [
            'title' => '🏀 Draft Inicial',
            'body'  => "{$teamName} escolheu {$playerName}! (Rodada {$round} · Pick {$pickPosition})",
            'url'   => '/initdraftselecao.php?token=' . $session['access_token'],
        ], 'draft');
    } catch (Throwable $e) {
        error_log('[notificarInitDraftPick] ' . $e->getMessage());
    }
}

/** Avisa o dono do próximo time que é a vez dele. Best-effort. */
function notificarInitDraftVez(PDO $pdo, array $session, int $teamId, int $round, int $pickPosition): void {
    try {
        sendPushToTeam($pdo, $teamId, [
            'title'      => '🏀 É a sua vez no Draft Inicial!',
            'body'       => "Rodada {$round} · Pick #{$pickPosition} — escolha seu jogador.",
            'url'        => '/initdraftselecao.php?token=' . $session['access_token'],
            'primaryKey' => 'initdraft_pick_' . $teamId . '_' . $round . '_' . $pickPosition,
        ], 'draft');
    } catch (Throwable $e) {
        error_log('[notificarInitDraftVez] ' . $e->getMessage());
    }
}

function performInitDraftPick(PDO $pdo, array $session, int $playerId): void {
    if (!$session) {
        throw new InvalidArgumentException('Sessão inválida');
    }
    if ($session['status'] !== 'in_progress') {
        throw new InvalidArgumentException('Draft não está em andamento');
    }
    // A trava do admin mora aqui, e não em make_pick, porque esta função é o
    // ÚNICO caminho que grava pick: o site, o autopick do navegador e o cron do
    // mock passam todos por ela. Barrar em cada um deles deixaria brecha na
    // primeira via nova que alguém escrevesse.
    if (!empty($session['pausado'])) {
        throw new InvalidArgumentException('O draft está pausado pelo admin');
    }
    if ($playerId <= 0) {
        throw new InvalidArgumentException('player_id obrigatório');
    }

    $sessionRound = (int)($session['current_round'] ?? 1);
    $sessionPick = (int)($session['current_pick'] ?? 1);

    $stmtPick = $pdo->prepare('SELECT * FROM initdraft_order WHERE initdraft_session_id = ? AND round = ? AND pick_position = ? AND picked_player_id IS NULL');
    $stmtPick->execute([$session['id'], $sessionRound, $sessionPick]);
    $currentPick = $stmtPick->fetch(PDO::FETCH_ASSOC);

    if (!$currentPick) {
        $stmtPick = $pdo->prepare('SELECT * FROM initdraft_order WHERE initdraft_session_id = ? AND picked_player_id IS NULL ORDER BY round ASC, pick_position ASC LIMIT 1');
        $stmtPick->execute([$session['id']]);
        $currentPick = $stmtPick->fetch(PDO::FETCH_ASSOC);
        if (!$currentPick) {
            throw new InvalidArgumentException('Todas as picks já foram realizadas');
        }
        $sessionRound = (int)$currentPick['round'];
        $sessionPick = (int)$currentPick['pick_position'];
        $pdo->prepare('UPDATE initdraft_sessions SET current_round = ?, current_pick = ? WHERE id = ?')
            ->execute([$sessionRound, $sessionPick, $session['id']]);
    }

    $stmtP = $pdo->prepare('SELECT * FROM initdraft_pool WHERE id = ? AND draft_status = "available"');
    $stmtP->execute([$playerId]);
    $player = $stmtP->fetch(PDO::FETCH_ASSOC);
    if (!$player) {
        throw new InvalidArgumentException('Jogador indisponível');
    }

    try {
        $pdo->beginTransaction();

        // Trava atômica da pick: só avança se ela ainda estiver livre (evita duplo-submit/corrida entre requisições concorrentes)
        $stmtLockPick = $pdo->prepare('UPDATE initdraft_order SET picked_player_id = ?, picked_at = NOW() WHERE id = ? AND picked_player_id IS NULL');
        $stmtLockPick->execute([$playerId, $currentPick['id']]);
        if ($stmtLockPick->rowCount() === 0) {
            throw new InvalidArgumentException('Esta pick já foi realizada em outra requisição.');
        }

        $stmtRoundSize = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND round = ?');
        $stmtRoundSize->execute([$session['id'], $sessionRound]);
        $roundSize = max(1, (int)$stmtRoundSize->fetchColumn());
        $pickNumber = (($sessionRound - 1) * $roundSize) + $sessionPick;

        // Trava atômica do jogador: só avança se ele ainda estiver disponível (evita o mesmo jogador ser draftado 2x)
        $stmtLockPlayer = $pdo->prepare('UPDATE initdraft_pool SET draft_status = "drafted", drafted_by_team_id = ?, draft_order = ? WHERE id = ? AND draft_status = "available"');
        $stmtLockPlayer->execute([$currentPick['team_id'], $pickNumber, $playerId]);
        if ($stmtLockPlayer->rowCount() === 0) {
            throw new InvalidArgumentException('Jogador indisponível — já foi selecionado por outra requisição.');
        }

        // loyal_override=0 fixo: jogador do Draft Inicial nunca é "Leal", nem por
        // coincidência de nome com uma entrada de draft_pool de outra temporada
        // (o próprio nome real de jogador NBA pode repetir entre os dois pools).
        ensurePlayerRestrictionColumns($pdo);
        $pdo->prepare('INSERT INTO players (team_id, drafted_by_team_id, draft_round, draft_pick_position, name, position, age, ovr, role, available_for_trade, loyal_override) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "Banco", 0, 0)')
            ->execute([$currentPick['team_id'], $currentPick['team_id'], $sessionRound, $sessionPick, $player['name'], $player['position'], $player['age'], $player['ovr']]);

        $nextPick = $sessionPick + 1;
        $nextRound = $sessionRound;
        $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND round = ?');
        $stmtCount->execute([$session['id'], $nextRound]);
        $totalPicks = (int)$stmtCount->fetchColumn();

        $completed = false;
        if ($nextPick > $totalPicks) {
            $nextRound++;
            $nextPick = 1;
            if ($nextRound > (int)$session['total_rounds']) {
                $pdo->prepare('UPDATE initdraft_sessions SET status = "completed", completed_at = NOW(), current_round = ?, current_pick = ? WHERE id = ?')
                    ->execute([$sessionRound, $sessionPick, $session['id']]);
                $completed = true;
            } else {
                $pdo->prepare('UPDATE initdraft_sessions SET current_round = ?, current_pick = ? WHERE id = ?')
                    ->execute([$nextRound, $nextPick, $session['id']]);
            }
        } else {
            $pdo->prepare('UPDATE initdraft_sessions SET current_round = ?, current_pick = ? WHERE id = ?')
                ->execute([$nextRound, $nextPick, $session['id']]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Notificações — best-effort, disparam só depois da pick confirmada e
    // nunca derrubam a resposta se algo der errado aqui.
    notificarInitDraftPick($pdo, $session, (int)$currentPick['team_id'], (string)$player['name'], $sessionRound, $sessionPick);
    if (!$completed) {
        $stmtNext = $pdo->prepare('SELECT team_id FROM initdraft_order WHERE initdraft_session_id = ? AND round = ? AND pick_position = ?');
        $stmtNext->execute([$session['id'], $nextRound, $nextPick]);
        $nextTeamId = (int)($stmtNext->fetchColumn() ?: 0);
        if ($nextTeamId) {
            notificarInitDraftVez($pdo, $session, $nextTeamId, $nextRound, $nextPick);
        }
    }
}
