<?php
require_once __DIR__ . '/../backend/db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

function ensureTradeWebhookTable(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS trade_webhooks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            trade_id INT NOT NULL,
            league ENUM('ELITE','NEXT','RISE','ROOKIE') NULL,
            from_team VARCHAR(150) NULL,
            to_team VARCHAR(150) NULL,
            receiving_phone VARCHAR(30) NULL,
            payload_json LONGTEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_trade_webhook_trade (trade_id),
            INDEX idx_trade_webhook_league (league, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $e->getMessage()]);
        exit;
    }
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

ensureTradeWebhookTable($pdo);

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
    $payload = readJsonBody();
    if (!$payload) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    $tradeId = (int)($payload['trade']['id'] ?? $payload['trade_id'] ?? 0);
    $league = $payload['trade']['league'] ?? ($payload['league'] ?? null);
    $fromTeam = $payload['from_team']['name'] ?? null;
    $toTeam = $payload['to_team']['name'] ?? null;
    $phone = $payload['receiving_user_phone'] ?? ($payload['to_team']['owner_phone'] ?? null);

    if ($tradeId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'trade_id is required']);
        exit;
    }

    $stmt = $pdo->prepare('INSERT IGNORE INTO trade_webhooks (trade_id, league, from_team, to_team, receiving_phone, payload_json) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $tradeId,
        $league,
        $fromTeam,
        $toTeam,
        $phone,
        json_encode($payload, JSON_UNESCAPED_UNICODE)
    ]);

    echo json_encode(['success' => true]);
    exit;
}

$league = strtoupper((string)($_GET['league'] ?? ''));
$limit = (int)($_GET['limit'] ?? 10);
if ($limit <= 0) {
    $limit = 10;
}
if ($limit > 50) {
    $limit = 50;
}

$validLeagues = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
if ($league === '' || !in_array($league, $validLeagues, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'league is required (ELITE, NEXT, RISE, ROOKIE)'
    ]);
    exit;
}

$stmt = $pdo->prepare('SELECT trade_id, league, from_team, to_team, receiving_phone, payload_json, created_at FROM trade_webhooks WHERE league = ? ORDER BY created_at DESC LIMIT ?');
$stmt->bindValue(1, $league, PDO::PARAM_STR);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$out = [];
foreach ($rows as $row) {
    $payload = null;
    if (!empty($row['payload_json'])) {
        $decoded = json_decode($row['payload_json'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    $out[] = [
        'trade_id' => (int)$row['trade_id'],
        'league' => $row['league'],
        'from_team' => $row['from_team'],
        'to_team' => $row['to_team'],
        'receiving_phone' => $row['receiving_phone'],
        'created_at' => $row['created_at'],
        'payload' => $payload
    ];
}

echo json_encode([
    'success' => true,
    'count' => count($out),
    'items' => $out
]);
