<?php

/**
 * Helper para consultar a API oficial da NBA (stats.nba.com)
 * Endpoint: https://stats.nba.com/stats/commonallplayers
 */

/**
 * Busca todos os jogadores da temporada atual da NBA
 * @param bool $onlyCurrentSeason Se true, retorna apenas jogadores ativos
 * @param string $season Temporada no formato YYYY-YY (ex: 2024-25)
 * @return array|null Array com todos os jogadores ou null em caso de erro
 */
function nbaOfficialFetchAllPlayers(bool $onlyCurrentSeason = true, string $season = '2024-25'): ?array
{
    $url = 'https://stats.nba.com/stats/commonallplayers?' . http_build_query([
        'IsOnlyCurrentSeason' => $onlyCurrentSeason ? 1 : 0,
        'LeagueID' => '00',
        'Season' => $season
    ]);
    
    $headers = [
        'Host: stats.nba.com',
        'Connection: keep-alive',
        'Accept: application/json, text/plain, */*',
        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Origin: https://www.nba.com',
        'Referer: https://www.nba.com/',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: gzip, deflate, br',
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING => '',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    unset($ch);
    
    if ($curlError) {
        error_log("NBA Official API curl error: $curlError");
        return null;
    }
    
    if ($httpCode !== 200) {
        error_log("NBA Official API http $httpCode url=$url");
        return null;
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['resultSets'][0]['rowSet'])) {
        error_log("NBA Official API invalid response format");
        return null;
    }
    
    return $data;
}

/**
 * Busca um jogador específico pelo nome
 * @param string $name Nome do jogador
 * @param bool $onlyCurrentSeason Se true, busca apenas entre jogadores ativos
 * @return array|null Array com ['id' => string, 'name' => string] ou null
 */
function nbaOfficialFetchPlayerIdByName(string $name, bool $onlyCurrentSeason = true): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    
    // Busca todos os jogadores
    $data = nbaOfficialFetchAllPlayers($onlyCurrentSeason);
    if (!$data) {
        return null;
    }
    
    // Estrutura: headers em resultSets[0].headers e dados em resultSets[0].rowSet
    $headers = $data['resultSets'][0]['headers'] ?? [];
    $rows = $data['resultSets'][0]['rowSet'] ?? [];
    
    // Encontrar índices das colunas
    $personIdIdx = array_search('PERSON_ID', $headers);
    $displayNameIdx = array_search('DISPLAY_FIRST_LAST', $headers);
    
    if ($personIdIdx === false || $displayNameIdx === false) {
        error_log("NBA Official API: Could not find required columns");
        return null;
    }
    
    // Normalizar nome para busca
    $normalizedSearch = normalizePlayerName($name);
    
    // Buscar jogador
    foreach ($rows as $row) {
        $playerName = $row[$displayNameIdx] ?? '';
        $normalizedPlayer = normalizePlayerName($playerName);
        
        if ($normalizedPlayer === $normalizedSearch) {
            return [
                'id' => (string)$row[$personIdIdx],
                'name' => $playerName,
            ];
        }
    }
    
    // Busca parcial (caso não encontre exato)
    foreach ($rows as $row) {
        $playerName = $row[$displayNameIdx] ?? '';
        $normalizedPlayer = normalizePlayerName($playerName);
        
        if (stripos($normalizedPlayer, $normalizedSearch) !== false || 
            stripos($normalizedSearch, $normalizedPlayer) !== false) {
            return [
                'id' => (string)$row[$personIdIdx],
                'name' => $playerName,
            ];
        }
    }
    
    return null;
}

/**
 * Normaliza nome de jogador para comparação
 */
function normalizePlayerName(string $name): string
{
    $name = trim($name);
    $name = mb_strtolower($name);
    // Remove acentos e caracteres especiais
    $name = preg_replace('/[^a-z0-9\s]/i', '', $name);
    // Remove espaços extras
    $name = preg_replace('/\s+/', ' ', $name);
    return $name;
}

/**
 * Cache em memória dos jogadores para evitar múltiplas requisições
 */
$_nbaPlayersCache = null;

function nbaOfficialGetCachedPlayers(bool $forceRefresh = false): ?array
{
    global $_nbaPlayersCache;
    
    if ($forceRefresh || $_nbaPlayersCache === null) {
        $_nbaPlayersCache = nbaOfficialFetchAllPlayers();
    }
    
    return $_nbaPlayersCache;
}
