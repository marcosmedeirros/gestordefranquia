<?php
require_once __DIR__ . '/helpers.php';

const NBA_PLAYERS_CACHE_TTL = 43200; // 12 horas
const NBA_PLAYERS_INDEX_URL = 'https://cdn.nba.com/static/json/staticData/player/leaguePlayerList.json';
const NBA_HEADSHOT_FALLBACK = 'https://cdn.nba.com/headshots/nba/latest/1040x760/fallback.png';
const NBA_HEADSHOT_TEMPLATE = 'https://ak-static.cms.nba.com/wp-content/uploads/headshots/nba/latest/260x190/%s.png';

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

function loadNbaPlayersIndex(bool $forceRefresh = false): array
{
    static $cache = null;
    if (!$forceRefresh && is_array($cache)) {
        return $cache;
    }

    $cacheFile = sys_get_temp_dir() . '/nba_players_cache.json';

    if (!$forceRefresh && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < NBA_PLAYERS_CACHE_TTL) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (is_array($data)) {
            return $cache = $data;
        }
        @unlink($cacheFile);
    }

    $ch = curl_init(NBA_PLAYERS_INDEX_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'FBA-Manager/1.0',
        CURLOPT_ENCODING => '', // permite gzip/deflate
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
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
        error_log('NBA players index payload inválido.');
        return $cache ?? [];
    }

    $players = $payload['league']['standard'];
    file_put_contents($cacheFile, json_encode($players));
    return $cache = $players;
}

function refreshNbaPlayersIndex(): array
{
    $cacheFile = sys_get_temp_dir() . '/nba_players_cache.json';
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }
    return loadNbaPlayersIndex(true);
}

function buildHeadshotUrl($nbaPlayerId): string
{
    if (!$nbaPlayerId) {
        return NBA_HEADSHOT_FALLBACK;
    }
    return sprintf(NBA_HEADSHOT_TEMPLATE, $nbaPlayerId);
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
