<?php
require_once __DIR__ . '/backend/db.php';
$pdo = db();

echo "<h1>Sincronizador de IDs da NBA</h1>";

// 1. Endpoint oficial da NBA que lista todos os jogadores
$nbaApiUrl = 'https://stats.nba.com/stats/commonallplayers?IsOnlyCurrentSeason=0&LeagueID=00&Season=2023-24';

// 2. Configuração avançada do cURL para burlar o Firewall (Akamai) da NBA
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $nbaApiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_ENCODING, "gzip, deflate, br"); // Essencial: a NBA compacta a resposta
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Host: stats.nba.com',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
    'Accept: application/json, text/plain, */*',
    'Accept-Language: pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7',
    'Origin: https://www.nba.com',
    'Referer: https://www.nba.com/',
    'Connection: keep-alive',
    'x-nba-stats-origin: stats',
    'x-nba-stats-token: true',
    'Sec-Fetch-Dest: empty',
    'Sec-Fetch-Mode: cors',
    'Sec-Fetch-Site: same-site'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Validação de segurança do retorno HTTP
if ($httpCode !== 200 || empty($response)) {
    die("Erro HTTP $httpCode - O firewall da NBA ainda está bloqueando a requisição do seu servidor. O log de erro retornou vazio ou acesso negado.");
}

$nbaData = json_decode($response, true);

if (!$nbaData || !isset($nbaData['resultSets'][0]['rowSet'])) {
    die("Erro ao decodificar o JSON da NBA. A estrutura da API pode ter mudado.");
}

// 3. Criar um array de mapeamento rápido [ "nome do jogador" => id_nba ]
$nbaPlayersMap = [];
foreach ($nbaData['resultSets'][0]['rowSet'] as $row) {
    $nbaId = $row[0];
    $nbaName = strtolower(trim($row[2])); 
    $nbaPlayersMap[$nbaName] = $nbaId;
}

// 4. Buscar os jogadores do SEU banco de dados que estão sem foto na coluna correta
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Mudamos de nba_id para nba_player_id
$stmt = $pdo->query('
    SELECT p.id, p.name, t.city, t.name AS team_name
    FROM players p
    LEFT JOIN teams t ON t.id = p.team_id
    WHERE p.nba_player_id IS NULL
');
$meusJogadores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$atualizados = 0;
$naoEncontrados = [];

// 5. Atualização direta na coluna correta
// Mudamos o SET para nba_player_id e, por precaução, já atualizamos a nba_id também para não dar conflito no front-end
$updateStmt = $pdo->prepare('UPDATE players SET nba_player_id = ?, nba_id = ? WHERE id = ?');

foreach ($meusJogadores as $jogador) {
    $meuNome = strtolower(trim($jogador['name']));
    
    if (isset($nbaPlayersMap[$meuNome])) {
        $idCorreto = $nbaPlayersMap[$meuNome];
        
        try {
            // Passamos o $idCorreto duas vezes (uma para nba_player_id e outra para nba_id)
            $updateStmt->execute([$idCorreto, $idCorreto, $jogador['id']]);
            $atualizados++;
        } catch (PDOException $e) {
            die("<br><b style='color:red;'>ERRO FATAL NO SQL ao salvar {$jogador['name']}:</b> " . $e->getMessage());
        }

    } else {
        $teamLabel = trim(($jogador['city'] ?? '') . ' ' . ($jogador['team_name'] ?? ''));
        $teamLabel = $teamLabel !== '' ? $teamLabel : 'Sem time';
        $naoEncontrados[] = htmlspecialchars($jogador['name']) . ' <span style="color:#999;">(' . htmlspecialchars($teamLabel) . ')</span>';
    }
}

echo "<h3>Processo concluído! $atualizados jogadores foram atualizados e SALVOS nas colunas corretas.</h3>";
if (!empty($naoEncontrados)) {
    echo '<h4>Sem id_nba_player:</h4><ul>';
    foreach ($naoEncontrados as $item) {
        echo '<li>' . $item . '</li>';
    }
    echo '</ul>';
}
?>