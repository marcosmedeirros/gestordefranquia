<?php
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/db.php';

$pdo = db();
$league = isset($_GET['league']) ? strtoupper(trim($_GET['league'])) : '';

if (!$league) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Informe a liga.']);
    exit;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function playerOvrColumn(PDO $pdo): string
{
    return columnExists($pdo, 'players', 'ovr') ? 'ovr' : (columnExists($pdo, 'players', 'overall') ? 'overall' : 'ovr');
}

$trades = [];

// Trades simples aceitas
$query = "
    SELECT 
        t.*, 
        from_team.city as from_city,
        from_team.name as from_name,
        to_team.city as to_city,
        to_team.name as to_name
    FROM trades t
    JOIN teams from_team ON t.from_team_id = from_team.id
    JOIN teams to_team ON t.to_team_id = to_team.id
    WHERE (COALESCE(t.league, from_team.league, to_team.league)) = ?
      AND t.status = 'accepted'
    ORDER BY t.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$league]);
$simpleTrades = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($simpleTrades as &$trade) {
    $trade['is_multi'] = false;
    $trade['offer_players'] = [];
    $trade['offer_picks'] = [];
    $trade['request_players'] = [];
    $trade['request_picks'] = [];

    try {
        $ovrCol = playerOvrColumn($pdo);
        $stmtOfferPlayers = $pdo->prepare(
            "SELECT 
                COALESCE(p.id, ti.player_id) AS id,
                COALESCE(p.name, ti.player_name, CONCAT('Jogador #', ti.player_id)) AS name,
                COALESCE(p.position, ti.player_position) AS position,
                COALESCE(p.age, ti.player_age) AS age,
                COALESCE(p.{$ovrCol}, ti.player_ovr) AS ovr
             FROM trade_items ti
             LEFT JOIN players p ON p.id = ti.player_id
             WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.pick_id IS NULL"
        );
        $stmtOfferPlayers->execute([$trade['id']]);
        $trade['offer_players'] = $stmtOfferPlayers->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $trade['offer_players'] = [];
    }

    try {
        $stmtOfferPicks = $pdo->prepare('
            SELECT pk.*, 
                   t.city as original_team_city, t.name as original_team_name,
                   lo.city as last_owner_city, lo.name as last_owner_name,
                   ti.pick_protection
            FROM picks pk
            JOIN trade_items ti ON pk.id = ti.pick_id
            JOIN teams t ON pk.original_team_id = t.id
            LEFT JOIN teams lo ON pk.last_owner_team_id = lo.id
            WHERE ti.trade_id = ? AND ti.from_team = TRUE AND ti.pick_id IS NOT NULL
        ');
        $stmtOfferPicks->execute([$trade['id']]);
        $trade['offer_picks'] = $stmtOfferPicks->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $trade['offer_picks'] = [];
    }

    try {
        $ovrCol = playerOvrColumn($pdo);
        $stmtRequestPlayers = $pdo->prepare(
            "SELECT 
                COALESCE(p.id, ti.player_id) AS id,
                COALESCE(p.name, ti.player_name, CONCAT('Jogador #', ti.player_id)) AS name,
                COALESCE(p.position, ti.player_position) AS position,
                COALESCE(p.age, ti.player_age) AS age,
                COALESCE(p.{$ovrCol}, ti.player_ovr) AS ovr
             FROM trade_items ti
             LEFT JOIN players p ON p.id = ti.player_id
             WHERE ti.trade_id = ? AND ti.from_team = FALSE AND ti.pick_id IS NULL"
        );
        $stmtRequestPlayers->execute([$trade['id']]);
        $trade['request_players'] = $stmtRequestPlayers->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $trade['request_players'] = [];
    }

    try {
        $stmtRequestPicks = $pdo->prepare('
            SELECT pk.*, 
                   t.city as original_team_city, t.name as original_team_name,
                   lo.city as last_owner_city, lo.name as last_owner_name,
                   ti.pick_protection
            FROM picks pk
            JOIN trade_items ti ON pk.id = ti.pick_id
            JOIN teams t ON pk.original_team_id = t.id
            LEFT JOIN teams lo ON pk.last_owner_team_id = lo.id
            WHERE ti.trade_id = ? AND ti.from_team = FALSE AND ti.pick_id IS NOT NULL
        ');
        $stmtRequestPicks->execute([$trade['id']]);
        $trade['request_picks'] = $stmtRequestPicks->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $trade['request_picks'] = [];
    }
}
unset($trade);

$trades = array_merge($trades, $simpleTrades);

// Trades múltiplas aceitas
$stmtMulti = $pdo->prepare('
    SELECT mt.*,
           (SELECT COUNT(*) FROM multi_trade_teams WHERE trade_id = mt.id) AS teams_total,
           (SELECT COUNT(*) FROM multi_trade_teams WHERE trade_id = mt.id AND accepted_at IS NOT NULL) AS teams_accepted
    FROM multi_trades mt
    WHERE mt.league = ? AND mt.status = "accepted"
    ORDER BY mt.created_at DESC
');
$stmtMulti->execute([$league]);
$multiTrades = $stmtMulti->fetchAll(PDO::FETCH_ASSOC);

foreach ($multiTrades as &$trade) {
    $trade['is_multi'] = true;
    $tradeId = (int)$trade['id'];

    $stmtTeams = $pdo->prepare('SELECT t.id, t.city, t.name FROM multi_trade_teams mtt JOIN teams t ON t.id = mtt.team_id WHERE mtt.trade_id = ?');
    $stmtTeams->execute([$tradeId]);
    $trade['teams'] = $stmtTeams->fetchAll(PDO::FETCH_ASSOC);

    $stmtItems = $pdo->prepare('SELECT * FROM multi_trade_items WHERE trade_id = ?');
    $stmtItems->execute([$tradeId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $ovrCol = playerOvrColumn($pdo);
    $stmtPickInfo = $pdo->prepare('SELECT pk.*, t.city as original_team_city, t.name as original_team_name, lo.city as last_owner_city, lo.name as last_owner_name FROM picks pk JOIN teams t ON pk.original_team_id = t.id LEFT JOIN teams lo ON pk.last_owner_team_id = lo.id WHERE pk.id = ?');

    foreach ($items as &$item) {
        if ($item['player_id'] && (!$item['player_name'] || !$item['player_ovr'])) {
            $stmtP = $pdo->prepare("SELECT name, position, age, {$ovrCol} AS ovr FROM players WHERE id = ?");
            $stmtP->execute([(int)$item['player_id']]);
            $p = $stmtP->fetch(PDO::FETCH_ASSOC) ?: [];
            $item['player_name'] = $item['player_name'] ?: ($p['name'] ?? null);
            $item['player_position'] = $item['player_position'] ?: ($p['position'] ?? null);
            $item['player_age'] = $item['player_age'] ?: ($p['age'] ?? null);
            $item['player_ovr'] = $item['player_ovr'] ?: ($p['ovr'] ?? null);
        }
        if ($item['pick_id']) {
            $stmtPickInfo->execute([(int)$item['pick_id']]);
            $pick = $stmtPickInfo->fetch(PDO::FETCH_ASSOC) ?: [];
            $item['season_year'] = $pick['season_year'] ?? null;
            $item['round'] = $pick['round'] ?? null;
            $item['original_team_id'] = $pick['original_team_id'] ?? null;
            $item['original_team_city'] = $pick['original_team_city'] ?? null;
            $item['original_team_name'] = $pick['original_team_name'] ?? null;
            $item['last_owner_team_id'] = $pick['last_owner_team_id'] ?? null;
            $item['last_owner_city'] = $pick['last_owner_city'] ?? null;
            $item['last_owner_name'] = $pick['last_owner_name'] ?? null;
        }
    }
    unset($item);
    $trade['items'] = $items;
}
unset($trade);

$trades = array_merge($trades, $multiTrades);

usort($trades, static function ($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});

echo json_encode([
    'success' => true,
    'league' => $league,
    'trades' => $trades
]);
