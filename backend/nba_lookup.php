<?php
require_once __DIR__ . '/helpers.php';

const NBA_PLAYERS_CACHE_TTL = 43200; // 12 horas
const NBA_PLAYERS_INDEX_URL = 'https://cdn.nba.com/static/json/staticData/player/leaguePlayerList.json';
const NBA_STATS_PLAYERINDEX_URL = 'https://stats.nba.com/stats/playerindex';
const NBA_HEADSHOT_FALLBACK = 'https://cdn.nba.com/headshots/nba/latest/1040x760/fallback.png';
const NBA_HEADSHOT_TEMPLATE = 'https://ak-static.cms.nba.com/wp-content/uploads/headshots/nba/latest/260x190/%s.png';
const LOCAL_NBA_PLAYERS_INDEX = __DIR__ . '/data/leaguePlayerList.json';

function fetchNbaPlayerIdByName(string $fullName): ?array
{
    $players = loadNbaPlayersIndex();
    if (empty($players)) {
        // Último fallback: um mapa mínimo embutido para garantir fotos dos nomes mais comuns
        $fallback = embeddedNbaIdMap();
        $key = normalizeName($fullName);
        if (isset($fallback[$key])) {
            return [
                'id' => $fallback[$key]['id'],
                'name' => $fallback[$key]['name'],
            ];
        }
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

/**
 * Mapa mínimo embutido (fallback final) para evitar "0 encontrados" quando a NBA bloqueia as fontes.
 * Você pode expandir esta lista com os nomes que aparecem no seu jogo.
 */
function embeddedNbaIdMap(): array
{
    // Chave: normalizeName("Nome Sobrenome")
    return [
        normalizeName('Stephen Curry') => ['id' => '201939', 'name' => 'Stephen Curry'],
        normalizeName('LeBron James') => ['id' => '2544', 'name' => 'LeBron James'],
        normalizeName('Kevin Durant') => ['id' => '201142', 'name' => 'Kevin Durant'],
        normalizeName('Giannis Antetokounmpo') => ['id' => '203507', 'name' => 'Giannis Antetokounmpo'],
        normalizeName('Nikola Jokic') => ['id' => '203999', 'name' => 'Nikola Jokic'],
        normalizeName('Luka Doncic') => ['id' => '1629029', 'name' => 'Luka Doncic'],
        normalizeName('Jayson Tatum') => ['id' => '1628369', 'name' => 'Jayson Tatum'],
        normalizeName('Joel Embiid') => ['id' => '203954', 'name' => 'Joel Embiid'],
        normalizeName('Anthony Davis') => ['id' => '203076', 'name' => 'Anthony Davis'],
        normalizeName('Damian Lillard') => ['id' => '203081', 'name' => 'Damian Lillard'],
        normalizeName('James Harden') => ['id' => '201935', 'name' => 'James Harden'],
        normalizeName('Chris Paul') => ['id' => '101108', 'name' => 'Chris Paul'],
        normalizeName('Paul George') => ['id' => '202331', 'name' => 'Paul George'],
        normalizeName('Kyrie Irving') => ['id' => '202681', 'name' => 'Kyrie Irving'],
        normalizeName('Kawhi Leonard') => ['id' => '202695', 'name' => 'Kawhi Leonard'],
        normalizeName('Jimmy Butler') => ['id' => '202710', 'name' => 'Jimmy Butler'],
        normalizeName('Devin Booker') => ['id' => '1626164', 'name' => 'Devin Booker'],
        normalizeName('Trae Young') => ['id' => '1629027', 'name' => 'Trae Young'],
        normalizeName('Shai Gilgeous-Alexander') => ['id' => '1628983', 'name' => 'Shai Gilgeous-Alexander'],
    ];
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
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        CURLOPT_ENCODING => '', // permite gzip/deflate
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json, text/plain, */*',
            'Origin: https://www.nba.com',
            'Referer: https://www.nba.com/',
            'Host: cdn.nba.com',
            'Connection: keep-alive',
        ],
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    @curl_close($ch);

    if ($response === false) {
        error_log('NBA players index download failed: ' . $curlError);
        return $cache ?? [];
    }

    $payload = json_decode($response, true);
    if (isset($payload['league']['standard']) && is_array($payload['league']['standard'])) {
        $players = $payload['league']['standard'];
        file_put_contents($cacheFile, json_encode($players));
        return $cache = $players;
    }

    // Fallback 1: carregar de arquivo local se disponível
    if (file_exists(LOCAL_NBA_PLAYERS_INDEX)) {
        $local = json_decode(file_get_contents(LOCAL_NBA_PLAYERS_INDEX), true);
        if (isset($local['league']['standard']) && is_array($local['league']['standard'])) {
            error_log('Usando índice local de jogadores NBA (league.standard)');
            $players = $local['league']['standard'];
        } elseif (is_array($local)) {
            // aceitar formato achatado
            error_log('Usando índice local de jogadores NBA (array achatado)');
            $players = $local;
        }
        if (!empty($players)) {
            file_put_contents($cacheFile, json_encode($players));
            return $cache = $players;
        }
    }

    // Fallback 2: usar stats.nba.com playerindex com headers de navegador e mesclar últimas temporadas
    error_log('NBA CDN player index inválido ou bloqueado, tentando fallback via stats.nba.com');
    $merged = fetchPlayersFromStatsIndex();
    if (!empty($merged)) {
        file_put_contents($cacheFile, json_encode($merged));
        return $cache = $merged;
    }

    error_log('Falha ao carregar índice de jogadores NBA em todas as fontes.');
    return $cache ?? [];
}

function refreshNbaPlayersIndex(): array
{
    $cacheFile = sys_get_temp_dir() . '/nba_players_cache.json';
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }
    return loadNbaPlayersIndex(true);
}

/**
 * Tenta carregar jogadores via stats.nba.com/stats/playerindex para múltiplas temporadas recentes.
 * Retorna um array com chaves compatíveis: personId, firstName, lastName.
 */
function fetchPlayersFromStatsIndex(): array
{
    $seasons = recentSeasons(3); // tenta 3 temporadas
    $playersById = [];
    foreach ($seasons as $season) {
        $url = NBA_STATS_PLAYERINDEX_URL . '?' . http_build_query([
            'LeagueID' => '00',
            'Season' => $season,
            'SeasonType' => 'Regular Season',
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            CURLOPT_ENCODING => '',
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, text/plain, */*',
                'Origin: https://www.nba.com',
                'Referer: https://www.nba.com/',
                'Host: stats.nba.com',
                'Connection: keep-alive',
                'x-nba-stats-origin: stats',
                'x-nba-stats-token: true',
            ],
        ]);
        $response = curl_exec($ch);
        $err = curl_error($ch);
        @curl_close($ch);
        if ($response === false) {
            error_log('Falha playerindex ' . $season . ': ' . $err);
            continue;
        }
        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['resultSets'])) {
            error_log('Resposta inválida playerindex ' . $season);
            continue;
        }
        $rs = null;
        foreach ($data['resultSets'] as $set) {
            if ((isset($set['name']) && $set['name'] === 'PlayerIndex') || (isset($set['rowSet']) && isset($set['headers']))) {
                $rs = $set;
                break;
            }
        }
        if (!$rs || empty($rs['rowSet']) || empty($rs['headers'])) {
            continue;
        }
        $headers = $rs['headers'];
        $map = array_flip($headers);
        foreach ($rs['rowSet'] as $row) {
            $id = $row[$map['PERSON_ID']] ?? null;
            if (!$id) continue;
            $first = $row[$map['FIRST_NAME']] ?? '';
            $last = $row[$map['LAST_NAME']] ?? '';
            $playersById[$id] = [
                'personId' => (string)$id,
                'firstName' => $first,
                'lastName' => $last,
            ];
        }
    }
    return array_values($playersById);
}

/**
 * Gera uma lista de temporadas recentes no formato 'YYYY-YY'.
 */
function recentSeasons(int $count): array
{
    $seasons = [];
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $year = (int)$now->format('Y');
    // Temporada da NBA começa em out/nov e termina no ano seguinte; aproximação simples
    // Se estamos no primeiro semestre, a temporada corrente é (ano-1)-(ano % 100)
    $month = (int)$now->format('n');
    $startYear = $month <= 6 ? $year - 1 : $year; // antes de julho, usa ano anterior
    for ($i = 0; $i < $count; $i++) {
        $y1 = $startYear - $i;
        $y2 = ($y1 + 1) % 100;
        $seasons[] = sprintf('%d-%02d', $y1, $y2);
    }
    return $seasons;
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
