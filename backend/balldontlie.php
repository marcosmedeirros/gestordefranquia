<?php

/**
 * Helper para consultar balldontlie e obter player id pelo nome.
 * API: https://api.balldontlie.io/v1/players?search={name}
 */

function balldontlieFetchPlayerIdByName(string $name): ?array
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }

    $url = 'https://api.balldontlie.io/v1/players?search=' . rawurlencode($name);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'FBA-Manager/1.0',
        CURLOPT_ENCODING => '',
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
        // Pode retornar 429 rate limit
        error_log('balldontlie http ' . $httpCode . ' body=' . substr($response, 0, 200));
        return null;
    }

    $json = json_decode($response, true);
    $first = $json['data'][0] ?? null;
    if (!$first || empty($first['id'])) {
        return null;
    }

    // balldontlie retorna first_name/last_name
    $full = trim(($first['first_name'] ?? '') . ' ' . ($first['last_name'] ?? ''));

    return [
        'id' => (string)$first['id'],
        'name' => $full !== '' ? $full : $name,
    ];
}

function balldontlieSleepMs(int $ms): void
{
    if ($ms <= 0) return;
    usleep($ms * 1000);
}
