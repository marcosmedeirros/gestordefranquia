<?php
$url = 'https://cdn.nba.com/static/json/staticData/player/leaguePlayerList.json';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT => 'FBA-Manager/1.0',
    CURLOPT_ENCODING => '',
    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
]);
$response = curl_exec($ch);
$err = curl_error($ch);
@curl_close($ch);
if ($response === false) {
    echo "curl error: $err\n";
    exit(1);
}
$len = strlen($response);
$head = substr($response, 0, 500);
$payload = json_decode($response, true);
$keys = is_array($payload) ? implode(',', array_keys($payload)) : 'not_json';
echo "response_len=$len\n";
echo "top_keys=$keys\n";
echo "peek=$head\n";
if (isset($payload['league'])) {
    $leagueKeys = implode(',', array_keys($payload['league']));
    echo "league_keys=$leagueKeys\n";
    if (isset($payload['league']['standard'])) {
        echo "standard_count=" . count($payload['league']['standard']) . "\n";
        $sample = $payload['league']['standard'][0] ?? [];
        echo 'sample=' . json_encode($sample) . "\n";
    }
}
