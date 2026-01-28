<?php

require_once __DIR__ . '/helpers.php';

/**
 * Helper para consultar balldontlie e obter player id pelo nome.
 * API nova: https://api.balldontlie.io/v1/players?search={name}
 * API legacy (sem chave): https://www.balldontlie.io/api/v1/players?search={name}
 */

function balldontlieFetchPlayerIdByName(string $name): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    // Try multiple search strategies
    $searchTerms = [];
    
    // 1. Full name
    $searchTerms[] = $name;
    
    // 2. If it has spaces, try first name only
    if (strpos($name, ' ') !== false) {
        $parts = explode(' ', $name);
        $searchTerms[] = $parts[0]; // First name
        $searchTerms[] = end($parts); // Last name
    }
    
    foreach ($searchTerms as $term) {
        $result = balldontlieRequest('players', ['search' => $term]);
        
        if ($result && !empty($result['data'])) {
            // If we searched by partial name, try to find exact match
            foreach ($result['data'] as $player) {
                $fullName = trim(($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? ''));
                
                // Exact match or if we only have one result
                if (count($result['data']) === 1 || 
                    strcasecmp($fullName, $name) === 0) {
                    
                    return [
                        'id' => (string)$player['id'],
                        'name' => $fullName !== '' ? $fullName : $name,
                    ];
                }
            }
            
            // If no exact match but we have results, return first one
            $first = $result['data'][0];
            $fullName = trim(($first['first_name'] ?? '') . ' ' . ($first['last_name'] ?? ''));
            return [
                'id' => (string)$first['id'],
                'name' => $fullName !== '' ? $fullName : $name,
            ];
        }
    }
    
    // Fallback legacy (sem chave)
    $result = balldontlieLegacyRequest($name);
    if ($result && !empty($result['data'])) {
        $first = $result['data'][0];
        $fullName = trim(($first['first_name'] ?? '') . ' ' . ($first['last_name'] ?? ''));
        return [
            'id' => (string)$first['id'],
            'name' => $fullName !== '' ? $fullName : $name,
        ];
    }

    return null;
}

function balldontlieRequest(string $endpoint, array $params = []): ?array
{
    $config = include __DIR__ . '/config.php';
    
    // Try multiple sources for API key
    $apiKey = getenv('BALLDONTLIE_API_KEY') 
           ?: getenv('FBA_BALLDONTLIE_API_KEY')
           ?: ($config['api']['balldontlie_key'] ?? '');
    
    $url = "https://api.balldontlie.io/v1/" . ltrim($endpoint, '/');
    
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    
    $headers = [
        'Accept: application/json',
        'User-Agent: FBA-Brasil-Bot/1.0'
    ];
    
    // Add Authorization header if API key is available
    if (!empty($apiKey)) {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => 'gzip, deflate',
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    @curl_close($ch);

    if ($response === false) {
        error_log('balldontlie error: ' . $err);
        return null;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('balldontlie http ' . $httpCode . ' url=' . $url . ' body=' . substr($response, 0, 200));
        return null;
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        return null;
    }
    return $json;
}

function balldontlieLegacyRequest(string $name): ?array
{
    $url = 'https://www.balldontlie.io/api/v1/players?search=' . rawurlencode($name);
    $headers = [
        'Accept: application/json',
        'User-Agent: FBA-Brasil-Bot/1.0'
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING => 'gzip, deflate',
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    @curl_close($ch);

    if ($response === false) {
        error_log('balldontlie legacy error: ' . $err);
        return null;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('balldontlie legacy http ' . $httpCode . ' url=' . $url);
        return null;
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        return null;
    }
    return $json;
}

function balldontlieSleepMs(int $ms): void
{
    if ($ms <= 0) return;
    usleep($ms * 1000);
}
