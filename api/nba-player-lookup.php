<?php
session_start();
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$playerId = (int)($body['player_id'] ?? 0);
$playerName = trim($body['player_name'] ?? '');

if (!$playerId && $playerName === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'player_id ou player_name obrigatório']);
    exit;
}

$pdo = db();

try {
    if ($playerId) {
        $stmt = $pdo->prepare('SELECT id, name, nba_player_id FROM players WHERE id = ? LIMIT 1');
        $stmt->execute([$playerId]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$player) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Jogador não encontrado']);
            exit;
        }
        if (!empty($player['nba_player_id'])) {
            echo json_encode([
                'success' => true,
                'nba_player_id' => $player['nba_player_id'],
                'source' => 'cache',
                'matched_name' => $player['name']
            ]);
            exit;
        }
        $playerName = $player['name'];
    }

    if ($playerName === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Nome do jogador obrigatório']);
        exit;
    }

    $lookupResult = fetchNbaPlayerIdByName($playerName);
    if (!$lookupResult) {
        echo json_encode(['success' => true, 'nba_player_id' => null, 'matched_name' => null]);
        exit;
    }

    if ($playerId) {
        $stmtUpdate = $pdo->prepare('UPDATE players SET nba_player_id = ? WHERE id = ?');
        $stmtUpdate->execute([$lookupResult['id'], $playerId]);
    }

    echo json_encode([
        'success' => true,
        'nba_player_id' => $lookupResult['id'],
        'matched_name' => $lookupResult['name'],
        'headshot_url' => buildHeadshotUrl($lookupResult['id'])
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Falha ao buscar jogador', 'details' => $e->getMessage()]);
}

function fetchNbaPlayerIdByName(string $fullName): ?array
{
    $players = loadNbaPlayersIndex();
    if (empty($players)) {
        return null;
    }

    $normalizedSearch = normalizeName($fullName);
    $best = null;
    $bestScore = PHP_INT_MAX;

    foreach ($players as $player) {
        $candidateName = trim(($player['firstName'] ?? '') . ' ' . ($player['lastName'] ?? ''));
        if ($candidateName === '') {
            continue;
        }
        $score = levenshteinScore($normalizedSearch, normalizeName($candidateName));
        if ($score < $bestScore) {
            $bestScore = $score;
            $best = [
                'id' => $player['personId'] ?? null,
                'name' => $candidateName
            ];
        }
        if ($score === 0) {
            break;
        }
    }

    if (!$best || !$best['id']) {
        return null;
    }

    if ($bestScore > 8 && stripos($best['name'], $fullName) === false) {
        return null;
    }

    return $best;
}

function loadNbaPlayersIndex(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cacheFile = sys_get_temp_dir() . '/nba_players_cache.json';
    $cacheTtl = 60 * 60 * 12; // 12 horas

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (is_array($data)) {
            return $cache = $data;
        }
    }

    $remoteUrl = 'https://cdn.nba.com/static/json/staticData/player/leaguePlayerList.json';
    $ch = curl_init($remoteUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'FBA-Manager/1.0'
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('NBA players index download failed: ' . $curlError);
        return $cache ?? [];
    }

    $payload = json_decode($response, true);
    if (!isset($payload['league']['standard']) || !is_array($payload['league']['standard'])) {
        return $cache ?? [];
    }

    $players = $payload['league']['standard'];
    file_put_contents($cacheFile, json_encode($players));
    return $cache = $players;
}

function buildHeadshotUrl($nbaPlayerId): string
{
    if (!$nbaPlayerId) {
        return 'https://cdn.nba.com/headshots/nba/latest/1040x760/fallback.png';
    }
    return sprintf('https://ak-static.cms.nba.com/wp-content/uploads/headshots/nba/latest/260x190/%s.png', $nbaPlayerId);
}

function normalizeName(string $name): string
{
    $clean = iconv('UTF-8', 'ASCII//TRANSLIT', mb_strtolower($name));
    return preg_replace('/[^a-z\s]/', '', $clean);
}

function levenshteinScore(string $a, string $b): int
{
    return levenshtein(trim($a), trim($b));
}
