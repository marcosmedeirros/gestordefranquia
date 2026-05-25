<?php
require_once __DIR__ . '/backend/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$pdo = db();

$league       = strtoupper(trim($_GET['league'] ?? ''));
$validLeagues = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

// Detectar coluna de OVR
function resolveOvrCol(PDO $pdo): string {
    try { $pdo->query('SELECT ovr_overall FROM players LIMIT 1'); return 'ovr_overall'; }
    catch (Exception $e) { return 'ovr'; }
}
$ovrCol = resolveOvrCol($pdo);

// Filtro de liga
$leagueWhere  = '';
$leagueParams = [];
if ($league && in_array($league, $validLeagues, true)) {
    $leagueWhere  = ' AND COALESCE(t.league, tf.league, tt.league) = ?';
    $leagueParams = [$league];
}

try {
    // Busca trades aceitas que tenham pelo menos 1 jogador OVR >= 80
    $sql = "
        SELECT DISTINCT
            t.id, t.from_team_id, t.to_team_id,
            COALESCE(t.league, tf.league, tt.league) AS league,
            t.notes, t.created_at, t.updated_at,
            tf.city AS from_city, tf.name AS from_name, tf.photo_url AS from_photo,
            tt.city AS to_city,   tt.name AS to_name,   tt.photo_url AS to_photo
        FROM trades t
        JOIN teams tf ON t.from_team_id = tf.id
        JOIN teams tt ON t.to_team_id   = tt.id
        JOIN trade_items ti ON ti.trade_id = t.id
        LEFT JOIN players p ON ti.player_id = p.id
        WHERE t.status = 'accepted'
          {$leagueWhere}
          AND COALESCE(ti.player_ovr, p.{$ovrCol}, 0) >= 80
        ORDER BY COALESCE(t.updated_at, t.created_at) DESC
        LIMIT 500
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($leagueParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// Para cada trade, busca todos os itens
$trades = [];
foreach ($rows as $row) {
    $tid = (int)$row['id'];

    $stmtItems = $pdo->prepare("
        SELECT ti.*,
               COALESCE(ti.player_ovr,      p.{$ovrCol}) AS ovr,
               COALESCE(ti.player_name,     p.name)      AS pname,
               COALESCE(ti.player_position, p.position)  AS ppos,
               COALESCE(ti.player_age,      p.age)       AS page
        FROM trade_items ti
        LEFT JOIN players p ON ti.player_id = p.id
        WHERE ti.trade_id = ?
    ");
    $stmtItems->execute([$tid]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $fromPlayers = $toPlayers = $fromPicks = $toPicks = [];

    foreach ($items as $item) {
        $isFrom = !empty($item['from_team']);

        if (!empty($item['player_id'])) {
            $entry = [
                'id'       => (int)$item['player_id'],
                'name'     => $item['pname'] ?? 'Desconhecido',
                'position' => $item['ppos']  ?? '—',
                'age'      => $item['page']  !== null ? (int)$item['page'] : null,
                'ovr'      => (int)($item['ovr'] ?? 0),
            ];
            $isFrom ? ($fromPlayers[] = $entry) : ($toPlayers[] = $entry);
        } elseif (!empty($item['pick_id'])) {
            $entry = [
                'pick_id'    => (int)$item['pick_id'],
                'protection' => $item['protection'] ?? null,
            ];
            $isFrom ? ($fromPicks[] = $entry) : ($toPicks[] = $entry);
        }
    }

    $trades[] = [
        'trade_id'     => $tid,
        'league'       => $row['league'],
        'date'         => $row['updated_at'] ?? $row['created_at'],
        'from_team'    => [
            'id'    => (int)$row['from_team_id'],
            'name'  => trim($row['from_city'] . ' ' . $row['from_name']),
            'photo' => $row['from_photo'] ?: null,
        ],
        'to_team'      => [
            'id'    => (int)$row['to_team_id'],
            'name'  => trim($row['to_city'] . ' ' . $row['to_name']),
            'photo' => $row['to_photo'] ?: null,
        ],
        'players_from' => $fromPlayers,
        'players_to'   => $toPlayers,
        'picks_from'   => $fromPicks,
        'picks_to'     => $toPicks,
        'notes'        => $row['notes'] ?: null,
    ];
}

echo json_encode([
    'league' => $league ?: 'ALL',
    'total'  => count($trades),
    'trades' => $trades,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
