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
        'matched_name' => $lookupResult['name']
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Falha ao buscar jogador', 'details' => $e->getMessage()]);
}

function fetchNbaPlayerIdByName(string $fullName): ?array
{
    $apiUrl = 'https://www.balldontlie.io/api/v1/players?search=' . rawurlencode($fullName);

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'FBA-Manager/1.0'
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('NBA lookup failed: ' . $curlError);
        return null;
    }

    $payload = json_decode($response, true);
    if (!isset($payload['data']) || !is_array($payload['data'])) {
        return null;
    }

    $candidates = $payload['data'];
    if (empty($candidates)) {
        return null;
    }

    $normalizedSearch = normalizeName($fullName);

    usort($candidates, function ($a, $b) use ($normalizedSearch) {
        return levenshteinScore($normalizedSearch, normalizeName($a['first_name'] . ' ' . $a['last_name'])) <=>
               levenshteinScore($normalizedSearch, normalizeName($b['first_name'] . ' ' . $b['last_name']));
    });

    $best = $candidates[0];
    $bestName = trim(($best['first_name'] ?? '') . ' ' . ($best['last_name'] ?? ''));
    $bestScore = levenshteinScore($normalizedSearch, normalizeName($bestName));

    if ($bestScore > 5 && stripos($bestName, $fullName) === false) {
        return null;
    }

    return [
        'id' => $best['id'] ?? null,
        'name' => $bestName
    ];
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
