<?php
/**
 * Cron de auto-pick do Mock Draft
 * Executa a cada minuto e dispara o auto-pick apos 30 min na vez do time.
 */

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/push.php';

$pdo = db();

try {
    $pdo->exec("ALTER TABLE draft_sessions ADD COLUMN current_pick_started_at DATETIME NULL");
} catch (Exception $e) {}

function notifyNextPickCron(PDO $pdo, int $teamId, int $round, int $pickPosition): void {
    $stmt = $pdo->prepare('SELECT u.id FROM teams t JOIN users u ON t.user_id = u.id WHERE t.id = ? LIMIT 1');
    $stmt->execute([$teamId]);
    $userId = (int)($stmt->fetchColumn() ?: 0);
    if (!$userId) return;

    sendPushToUser($pdo, $userId, [
        'title'      => '🏀 É a sua vez no Draft!',
        'body'       => "Rodada {$round} · Pick #{$pickPosition} — Você tem 30 min para escolher.",
        'url'        => '/drafts.php',
        'primaryKey' => 'draft_pick_' . $teamId . '_' . $round . '_' . $pickPosition,
    ]);
}

function runAutopickForSession(PDO $pdo, int $draftSessionId): void {
    $stmtSess = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "in_progress"');
    $stmtSess->execute([$draftSessionId]);
    $session = $stmtSess->fetch(PDO::FETCH_ASSOC);
    if (!$session) return;

    $stmtPick = $pdo->prepare('
        SELECT * FROM draft_order
        WHERE draft_session_id = ? AND round = ? AND pick_position = ? AND picked_player_id IS NULL
    ');
    $stmtPick->execute([$draftSessionId, (int)$session['current_round'], (int)$session['current_pick']]);
    $currentPick = $stmtPick->fetch(PDO::FETCH_ASSOC);
    if (!$currentPick) return;

    $currentTeamId = (int)$currentPick['team_id'];

    $stmtSettings = $pdo->prepare('SELECT is_active FROM draft_mock_settings WHERE team_id = ? AND draft_session_id = ?');
    $stmtSettings->execute([$currentTeamId, $draftSessionId]);
    $settings = $stmtSettings->fetch(PDO::FETCH_ASSOC);
    if (empty($settings['is_active'])) return;

    $startedAt = $session['current_pick_started_at'] ?? null;
    if (!$startedAt) {
        $pdo->prepare('UPDATE draft_sessions SET current_pick_started_at = NOW() WHERE id = ?')
            ->execute([$draftSessionId]);
        return;
    }

    $elapsed = time() - strtotime($startedAt);
    if ($elapsed < 1800) return;

    $stmtQueue = $pdo->prepare('
        SELECT mq.player_id, dp.name, dp.position, dp.age, dp.ovr
        FROM draft_mock_queue mq
        JOIN draft_pool dp ON mq.player_id = dp.id
        WHERE mq.team_id = ? AND mq.draft_session_id = ?
          AND dp.draft_status = "available"
        ORDER BY mq.priority ASC
        LIMIT 1
    ');
    $stmtQueue->execute([$currentTeamId, $draftSessionId]);
    $playerToPick = $stmtQueue->fetch(PDO::FETCH_ASSOC);
    if (!$playerToPick) return;

    $pdo->beginTransaction();
    try {
        $playerId = (int)$playerToPick['player_id'];

        $pdo->prepare('UPDATE draft_order SET picked_player_id = ?, picked_at = NOW(), team_id = ? WHERE id = ? AND picked_player_id IS NULL')
            ->execute([$playerId, $currentTeamId, (int)$currentPick['id']]);

        $stmtTotalRound = $pdo->prepare('SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ? AND round = ?');
        $stmtTotalRound->execute([$draftSessionId, (int)$currentPick['round']]);
        $roundSize = (int)$stmtTotalRound->fetchColumn();
        $pickNumber = (((int)$currentPick['round'] - 1) * $roundSize) + (int)$currentPick['pick_position'];

        $pdo->prepare('UPDATE draft_pool SET draft_status = "drafted", drafted_by_team_id = ?, draft_order = ? WHERE id = ?')
            ->execute([$currentTeamId, $pickNumber, $playerId]);

        $stmtChk = $pdo->prepare('SELECT id FROM players WHERE team_id = ? AND name = ? LIMIT 1');
        $stmtChk->execute([$currentTeamId, $playerToPick['name']]);
        if (!$stmtChk->fetchColumn()) {
            $pdo->prepare('INSERT INTO players (team_id, drafted_by_team_id, name, position, age, ovr, role, available_for_trade) VALUES (?, ?, ?, ?, ?, ?, "Banco", 0)')
                ->execute([$currentTeamId, $currentTeamId, $playerToPick['name'], $playerToPick['position'], (int)$playerToPick['age'], (int)$playerToPick['ovr']]);
        }

        $stmtNext = $pdo->prepare('SELECT round, pick_position FROM draft_order WHERE draft_session_id = ? AND picked_player_id IS NULL ORDER BY round ASC, pick_position ASC LIMIT 1');
        $stmtNext->execute([$draftSessionId]);
        $next = $stmtNext->fetch(PDO::FETCH_ASSOC);

        $nextTeamId = null;
        if ($next) {
            $pdo->prepare('UPDATE draft_sessions SET current_round = ?, current_pick = ?, current_pick_started_at = NOW() WHERE id = ?')
                ->execute([(int)$next['round'], (int)$next['pick_position'], $draftSessionId]);
            $stmtNextTeam = $pdo->prepare('SELECT team_id FROM draft_order WHERE draft_session_id = ? AND round = ? AND pick_position = ? LIMIT 1');
            $stmtNextTeam->execute([$draftSessionId, (int)$next['round'], (int)$next['pick_position']]);
            $nextTeamId = (int)($stmtNextTeam->fetchColumn() ?: 0);
        } else {
            $pdo->prepare('UPDATE draft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')
                ->execute([$draftSessionId]);
        }

        $pdo->commit();

        if ($nextTeamId && $next) {
            notifyNextPickCron($pdo, $nextTeamId, (int)$next['round'], (int)$next['pick_position']);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
    }
}

$stmtSessions = $pdo->query('SELECT id FROM draft_sessions WHERE status = "in_progress"');
$sessionIds = $stmtSessions ? $stmtSessions->fetchAll(PDO::FETCH_COLUMN) : [];
foreach ($sessionIds as $sid) {
    runAutopickForSession($pdo, (int)$sid);
}
