<?php
require_once __DIR__ . '/../backend/db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

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

$stmt = $pdo->prepare("
    SELECT
        t.id AS trade_id,
        t.league,
        t.created_at,
        t.status,
        from_team.city AS from_city,
        from_team.name AS from_name,
        to_team.city AS to_city,
        to_team.name AS to_name,
        from_user.phone AS from_user_phone
    FROM trades t
    JOIN teams from_team ON t.from_team_id = from_team.id
    JOIN teams to_team ON t.to_team_id = to_team.id
    JOIN users from_user ON from_team.user_id = from_user.id
    WHERE t.league = ?
    AND t.status = 'pending'
      AND t.created_at >= (NOW() - INTERVAL 1 HOUR)
    ORDER BY t.created_at DESC
    LIMIT ?
");
$stmt->bindValue(1, $league, PDO::PARAM_STR);
$stmt->bindValue(2, $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$tradeIds = array_values(array_filter(array_map(static fn($r) => (int)$r['trade_id'], $rows)));

$ovrColumn = 'ovr';
try {
    $col = $pdo->query("SHOW COLUMNS FROM players LIKE 'ovr'")->fetch();
    if (!$col) {
        $colOverall = $pdo->query("SHOW COLUMNS FROM players LIKE 'overall'")->fetch();
        if ($colOverall) {
            $ovrColumn = 'overall';
        }
    }
} catch (Exception $e) {
}

$itemsByTrade = [];
if ($tradeIds) {
    $placeholders = implode(',', array_fill(0, count($tradeIds), '?'));
    $sqlItems = "
        SELECT
            ti.trade_id,
            ti.from_team,
            ti.player_id,
            ti.pick_id,
            ti.pick_protection,
            COALESCE(ti.player_name, p.name) AS player_name,
            COALESCE(ti.player_position, p.position) AS player_position,
            COALESCE(ti.player_age, p.age) AS player_age,
            COALESCE(ti.player_ovr, p.{$ovrColumn}) AS player_ovr,
            pk.season_year,
            pk.round,
            ot.city AS orig_city,
            ot.name AS orig_name
        FROM trade_items ti
        LEFT JOIN players p ON p.id = ti.player_id
        LEFT JOIN picks pk ON pk.id = ti.pick_id
        LEFT JOIN teams ot ON ot.id = pk.original_team_id
        WHERE ti.trade_id IN ($placeholders)
    ";

    $stmtItems = $pdo->prepare($sqlItems);
    $stmtItems->execute($tradeIds);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $tradeId = (int)$item['trade_id'];
        if (!isset($itemsByTrade[$tradeId])) {
            $itemsByTrade[$tradeId] = [
                'sent' => ['players' => [], 'picks' => []],
                'requested' => ['players' => [], 'picks' => []]
            ];
        }

        $bucket = !empty($item['from_team']) ? 'sent' : 'requested';

        if (!empty($item['player_id'])) {
            $itemsByTrade[$tradeId][$bucket]['players'][] = [
                'id' => (int)$item['player_id'],
                'name' => $item['player_name'],
                'position' => $item['player_position'],
                'ovr' => $item['player_ovr'] !== null ? (int)$item['player_ovr'] : null,
                'age' => $item['player_age'] !== null ? (int)$item['player_age'] : null
            ];
        }

        if (!empty($item['pick_id'])) {
            $itemsByTrade[$tradeId][$bucket]['picks'][] = [
                'id' => (int)$item['pick_id'],
                'season_year' => $item['season_year'] !== null ? (int)$item['season_year'] : null,
                'round' => $item['round'] !== null ? (int)$item['round'] : null,
                'original_team' => trim(($item['orig_city'] ?? '') . ' ' . ($item['orig_name'] ?? '')),
                'protection' => $item['pick_protection'] ?? null
            ];
        }
    }
}

$out = [];
foreach ($rows as $row) {
    $tradeId = (int)$row['trade_id'];
    $out[] = [
        'trade_id' => $tradeId,
        'league' => $row['league'],
        'status' => $row['status'],
        'from_team' => trim($row['from_city'] . ' ' . $row['from_name']),
        'to_team' => trim($row['to_city'] . ' ' . $row['to_name']),
        'from_user_phone' => $row['from_user_phone'],
        'created_at' => $row['created_at'],
        'items' => $itemsByTrade[$tradeId] ?? ['sent' => ['players' => [], 'picks' => []], 'requested' => ['players' => [], 'picks' => []]]
    ];
}

echo json_encode([
    'success' => true,
    'count' => count($out),
    'items' => $out
]);
