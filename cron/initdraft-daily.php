<?php
// Cron: roda a cada minuto para aplicar regras do InitDraft (1 round por dia).
// - 00:01 BRT: abre o round do dia automaticamente
// - Antes de 19:30: sem relógio
// - 19:30+: relógio (10 min por pick)
// - Timeout: escolhe maior OVR disponível

require_once __DIR__ . '/../backend/db.php';
// performInitDraftPick vem daqui agora. A cópia que existia neste arquivo era
// mais fraca que a da API: sem travas atômicas, sem gravar a posição do pick
// (que a rookie scale precisa) e sem fixar loyal_override=0.
require_once __DIR__ . '/../backend/initdraft_pick.php';

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

/**
 * Primeiro da fila do mock do time que ainda esteja disponível.
 *
 * O autopick por mock vive em api/initdraft.php (check_autopick), mas quem
 * dispara aquilo é o poll do navegador — só roda com alguém com a página
 * aberta. Quando o prazo estoura sem ninguém olhando, quem escolhe é este
 * cron, e ele ignorava o mock e pegava o melhor OVR. Ou seja: justamente no
 * cenário pra que o mock existe (o GM ausente), o mock não valia.
 *
 * Mesma consulta do check_autopick, pra as duas vias escolherem igual.
 */
function pickFromMockQueue(PDO $pdo, int $sessionId, int $teamId): ?int {
    try {
        $stmt = $pdo->prepare('SELECT is_active FROM initdraft_mock_settings WHERE team_id = ? AND initdraft_session_id = ?');
        $stmt->execute([$teamId, $sessionId]);
        if (empty($stmt->fetchColumn())) return null;

        $stmt = $pdo->prepare("
            SELECT mq.player_id FROM initdraft_mock_queue mq
            JOIN initdraft_pool ip ON ip.id = mq.player_id
            WHERE mq.team_id = ? AND mq.initdraft_session_id = ? AND ip.draft_status = 'available'
            ORDER BY mq.priority ASC LIMIT 1
        ");
        $stmt->execute([$teamId, $sessionId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Throwable $e) {
        // Tabela de mock ausente numa base antiga não pode travar o cron:
        // sem mock, segue pro melhor OVR como antes.
        return null;
    }
}

function pickHighestOvrAvailable(PDO $pdo, int $seasonId): ?int {
    $stmt = $pdo->prepare('SELECT id FROM initdraft_pool WHERE season_id = ? AND draft_status = "available" ORDER BY ovr DESC, id ASC LIMIT 1');
    $stmt->execute([$seasonId]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
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

    // Se round terminou, para até o próximo dia (1 round por dia)
    if (($session['status'] ?? 'setup') === 'in_progress' && isRoundCompleted($pdo, (int)$session['id'], $dailyRound)) {
        clearDeadlinesForRound($pdo, (int)$session['id'], $dailyRound);
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

    // Mock do time da vez primeiro; sem mock (ou com a fila toda já draftada),
    // cai no melhor OVR disponível, como era antes.
    $playerId = pickFromMockQueue($pdo, (int)$session['id'], (int)$pick['team_id'])
        ?: pickHighestOvrAvailable($pdo, (int)$session['season_id']);
    if (!$playerId) {
        $pdo->prepare('UPDATE initdraft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')
            ->execute([$session['id']]);
        continue;
    }

    performInitDraftPick($pdo, $session, $playerId);
    clearDeadlinesForRound($pdo, (int)$session['id'], $dailyRound);
}

echo "OK " . $now->format('c') . " sessions=" . count($sessions) . "\n";
