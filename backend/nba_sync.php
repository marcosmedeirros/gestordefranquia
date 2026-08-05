<?php
/**
 * Sincroniza nba_player_id/nba_id dos jogadores que ainda não têm — é esse ID
 * que monta a URL da foto (cdn.nba.com/headshots/.../{nba_player_id}.png, ver
 * players.php). Sem o ID certo, o jogador fica sem foto.
 *
 * Casa por NOME contra o cadastro oficial da NBA (todos os jogadores, atuais
 * e históricos). Time e liga não entram na conta — só o nome, então um nome
 * genérico ou mal digitado pode não bater; esses ficam listados em
 * 'nao_encontrados' pra correção manual.
 *
 * Usada por três portas: sync_fotos.php (visita manual do admin),
 * cron/sync-fotos.php (agendado) e api/admin.php ação sync_fotos (botão em
 * Gestão) — as três chamam esta mesma função, nenhuma duplica a lógica.
 */
function syncNbaPlayerPhotos(PDO $pdo): array
{
    $nbaApiUrl = 'https://stats.nba.com/stats/commonallplayers?IsOnlyCurrentSeason=0&LeagueID=00&Season=2023-24';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $nbaApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate, br'); // a NBA compacta a resposta
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
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
        'Sec-Fetch-Site: same-site',
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || empty($response)) {
        return [
            'ok' => false,
            'erro' => "Erro HTTP {$httpCode} ao falar com a NBA" . ($curlErr ? " ({$curlErr})" : '') . '. O firewall deles pode estar bloqueando o servidor, ou a resposta veio vazia.',
        ];
    }

    $nbaData = json_decode($response, true);
    if (!$nbaData || !isset($nbaData['resultSets'][0]['rowSet'])) {
        return ['ok' => false, 'erro' => 'Não deu pra entender a resposta da NBA — a estrutura da API pode ter mudado.'];
    }

    $nbaPlayersMap = [];
    foreach ($nbaData['resultSets'][0]['rowSet'] as $row) {
        $nbaPlayersMap[strtolower(trim((string)$row[2]))] = $row[0];
    }

    $stmt = $pdo->query("
        SELECT p.id, p.name, t.city, t.name AS team_name
        FROM players p
        LEFT JOIN teams t ON t.id = p.team_id
        WHERE p.nba_player_id IS NULL
    ");
    $meusJogadores = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $atualizados = 0;
    $naoEncontrados = [];
    $updateStmt = $pdo->prepare('UPDATE players SET nba_player_id = ?, nba_id = ? WHERE id = ?');

    foreach ($meusJogadores as $jogador) {
        $meuNome = strtolower(trim($jogador['name']));
        if (!isset($nbaPlayersMap[$meuNome])) {
            $teamLabel = trim(($jogador['city'] ?? '') . ' ' . ($jogador['team_name'] ?? ''));
            $naoEncontrados[] = [
                'id' => (int)$jogador['id'],
                'nome' => $jogador['name'],
                'time' => $teamLabel !== '' ? $teamLabel : 'Sem time',
            ];
            continue;
        }
        $idCorreto = $nbaPlayersMap[$meuNome];
        $updateStmt->execute([$idCorreto, $idCorreto, $jogador['id']]);
        $atualizados++;
    }

    return [
        'ok' => true,
        'atualizados' => $atualizados,
        'sem_correspondencia' => $naoEncontrados,
        'total_verificados' => count($meusJogadores),
    ];
}
