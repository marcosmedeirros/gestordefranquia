<?php
/**
 * Sincroniza status ativo/inativo e time atual de hoopgrid_players contra a
 * lista oficial da temporada corrente (stats.nba.com) — sem isso, jogador
 * aposentado ou trocado de time fica com dado velho indefinidamente, já que
 * a tabela nunca se atualiza sozinha.
 *
 * Usada por dois lugares: a ação sync_status de games/admin/dadosjogadores.php
 * (botão manual "Sincronizar Status/Time Atual") e games/cron/sync-hoopgrid-status.php
 * (agendado) — as duas chamam esta mesma função, nenhuma duplica a lógica.
 */
function hoopgridTeamMap(): array {
    return [
        'ATL'=>'ATL','BOS'=>'BOS','BKN'=>'BKN','CHA'=>'CHA','CHI'=>'CHI',
        'CLE'=>'CLE','DAL'=>'DAL','DEN'=>'DEN','DET'=>'DET','GSW'=>'GSW',
        'HOU'=>'HOU','IND'=>'IND','LAC'=>'LAC','LAL'=>'LAL','MEM'=>'MEM',
        'MIA'=>'MIA','MIL'=>'MIL','MIN'=>'MIN','NOP'=>'NOP','NYK'=>'NYK',
        'OKC'=>'OKC','ORL'=>'ORL','PHI'=>'PHI','PHX'=>'PHX','POR'=>'POR',
        'SAC'=>'SAC','SAS'=>'SAS','TOR'=>'TOR','UTA'=>'UTA','WAS'=>'WAS',
        'NJN'=>'BKN','SEA'=>'OKC','NOH'=>'NOP','NOK'=>'NOP','VAN'=>'MEM',
        'SDC'=>'LAC','KCK'=>'SAC',
        // Franquias historicas (pre-1980) mapeadas para a franquia atual equivalente
        'MNL'=>'LAL','MNP'=>'LAL','ROC'=>'SAC','CIN'=>'SAC','FTW'=>'DET',
        'TRI'=>'ATL','MLH'=>'ATL','STL'=>'ATL','SYR'=>'PHI','PHW'=>'GSW',
        'SFW'=>'GSW','BAL'=>'WAS','CHZ'=>'WAS','CAP'=>'WAS','BUF'=>'LAC',
        'CHP'=>'ATL','DNN'=>'DEN','INJ'=>'IND','CHS'=>'CHI','WAT'=>'ATL',
        'SHE'=>'ATL','AND'=>'IND','PRO'=>'ATL',
    ];
}

function syncHoopgridPlayerStatus(PDO $pdo): array
{
    $y = (int)date('Y');
    $m = (int)date('n');
    $seasonStartYear = ($m < 8) ? $y - 1 : $y;
    $season = sprintf('%d-%02d', $seasonStartYear, ($seasonStartYear + 1) % 100);

    $url = "https://stats.nba.com/stats/commonallplayers?IsOnlyCurrentSeason=1&LeagueID=00&Season={$season}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_ENCODING       => '',
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json, text/plain, */*',
            'Accept-Encoding: gzip, deflate, br',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Connection: keep-alive',
            'DNT: 1',
            'Host: stats.nba.com',
            'Origin: https://www.nba.com',
            'Pragma: no-cache',
            'Referer: https://www.nba.com/',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-site',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'x-nba-stats-origin: stats',
            'x-nba-stats-token: true',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err || $code !== 200) {
        return ['ok' => false, 'error' => "HTTP {$code}: {$err}"];
    }

    $data = json_decode($raw, true);
    $hdrs = $data['resultSets'][0]['headers'] ?? [];
    $rows = $data['resultSets'][0]['rowSet']   ?? [];
    if (!$rows) {
        return ['ok' => false, 'error' => 'Nenhum dado retornado (temporada ainda sem elenco?)'];
    }

    $ix    = array_flip($hdrs);
    $iPid  = $ix['PERSON_ID']         ?? null;
    $iTeam = $ix['TEAM_ABBREVIATION'] ?? null;
    if ($iPid === null) {
        return ['ok' => false, 'error' => 'Campo PERSON_ID não encontrado'];
    }

    $teamMap = hoopgridTeamMap();
    $activeMap = []; // pid => team
    foreach ($rows as $row) {
        $pid = (int)($row[$iPid] ?? 0);
        if (!$pid) continue;
        $team = $iTeam !== null ? strtoupper(trim($row[$iTeam] ?? '')) : '';
        $team = $teamMap[$team] ?? $team;
        $activeMap[$pid] = $team ?: null;
    }

    // Marca como ativo + time atual quem está na lista da temporada corrente
    $stmtActive = $pdo->prepare('UPDATE hoopgrid_players SET ativo=1, time_atual=? WHERE nba_person_id=?');
    $ativados = 0;
    foreach ($activeMap as $pid => $team) {
        $stmtActive->execute([$team, $pid]);
        $ativados += $stmtActive->rowCount() > 0 ? 1 : 0;
    }

    // Marca como inativo (e limpa time_atual) quem tem nba_person_id mas nao apareceu na lista
    $pids = array_keys($activeMap);
    if ($pids) {
        $ph = implode(',', array_fill(0, count($pids), '?'));
        $stmtInactive = $pdo->prepare("UPDATE hoopgrid_players SET ativo=0, time_atual=NULL WHERE nba_person_id IS NOT NULL AND nba_person_id NOT IN ({$ph})");
        $stmtInactive->execute($pids);
        $inativados = $stmtInactive->rowCount();
    } else {
        $inativados = 0;
    }

    // Rede de seguranca: registros legados sem nba_person_id (ex.: importados via awards-static.json)
    // nunca sao alcancados pela checagem acima e ficam presos no ativo=1 default. Quem nao jogou na
    // decada 20s certamente nao esta ativo hoje.
    $stmtLegacyInactive = $pdo->prepare("UPDATE hoopgrid_players SET ativo=0, time_atual=NULL WHERE ativo=1 AND eras NOT LIKE '%\"20s\"%'");
    $stmtLegacyInactive->execute();
    $inativados += $stmtLegacyInactive->rowCount();

    return [
        'ok' => true,
        'season' => $season,
        'encontrados' => count($activeMap),
        'ativados' => $ativados,
        'inativados' => $inativados,
    ];
}
