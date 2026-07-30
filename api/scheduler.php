<?php
/**
 * API admin do agendador de fases. GET lista eventos + status das trades (e já
 * resolve os vencidos de forma preguiçosa, sem depender de cron externo — mesmo
 * padrão do leilão/waiver); POST cria/cancela eventos e roda os vencidos na hora.
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/scheduler.php';
header('Content-Type: application/json');

requireAuth();
$user = getUserSession();
$pdo  = db();
if (!hasAdminAccess($pdo, (int)$user['id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Apenas administradores']);
    exit;
}
ensureScheduledEventsTable($pdo);
runDueScheduledEvents($pdo); // rede de segurança: resolve o que já venceu, sem precisar de cron

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $league = strtoupper((string)($_GET['league'] ?? 'ELITE'));
    $st = $pdo->prepare("SELECT id, league, type, run_at, status, result, ran_at,
                                TIMESTAMPDIFF(SECOND, NOW(), run_at) AS seconds_left
                         FROM scheduled_events WHERE league = ?
                         ORDER BY (status='pending') DESC, run_at DESC LIMIT 60");
    $st->execute([$league]);
    $events = $st->fetchAll(PDO::FETCH_ASSOC);

    $te = $pdo->prepare("SELECT COALESCE(trades_enabled,1) FROM league_settings WHERE league = ?");
    $te->execute([$league]);
    $tradesEnabled = $te->fetchColumn();

    echo json_encode([
        'success' => true, 'league' => $league,
        'trades_enabled' => $tradesEnabled === false ? 1 : (int)$tradesEnabled,
        'events' => $events,
    ]);
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? 'create';

    if ($action === 'create') {
        $league = strtoupper((string)($data['league'] ?? ''));
        $type   = (string)($data['type'] ?? '');
        $runAt  = trim((string)($data['run_at'] ?? ''));
        if (!in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) { echo json_encode(['success' => false, 'error' => 'Liga inválida']); exit; }
        if (!in_array($type, SCHED_TYPES, true)) { echo json_encode(['success' => false, 'error' => 'Tipo de evento indisponível']); exit; }
        $ts = strtotime(str_replace('T', ' ', $runAt));
        if (!$ts) { echo json_encode(['success' => false, 'error' => 'Data/hora inválida']); exit; }
        $pdo->prepare("INSERT INTO scheduled_events (league, type, run_at, created_by) VALUES (?, ?, ?, ?)")
            ->execute([$league, $type, date('Y-m-d H:i:s', $ts), (int)$user['id']]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($action === 'cancel') {
        $id = (int)($data['id'] ?? 0);
        $pdo->prepare("UPDATE scheduled_events SET status='canceled' WHERE id=? AND status='pending'")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'run_due') {
        echo json_encode(['success' => true] + runDueScheduledEvents($pdo));
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Ação inválida']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não suportado']);
