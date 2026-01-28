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

    $result = balldontlieRequest('https://api.balldontlie.io/v1/players?search=' . rawurlencode($name));
    if (!$result || empty($result['data'])) {
        // Fallback legacy (sem chave)
        $result = balldontlieRequest('https://www.balldontlie.io/api/v1/players?search=' . rawurlencode($name));
    }

    $first = $result['data'][0] ?? null;
    if (!$first || empty($first['id'])) {
        return null;
    }

    $full = trim(($first['first_name'] ?? '') . ' ' . ($first['last_name'] ?? ''));
    return [
        'id' => (string)$first['id'],
        'name' => $full !== '' ? $full : $name,
    ];
}

function balldontlieRequest(string $url): ?array
{
    $apiKey = getenv('BALLDONTLIE_API_KEY') ?: getenv('FBA_BALLDONTLIE_API_KEY');
    if (empty($apiKey)) {
        $configFile = __DIR__ . '/config.php';
        if (file_exists($configFile)) {
            $config = loadConfig();
            $apiKey = $config['api']['balldontlie_key'] ?? '';
        }
    }
    $headers = [
        'Accept: application/json',
    ];
    if (!empty($apiKey)) {
        $headers[] = 'Authorization: ' . $apiKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'FBA-Manager/1.0',
        CURLOPT_ENCODING => '',
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

function balldontlieSleepMs(int $ms): void
{
    if ($ms <= 0) return;
    usleep($ms * 1000);
}
