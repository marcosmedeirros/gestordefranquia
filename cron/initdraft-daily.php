<?php
// Cron: roda a cada minuto para aplicar regras do InitDraft (1 round por dia).
// - 00:01 BRT: abre o round do dia automaticamente
// - 00:01-19:30: sem relógio
// - 19:30+: relógio (10 min por pick)
// - Timeout: escolhe maior OVR disponível

require_once __DIR__ . '/../backend/db.php';

$pdo = db();

date_default_timezone_set('America/Sao_Paulo');

// Funções locais mínimos (copiadas/espelhadas da API para rodar standalone)
function tzNow(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
}

function computeDailyRoundForDate(?string $startDate, DateTimeImmutable $now): ?int {
    if (!$startDate) return null;
    $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDate, $now->getTimezone());
    if (!$start) return null;
    if ($now->format('Y-m-d') < $start->format('Y-m-d')) return null;
    $days = (int)$start->diff($now)->format('%a');
    return $days + 1;
}

function isRoundCompleted(PDO $pdo, int $sessionId, int $round): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND round = ? AND picked_player_id IS NULL');
    $stmt->execute([$sessionId, $round]);
    return (int)$stmt->fetchColumn() === 0;
}

function clearDeadlinesForRound(PDO $pdo, int $sessionId, int $round): void {
    $pdo->prepare('UPDATE initdraft_order SET deadline_at = NULL WHERE initdraft_session_id = ? AND round = ? AND picked_player_id IS NULL')
        ->execute([$sessionId, $round]);
}

function getCurrentOpenPick(PDO $pdo, int $sessionId, int $round): ?array {
    $stmt = $pdo->prepare('SELECT * FROM initdraft_order WHERE initdraft_session_id = ? AND round = ? AND picked_player_id IS NULL ORDER BY pick_position ASC LIMIT 1');
    $stmt->execute([$sessionId, $round]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function ensureDeadlineForPick(PDO $pdo, array $pick, DateTimeImmutable $now, int $pickMinutes): void {
    if (!empty($pick['deadline_at'])) return;
    $deadline = $now->add(new DateInterval('PT' . max(1, $pickMinutes) . 'M'));
    $pdo->prepare('UPDATE initdraft_order SET deadline_at = ? WHERE id = ?')
        ->execute([$deadline->format('Y-m-d H:i:s'), $pick['id']]);
}

function pickHighestOvrAvailable(PDO $pdo, int $seasonId): ?int {
    $stmt = $pdo->prepare('SELECT id FROM initdraft_pool WHERE season_id = ? AND draft_status = "available" ORDER BY ovr DESC, id ASC LIMIT 1');
    $stmt->execute([$seasonId]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function performInitDraftPick(PDO $pdo, array $session, int $playerId): void {
    // Importa lógica da API de forma focada (sem includes para evitar dependência circular)
    if (($session['status'] ?? 'setup') !== 'in_progress') return;

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
            return;
        }
        $sessionRound = (int)$currentPick['round'];
        $sessionPick = (int)$currentPick['pick_position'];
        $pdo->prepare('UPDATE initdraft_sessions SET current_round = ?, current_pick = ? WHERE id = ?')
            ->execute([$sessionRound, $sessionPick, $session['id']]);
    }

    $stmtP = $pdo->prepare('SELECT * FROM initdraft_pool WHERE id = ? AND draft_status = "available"');
    $stmtP->execute([$playerId]);
    $player = $stmtP->fetch(PDO::FETCH_ASSOC);
    if (!$player) return;

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE initdraft_order SET picked_player_id = ?, picked_at = NOW(), deadline_at = NULL WHERE id = ?')
            ->execute([$playerId, $currentPick['id']]);

        $stmtRoundSize = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND round = ?');
        $stmtRoundSize->execute([$session['id'], $sessionRound]);
        $roundSize = max(1, (int)$stmtRoundSize->fetchColumn());
        $pickNumber = (($sessionRound - 1) * $roundSize) + $sessionPick;

        $pdo->prepare('UPDATE initdraft_pool SET draft_status = "drafted", drafted_by_team_id = ?, draft_order = ? WHERE id = ?')
            ->execute([$currentPick['team_id'], $pickNumber, $playerId]);

        $pdo->prepare('INSERT INTO players (team_id, name, position, age, ovr, role, available_for_trade) VALUES (?, ?, ?, ?, ?, "Banco", 0)')
            ->execute([$currentPick['team_id'], $player['name'], $player['position'], $player['age'], $player['ovr']]);

        // avança ponteiro
        $nextPick = $sessionPick + 1;
        $nextRound = $sessionRound;
        $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order WHERE initdraft_session_id = ? AND round = ?');
        $stmtCount->execute([$session['id'], $nextRound]);
        $totalPicks = (int)$stmtCount->fetchColumn();

        if ($nextPick > $totalPicks) {
            $nextRound++;
            $nextPick = 1;
            if ($nextRound > (int)$session['total_rounds']) {
                $pdo->prepare('UPDATE initdraft_sessions SET status = "completed", completed_at = NOW(), current_round = ?, current_pick = ? WHERE id = ?')
                    ->execute([$sessionRound, $sessionPick, $session['id']]);
            } else {
                $pdo->prepare('UPDATE initdraft_sessions SET current_round = ?, current_pick = ? WHERE id = ?')
                    ->execute([$nextRound, $nextPick, $session['id']]);
            }
        } else {
            $pdo->prepare('UPDATE initdraft_sessions SET current_round = ?, current_pick = ? WHERE id = ?')
                ->execute([$nextRound, $nextPick, $session['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

$now = tzNow();
$today = $now->format('Y-m-d');

$stmt = $pdo->query("SELECT * FROM initdraft_sessions WHERE daily_schedule_enabled = 1 AND status IN ('setup','in_progress')");
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($sessions as $session) {
    $dailyRound = computeDailyRoundForDate($session['daily_schedule_start_date'] ?? null, $now);
    if (!$dailyRound) continue;
    if ($dailyRound > (int)$session['total_rounds']) continue;

    // 00:01 abre o draft (se ainda estiver setup)
    $openAfter = new DateTimeImmutable($today . ' 00:01:00', $now->getTimezone());
    if ($now >= $openAfter && ($session['daily_last_opened_date'] ?? null) !== $today) {
        if (($session['status'] ?? 'setup') === 'setup') {
            $pdo->prepare('UPDATE initdraft_sessions SET status = "in_progress", started_at = COALESCE(started_at, NOW()) WHERE id = ?')
                ->execute([$session['id']]);
        }
        $pdo->prepare('UPDATE initdraft_sessions SET daily_last_opened_date = ? WHERE id = ?')
            ->execute([$today, $session['id']]);
    }

    // Se round terminou, fecha o draft inteiro
    if (($session['status'] ?? 'setup') === 'in_progress' && isRoundCompleted($pdo, (int)$session['id'], $dailyRound)) {
        $pdo->prepare('UPDATE initdraft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')
            ->execute([$session['id']]);
        continue;
    }

    // Clock
    $clockStart = ($session['daily_clock_start_time'] ?? '19:30:00');
    $clockStartDT = new DateTimeImmutable($today . ' ' . $clockStart, $now->getTimezone());

    if ($now < $clockStartDT) {
        clearDeadlinesForRound($pdo, (int)$session['id'], $dailyRound);
        continue;
    }

    // Depois das 19:30
    $pick = getCurrentOpenPick($pdo, (int)$session['id'], $dailyRound);
    if (!$pick) continue;

    if (empty($pick['deadline_at'])) {
        ensureDeadlineForPick($pdo, $pick, $now, (int)($session['daily_pick_minutes'] ?? 10));
        continue;
    }

    $deadline = new DateTimeImmutable($pick['deadline_at'], $now->getTimezone());
    if ($now <= $deadline) continue;

    $playerId = pickHighestOvrAvailable($pdo, (int)$session['season_id']);
    if (!$playerId) {
        $pdo->prepare('UPDATE initdraft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')
            ->execute([$session['id']]);
        continue;
    }

    performInitDraftPick($pdo, $session, $playerId);
    clearDeadlinesForRound($pdo, (int)$session['id'], $dailyRound);
}

echo "OK " . $now->format('c') . " sessions=" . count($sessions) . "\n";
