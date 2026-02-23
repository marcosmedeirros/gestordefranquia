<?php
require_once __DIR__ . '/backend/db.php';
$pdo = db();

echo "<h1>Sincronizador de IDs da NBA</h1>";

// 1. Endpoint oficial da NBA que lista todos os jogadores
$nbaApiUrl = 'https://stats.nba.com/stats/commonallplayers?IsOnlyCurrentSeason=0&LeagueID=00&Season=2023-24';

// A NBA bloqueia requisições sem User-Agent, então vamos simular um navegador usando cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $nbaApiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Referer: https://stats.nba.com/',
    'Accept: application/json'
]);

$response = curl_exec($ch);
curl_close($ch);

$nbaData = json_decode($response, true);

if (!$nbaData || !isset($nbaData['resultSets'][0]['rowSet'])) {
    die("Erro ao conectar com a API da NBA.");
}

// 2. Criar um array de mapeamento rápido [ "nome do jogador" => id_nba ]
$nbaPlayersMap = [];
foreach ($nbaData['resultSets'][0]['rowSet'] as $row) {
    $nbaId = $row[0];
    $nbaName = strtolower(trim($row[2])); // Deixa tudo minúsculo para facilitar a busca
    $nbaPlayersMap[$nbaName] = $nbaId;
}

// 3. Buscar os jogadores do SEU banco de dados que estão sem foto (nba_id NULL)
$stmt = $pdo->query('SELECT id, name FROM players WHERE nba_id IS NULL');
$meusJogadores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$atualizados = 0;

// 4. Fazer o match e atualizar o banco
$updateStmt = $pdo->prepare('UPDATE players SET nba_id = ? WHERE id = ?');

foreach ($meusJogadores as $jogador) {
    $meuNome = strtolower(trim($jogador['name']));
    
    // Se o nome do seu banco bater exatamente com o nome da NBA
    if (isset($nbaPlayersMap[$meuNome])) {
        $idCorreto = $nbaPlayersMap[$meuNome];
        
        $updateStmt->execute([$idCorreto, $jogador['id']]);
        $atualizados++;
        
        echo "✅ Foto encontrada para: {$jogador['name']} (ID: $idCorreto)<br>";
    } else {
        echo "❌ Nome não encontrado na API: {$jogador['name']} <br>";
    }
}

echo "<h3>Processo concluído! $atualizados jogadores foram atualizados.</h3>";
?>