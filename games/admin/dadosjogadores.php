<?php
/**
 * dadosjogadores.php
 * Gerenciador do banco de jogadores NBA (usado em múltiplos jogos)
 */
session_start();
require '../core/conexao.php';
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

$stmt = $pdo->prepare("SELECT is_admin, nome, pontos, fba_points, COALESCE(numero_tapas,0) as numero_tapas FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u) { header("Location: ../auth/login.php"); exit; }
$isAdmin = !empty($u['is_admin']);

// Garante que a tabela existe
$pdo->exec("CREATE TABLE IF NOT EXISTS hoopgrid_players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    times TEXT NOT NULL DEFAULT '[]',
    pais CHAR(5) NOT NULL DEFAULT 'USA',
    premios TEXT NOT NULL DEFAULT '[]',
    eras TEXT NOT NULL DEFAULT '[]',
    nba_person_id INT NULL,
    ativo TINYINT(1) DEFAULT 1,
    UNIQUE KEY uk_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Mapeamento abreviaturas NBA ──────────────────────────────────────────────
$TEAM_MAP = [
    'ATL'=>'ATL','BOS'=>'BOS','BKN'=>'BKN','CHA'=>'CHA','CHI'=>'CHI',
    'CLE'=>'CLE','DAL'=>'DAL','DEN'=>'DEN','DET'=>'DET','GSW'=>'GSW',
    'HOU'=>'HOU','IND'=>'IND','LAC'=>'LAC','LAL'=>'LAL','MEM'=>'MEM',
    'MIA'=>'MIA','MIL'=>'MIL','MIN'=>'MIN','NOP'=>'NOP','NYK'=>'NYK',
    'OKC'=>'OKC','ORL'=>'ORL','PHI'=>'PHI','PHX'=>'PHX','POR'=>'POR',
    'SAC'=>'SAC','SAS'=>'SAS','TOR'=>'TOR','UTA'=>'UTA','WAS'=>'WAS',
    'NJN'=>'BKN','SEA'=>'OKC','NOH'=>'NOP','NOK'=>'NOP','VAN'=>'MEM',
    'SDC'=>'LAC','KCK'=>'SAC',
];

function calcEras(int $from, int $to): array {
    $eras = [];
    $decades = ['80s'=>[1980,1989],'90s'=>[1990,1999],'00s'=>[2000,2009],'10s'=>[2010,2019],'20s'=>[2020,2030]];
    foreach ($decades as $key => [$s, $e]) {
        if ($from <= $e && $to >= $s) $eras[] = $key;
    }
    return $eras;
}

$action = $_GET['action'] ?? 'status';

// ── IMPORT DA API ────────────────────────────────────────────────────────────
if ($action === 'import') {
    header('Content-Type: application/json');
    set_time_limit(300);
    $season = '2024-25';
    $url = "https://stats.nba.com/stats/commonallplayers?IsOnlyCurrentSeason=0&LeagueID=00&Season={$season}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_ENCODING       => '',   // aceita gzip/deflate/br
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
    if ($err || $code !== 200) { echo json_encode(['ok'=>false,'error'=>"HTTP {$code}: {$err}"]); exit; }
    $data    = json_decode($raw, true);
    $headers = $data['resultSets'][0]['headers'] ?? [];
    $rows    = $data['resultSets'][0]['rowSet']   ?? [];
    if (!$rows) { echo json_encode(['ok'=>false,'error'=>'Nenhum dado retornado']); exit; }
    $idx   = array_flip($headers);
    $iNome = $idx['DISPLAY_FIRST_LAST'] ?? null;
    $iFrom = $idx['FROM_YEAR']          ?? null;
    $iTo   = $idx['TO_YEAR']            ?? null;
    $iTeam = $idx['TEAM_ABBREVIATION']  ?? null;
    $iPid  = $idx['PERSON_ID']          ?? null;
    if ($iNome === null || $iFrom === null) { echo json_encode(['ok'=>false,'error'=>'Campos não encontrados']); exit; }
    $stmtI = $pdo->prepare("INSERT INTO hoopgrid_players (nome,times,pais,premios,eras,nba_person_id)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            eras=IF(eras='[]' OR eras IS NULL,VALUES(eras),eras),
            nba_person_id=COALESCE(nba_person_id,VALUES(nba_person_id))");
    $inserted = 0; $skipped = 0;
    foreach ($rows as $row) {
        $nome = trim($row[$iNome] ?? '');
        if (!$nome) { $skipped++; continue; }
        $from = (int)($row[$iFrom] ?? 0);
        $to   = (int)($row[$iTo]   ?? date('Y'));
        if ($from < 1979) { $skipped++; continue; }
        $eras = calcEras($from, $to);
        if (empty($eras)) { $skipped++; continue; }
        $teamRaw = strtoupper(trim($row[$iTeam] ?? ''));
        $team    = $TEAM_MAP[$teamRaw] ?? $teamRaw;
        $times   = $team ? [$team] : [];
        $pid     = $iPid !== null ? (int)$row[$iPid] : null;
        try { $stmtI->execute([$nome, json_encode($times), 'USA', '[]', json_encode($eras), $pid]); $inserted++; }
        catch (PDOException $e) { $skipped++; }
    }
    echo json_encode(['ok'=>true,'inserted'=>$inserted,'skipped'=>$skipped,'total'=>count($rows)]);
    exit;
}

// ── LISTAR JOGADORES (JSON) ──────────────────────────────────────────────────
if ($action === 'list') {
    header('Content-Type: application/json');
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = 50;
    $off    = ($page - 1) * $limit;
    $q      = trim($_GET['q'] ?? '');
    $fAtivo = $_GET['ativo'] ?? '';
    $fPais  = trim($_GET['pais'] ?? '');
    $fPremio= trim($_GET['premio'] ?? '');
    $fEra   = trim($_GET['era'] ?? '');
    $fTime  = trim($_GET['time'] ?? '');

    $where = ['1=1'];
    $params = [];
    if ($q !== '')      { $where[] = 'nome LIKE ?';       $params[] = "%{$q}%"; }
    if ($fAtivo !== '') { $where[] = 'ativo = ?';         $params[] = (int)$fAtivo; }
    if ($fPais !== '')  { $where[] = 'pais = ?';          $params[] = strtoupper($fPais); }
    if ($fPremio !== '') { $where[] = 'premios LIKE ?';   $params[] = '%"' . $fPremio . '"%'; }
    if ($fEra !== '')   { $where[] = 'eras LIKE ?';       $params[] = '%"' . $fEra . '"%'; }
    if ($fTime !== '')  { $where[] = 'times LIKE ?';      $params[] = '%"' . $fTime . '"%'; }

    $whereStr = implode(' AND ', $where);

    // Conta total
    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM hoopgrid_players WHERE $whereStr");
    $stmtC->execute($params);
    $total = (int)$stmtC->fetchColumn();

    // Busca página
    $stmtL = $pdo->prepare("SELECT id,nome,times,pais,premios,eras,nba_person_id,ativo FROM hoopgrid_players WHERE $whereStr ORDER BY nome ASC LIMIT $limit OFFSET $off");
    $stmtL->execute($params);
    $players = $stmtL->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok'=>true,'players'=>$players,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/$limit))]);
    exit;
}

// ── SALVAR JOGADOR (POST JSON) ───────────────────────────────────────────────
if ($action === 'save_player' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    $id     = isset($body['id']) ? (int)$body['id'] : 0;
    $nome   = trim($body['nome'] ?? '');
    $times  = json_encode(array_values(array_filter(array_map('trim', (array)($body['times'] ?? [])))));
    $pais   = strtoupper(trim($body['pais'] ?? 'USA'));
    $premios= json_encode(array_values(array_filter(array_map('trim', (array)($body['premios'] ?? [])))));
    $eras   = json_encode(array_values(array_filter(array_map('trim', (array)($body['eras'] ?? [])))));
    $pid    = !empty($body['nba_person_id']) ? (int)$body['nba_person_id'] : null;
    $ativo  = isset($body['ativo']) ? (int)(bool)$body['ativo'] : 1;
    if (!$nome) { echo json_encode(['ok'=>false,'error'=>'Nome obrigatório']); exit; }
    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE hoopgrid_players SET nome=?,times=?,pais=?,premios=?,eras=?,nba_person_id=?,ativo=? WHERE id=?")
                ->execute([$nome,$times,$pais,$premios,$eras,$pid,$ativo,$id]);
        } else {
            $pdo->prepare("INSERT INTO hoopgrid_players (nome,times,pais,premios,eras,nba_person_id,ativo) VALUES (?,?,?,?,?,?,?)")
                ->execute([$nome,$times,$pais,$premios,$eras,$pid,$ativo]);
            $id = (int)$pdo->lastInsertId();
        }
        echo json_encode(['ok'=>true,'id'=>$id]);
    } catch (PDOException $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

// ── DELETAR JOGADOR ──────────────────────────────────────────────────────────
if ($action === 'delete_player' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit; }
    $pdo->prepare("DELETE FROM hoopgrid_players WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── TOGGLE ATIVO ─────────────────────────────────────────────────────────────
if ($action === 'toggle_active' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    $id    = (int)($body['id'] ?? 0);
    $ativo = (int)(bool)($body['ativo'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit; }
    $pdo->prepare("UPDATE hoopgrid_players SET ativo=? WHERE id=?")->execute([$ativo,$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── IMPORTAR PRÊMIOS (chunked + curl_multi) ──────────────────────────────────
if ($action === 'import_awards') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache');
    @ini_set('zlib.output_compression', 0);
    set_time_limit(90);
    if (ob_get_level()) ob_end_clean();

    $offset    = max(0, (int)($_GET['offset'] ?? 0));
    $chunkSize = 50;
    $parallel  = 5;

    $allPids = $pdo->query("SELECT nba_person_id FROM hoopgrid_players WHERE nba_person_id IS NOT NULL ORDER BY id")
                   ->fetchAll(PDO::FETCH_COLUMN);
    $total = count($allPids);

    if ($offset >= $total) { echo "DONE:{$total}\n"; flush(); exit; }

    $chunk      = array_slice($allPids, $offset, $chunkSize);
    $nextOffset = $offset + count($chunk);

    // Carregar premios atuais do bloco
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $stmtE = $pdo->prepare("SELECT nba_person_id, premios FROM hoopgrid_players WHERE nba_person_id IN ({$ph})");
    $stmtE->execute($chunk);
    $existingMap = [];
    foreach ($stmtE->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existingMap[(int)$r['nba_person_id']] = json_decode($r['premios'] ?: '[]', true) ?: [];
    }
    $stmtU = $pdo->prepare("UPDATE hoopgrid_players SET premios=? WHERE nba_person_id=?");

    $mapAward = function(string $desc, $allnbaNum): ?string {
        $d = strtolower(trim($desc));
        if (strpos($d, 'all-star')            !== false || strpos($d, 'all star')             !== false) return 'ALLSTAR';
        if (strpos($d, 'most valuable player') !== false || $d === 'mvp')                                 return 'MVP';
        if (strpos($d, 'defensive player of the year') !== false)                                         return 'DPOY';
        if (strpos($d, 'most improved')        !== false)                                                 return 'MIP';
        if (strpos($d, 'sixth man')            !== false)                                                 return '6THMAN';
        if (strpos($d, 'rookie of the year')   !== false || strpos($d, 'rookie award') !== false)        return 'ROY';
        if (strpos($d, 'hall of fame')         !== false)                                                 return 'HOF';
        if (strpos($d, 'champion')             !== false)                                                 return 'CHAMP';
        if (strpos($d, 'all-nba') !== false || strpos($d, 'all nba') !== false) {
            if ($allnbaNum == '1' || strpos($d, 'first')  !== false) return 'ALLNBA1';
            if ($allnbaNum == '2' || strpos($d, 'second') !== false) return 'ALLNBA2';
            if ($allnbaNum == '3' || strpos($d, 'third')  !== false) return 'ALLNBA3';
        }
        return null;
    };

    $hdrs_curl = [
        'Accept: application/json, text/plain, */*',
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
    ];

    $updated  = 0;
    $errors   = 0;
    $batchNum = 0;
    $batches  = array_chunk($chunk, $parallel);

    foreach ($batches as $batch) {
        $batchNum++;
        $mh = curl_multi_init();
        $handles = [];

        foreach ($batch as $pid) {
            $pid = (int)$pid;
            $ch = curl_init("https://stats.nba.com/stats/playerawards?PlayerID={$pid}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 25,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_ENCODING       => '',
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER     => $hdrs_curl,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$pid] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0) curl_multi_select($mh, 1.0);
        } while ($running > 0);

        $statusCodes = [];
        foreach ($handles as $pid => $ch) {
            $raw  = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $statusCodes[] = $code;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($code !== 200 || !$raw) { $errors++; continue; }

            $data = json_decode($raw, true);
            $rhdrs = $data['resultSets'][0]['headers'] ?? [];
            $rrows = $data['resultSets'][0]['rowSet']   ?? [];
            if (!$rhdrs) continue;

            $ix      = array_flip($rhdrs);
            $iDesc   = $ix['DESCRIPTION']        ?? null;
            $iAllNba = $ix['ALL_NBA_TEAM_NUMBER'] ?? null;
            if ($iDesc === null) continue;

            $current = $existingMap[$pid] ?? [];
            $changed = false;
            foreach ($rrows as $row) {
                $award = $mapAward($row[$iDesc] ?? '', $iAllNba !== null ? ($row[$iAllNba] ?? null) : null);
                if ($award && !in_array($award, $current, true)) {
                    $current[] = $award;
                    $changed   = true;
                }
            }
            if ($changed) {
                sort($current);
                $stmtU->execute([json_encode(array_values($current)), $pid]);
                $updated++;
            }
        }

        curl_multi_close($mh);
        echo "Lote {$batchNum}/" . count($batches) . " | HTTP: [" . implode(',', $statusCodes) . "] | atualizados: {$updated}\n";
        flush();
        usleep(400000);
    }

    if ($nextOffset >= $total) {
        echo "DONE:{$total}:{$updated}\n";
    } else {
        echo "NEXT:{$nextOffset}:{$total}:{$updated}\n";
    }
    flush();
    exit;
}

// ── IMPORTAR TIMES POR TEMPORADA (streaming) ─────────────────────────────────
if ($action === 'import_teams') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache');
    @ini_set('zlib.output_compression', 0);
    set_time_limit(600);
    if (ob_get_level()) ob_end_clean();

    $fromY = max(1979, (int)($_GET['from'] ?? 1979));
    $toY   = min(2024, (int)($_GET['to']   ?? 2024));
    $seasons = [];
    for ($y = $fromY; $y <= $toY; $y++) {
        $seasons[] = sprintf('%d-%02d', $y, ($y + 1) % 100);
    }

    $playerTeams = [];
    $done = 0; $total = count($seasons); $totalUpdated = 0;
    $headers_curl = [
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
    ];

    echo "Buscando {$total} temporadas ({$fromY} a {$toY})...\n";
    flush();

    foreach ($seasons as $season) {
        $qs = http_build_query([
            'College'=>'','Conference'=>'','Country'=>'','DateFrom'=>'','DateTo'=>'',
            'Division'=>'','DraftPick'=>'','DraftYear'=>'','GameScope'=>'','GameSegment'=>'',
            'Height'=>'','LastNGames'=>0,'LeagueID'=>'00','Location'=>'','MeasureType'=>'Base',
            'Month'=>0,'OpponentTeamID'=>0,'Outcome'=>'','PORound'=>0,'PaceAdjust'=>'N',
            'PerMode'=>'Totals','Period'=>0,'PlayerExperience'=>'','PlayerPosition'=>'',
            'PlusMinus'=>'N','Rank'=>'N','Season'=>$season,'SeasonSegment'=>'',
            'SeasonType'=>'Regular Season','ShotClockRange'=>'','StarterBench'=>'',
            'TeamID'=>0,'TwoWay'=>0,'VsConference'=>'','VsDivision'=>'','Weight'=>'',
        ]);
        $ch = curl_init("https://stats.nba.com/stats/leaguedashplayerstats?{$qs}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_ENCODING       => '',
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTPHEADER     => $headers_curl,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $done++;

        if ($err || $code !== 200) {
            echo "ERRO [{$done}/{$total}] {$season}: HTTP {$code} {$err}\n";
        } else {
            $data    = json_decode($raw, true);
            $hdrs    = $data['resultSets'][0]['headers'] ?? [];
            $rows    = $data['resultSets'][0]['rowSet']   ?? [];
            $ix      = array_flip($hdrs);
            $iPid    = $ix['PLAYER_ID']         ?? null;
            $iTeam   = $ix['TEAM_ABBREVIATION'] ?? null;
            $count   = 0;
            if ($iPid !== null && $iTeam !== null) {
                foreach ($rows as $row) {
                    $pid  = (int)$row[$iPid];
                    $team = strtoupper(trim($row[$iTeam] ?? ''));
                    if (!$pid || !$team || $team === 'TOT') continue;
                    $team = $TEAM_MAP[$team] ?? $team;
                    if (!isset($playerTeams[$pid])) $playerTeams[$pid] = [];
                    if (!in_array($team, $playerTeams[$pid], true)) $playerTeams[$pid][] = $team;
                    $count++;
                }
            }
            echo "OK [{$done}/{$total}] {$season}: {$count} ocorrencias, " . count($rows) . " linhas\n";
        }
        flush();
        usleep(400000);

        // Salvar no banco a cada 5 temporadas para evitar timeout do servidor
        if (($done % 5 === 0 || $done === $total) && !empty($playerTeams)) {
            $pids2 = array_keys($playerTeams);
            $ph2   = implode(',', array_fill(0, count($pids2), '?'));
            $sE = $pdo->prepare("SELECT nba_person_id, times FROM hoopgrid_players WHERE nba_person_id IN ({$ph2})");
            $sE->execute($pids2);
            $curMap = [];
            foreach ($sE->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                $curMap[(int)$cr['nba_person_id']] = json_decode($cr['times'] ?: '[]', true) ?: [];
            }
            $sU = $pdo->prepare("UPDATE hoopgrid_players SET times=? WHERE nba_person_id=?");
            $bc = 0;
            foreach ($playerTeams as $pid2 => $tm) {
                $mg = array_values(array_unique(array_merge($curMap[$pid2] ?? [], $tm)));
                sort($mg);
                $sU->execute([json_encode($mg), $pid2]);
                if (isset($curMap[$pid2])) $bc++;
            }
            $totalUpdated += $bc;
            echo "  -> Lote salvo: {$bc} jogadores (acumulado: {$totalUpdated})\n";
            flush();
            $playerTeams = [];
        }
    }

    echo "Concluido! {$totalUpdated} jogadores atualizados com todos os times.\n";
    flush();
    exit;
}

// ── IMPORT JSON ESTÁTICO (awards-static.json) ────────────────────────────────
if ($action === 'import_static') {
    header('Content-Type: application/json');
    $file = __DIR__ . '/awards-static.json';
    if (!file_exists($file)) { echo json_encode(['ok'=>false,'error'=>'awards-static.json não encontrado']); exit; }
    $data = json_decode(file_get_contents($file), true);
    if (!$data) { echo json_encode(['ok'=>false,'error'=>'JSON inválido']); exit; }

    $updated = 0; $skipped = 0;
    foreach ($data as $name => $awards) {
        if (empty($awards)) { $skipped++; continue; }
        $stmt = $pdo->prepare("SELECT id, premios FROM hoopgrid_players WHERE nome = ? LIMIT 1");
        $stmt->execute([$name]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$player) { $skipped++; continue; }
        $existing = json_decode($player['premios'] ?: '[]', true) ?: [];
        $merged = array_values(array_unique(array_merge($existing, $awards)));
        sort($merged);
        $pdo->prepare("UPDATE hoopgrid_players SET premios = ? WHERE id = ?")
            ->execute([json_encode($merged), $player['id']]);
        $updated++;
    }
    echo json_encode(['ok'=>true,'updated'=>$updated,'skipped'=>$skipped,'total'=>count($data)]);
    exit;
}

// ── IMPORT WIKIDATA ──────────────────────────────────────────────────────────
if ($action === 'import_wikidata') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache');
    @ini_set('zlib.output_compression', 0);
    set_time_limit(300);
    if (ob_get_level()) ob_end_clean();

    // Busca o Q ID correto de um prêmio pelo nome em inglês
    function wikidataFindQid(string $searchTerm): ?string {
        $url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
            'action'   => 'wbsearchentities',
            'search'   => $searchTerm,
            'language' => 'en',
            'type'     => 'item',
            'limit'    => 3,
            'format'   => 'json',
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['User-Agent: HoopGrid-FBA/1.0'],
        ]);
        $raw  = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($raw, true);
        foreach ($data['search'] ?? [] as $item) {
            return $item['id']; // primeiro resultado
        }
        return null;
    }

    // Busca vencedores via SPARQL usando dois padrões (P166 award received / P1346 winner)
    function wikidataWinners(string $qid): array {
        $sparql = 'SELECT DISTINCT ?playerLabel WHERE {
          { ?player wdt:P166 wd:' . $qid . ' . }
          UNION
          { ?edition wdt:P31 wd:' . $qid . ' . ?edition wdt:P1346 ?player . }
          ?player wdt:P31 wd:Q5 .
          SERVICE wikibase:label { bd:serviceParam wikibase:language "en" . }
        }';
        $url = 'https://query.wikidata.org/sparql?query=' . urlencode($sparql) . '&format=json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/sparql-results+json',
                'User-Agent: HoopGrid-FBA/1.0',
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$raw) return [];
        $result = json_decode($raw, true);
        return array_filter(array_map(
            fn($b) => trim($b['playerLabel']['value'] ?? ''),
            $result['results']['bindings'] ?? []
        ));
    }

    // Nome do prêmio para busca → código interno
    $awardsSearch = [
        'MVP'    => 'NBA Most Valuable Player Award',
        'DPOY'   => 'NBA Defensive Player of the Year Award',
        'ROY'    => 'NBA Rookie of the Year Award',
        'MIP'    => 'NBA Most Improved Player Award',
        '6THMAN' => 'NBA Sixth Man of the Year Award',
        'HOF'    => 'Naismith Memorial Basketball Hall of Fame',
        'ALLNBA1'=> 'All-NBA First Team',
        'ALLNBA2'=> 'All-NBA Second Team',
        'ALLNBA3'=> 'All-NBA Third Team',
    ];

    $totalUpdated = 0;

    foreach ($awardsSearch as $awardCode => $searchTerm) {
        echo "Localizando \"{$searchTerm}\"...\n";
        flush();

        $qid = wikidataFindQid($searchTerm);
        if (!$qid) {
            echo "  Q ID não encontrado — pulando\n\n";
            flush();
            usleep(800000);
            continue;
        }
        echo "  Q ID: {$qid}\n";
        flush();

        $names = wikidataWinners($qid);
        echo '  Nomes recebidos: ' . count($names) . "\n";
        flush();

        $updated = 0;
        foreach ($names as $name) {
            $stmt = $pdo->prepare("SELECT id, premios FROM hoopgrid_players WHERE nome = ? LIMIT 1");
            $stmt->execute([$name]);
            $player = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$player) continue;
            $existing = json_decode($player['premios'] ?: '[]', true) ?: [];
            if (!in_array($awardCode, $existing, true)) {
                $existing[] = $awardCode;
                sort($existing);
                $pdo->prepare("UPDATE hoopgrid_players SET premios = ? WHERE id = ?")
                    ->execute([json_encode(array_values($existing)), $player['id']]);
                $updated++;
                $totalUpdated++;
            }
        }

        echo "  Banco atualizado: {$updated} jogadores\n\n";
        flush();
        usleep(1500000);
    }

    echo "Concluído! Total de prêmios adicionados: {$totalUpdated}\n";
    flush();
    exit;
}

// ── IMPORT VIA JSON LOCAL ─────────────────────────────────────────────────────
if ($action === 'import_json' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $rows = $body['rows'] ?? [];
    if (!$rows) { echo json_encode(['ok'=>false,'error'=>'Nenhum dado recebido']); exit; }
    $TMAP = ['NJN'=>'BKN','SEA'=>'OKC','NOH'=>'NOP','NOK'=>'NOP','VAN'=>'MEM','SDC'=>'LAC','KCK'=>'SAC'];
    $stmtI = $pdo->prepare("INSERT INTO hoopgrid_players (nome,times,pais,premios,eras,nba_person_id)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            eras=IF(eras='[]' OR eras IS NULL,VALUES(eras),eras),
            nba_person_id=COALESCE(nba_person_id,VALUES(nba_person_id))");
    $inserted = 0; $skipped = 0;
    foreach ($rows as $row) {
        $nome = trim($row['DISPLAY_FIRST_LAST'] ?? '');
        if (!$nome) { $skipped++; continue; }
        $from = (int)($row['FROM_YEAR'] ?? 0);
        $to   = (int)($row['TO_YEAR']   ?? date('Y'));
        if ($from < 1979) { $skipped++; continue; }
        $eras = calcEras($from, $to);
        if (empty($eras)) { $skipped++; continue; }
        $teamRaw = strtoupper(trim($row['TEAM_ABBREVIATION'] ?? ''));
        $team    = $TMAP[$teamRaw] ?? $teamRaw;
        $times   = $team ? [$team] : [];
        $pid     = !empty($row['PERSON_ID']) ? (int)$row['PERSON_ID'] : null;
        try { $stmtI->execute([$nome, json_encode($times), 'USA', '[]', json_encode($eras), $pid]); $inserted++; }
        catch (PDOException $e) { $skipped++; }
    }
    echo json_encode(['ok'=>true,'inserted'=>$inserted,'skipped'=>$skipped,'total'=>count($rows)]);
    exit;
}

// ── UI ────────────────────────────────────────────────────────────────────────
$totalPlayers = (int)$pdo->query("SELECT COUNT(*) FROM hoopgrid_players WHERE ativo=1")->fetchColumn();
$totalAll     = (int)$pdo->query("SELECT COUNT(*) FROM hoopgrid_players")->fetchColumn();
$comPremios   = (int)$pdo->query("SELECT COUNT(*) FROM hoopgrid_players WHERE ativo=1 AND premios != '[]'")->fetchColumn();
$userInitial  = strtoupper(mb_substr($u['nome'] ?? 'A', 0, 1));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Controle de Jogadores — FBA Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025;--red-soft:rgba(252,0,37,.10);--red-glow:rgba(252,0,37,.18);
  --bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;
  --border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--border-red:rgba(252,0,37,.22);
  --text:#f0f0f3;--text-2:#868690;--text-3:#48484f;
  --font:'Poppins',sans-serif;--radius:14px;--radius-sm:10px;
  --ease:cubic-bezier(.2,.8,.2,1);--t:200ms;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

/* ── Layout ── */
.page-layout{display:flex;min-height:100vh}
.sidebar{width:240px;flex-shrink:0;background:var(--panel);border-right:1px solid var(--border);
         display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:200;overflow-y:auto}
.page-content{flex:1;margin-left:240px;min-width:0;padding:28px 24px 60px;overflow-y:auto;min-height:100vh}

/* ── Sidebar ── */
.sb-header{display:flex;align-items:center;gap:10px;padding:16px 14px 12px;border-bottom:1px solid var(--border);flex-shrink:0}
.sb-logo{width:30px;height:30px;border-radius:8px;background:var(--red);display:flex;align-items:center;
         justify-content:center;font-weight:800;font-size:11px;color:#fff;flex-shrink:0}
.sb-brand{font-weight:800;font-size:13px;color:var(--text);flex:1}
.sb-brand span{color:var(--red)}
.sb-user{padding:14px;border-bottom:1px solid var(--border)}
.sb-avatar{width:40px;height:40px;border-radius:50%;background:var(--red-soft);border:2px solid var(--border-red);
           display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:var(--red);margin-bottom:8px}
.sb-user-name{font-size:13px;font-weight:700;color:#fff}
.sb-user-role{font-size:10px;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.sb-stats{padding:10px 14px;border-bottom:1px solid var(--border);display:flex;flex-direction:row;gap:6px}
.sb-stat{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;gap:4px;
         padding:8px 4px;background:var(--panel-2);border-radius:8px;border:1px solid var(--border)}
.sb-stat i{font-size:13px;color:var(--red)}
.sb-stat-val{font-size:12px;font-weight:700;color:var(--text);line-height:1.2}
.sb-stat-label{font-size:9px;color:var(--text-3)}
.sb-nav{flex:1;padding:8px 0;overflow-y:auto}
.sb-nav-section{font-size:9px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:8px 14px 4px}
.sb-link{display:flex;align-items:center;gap:10px;padding:9px 14px;text-decoration:none;
         font-size:12px;font-weight:500;color:var(--text-2);transition:all var(--t) var(--ease);border-left:3px solid transparent}
.sb-link i{width:16px;text-align:center;font-size:13px}
.sb-link:hover{background:var(--panel-2);color:var(--text);border-left-color:var(--border-md)}
.sb-link.active{background:var(--red-soft);color:var(--red);border-left-color:var(--red);font-weight:700}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border);flex-shrink:0}
.sb-logout{display:flex;align-items:center;gap:8px;width:100%;padding:8px 12px;border-radius:8px;
           border:1px solid var(--border);background:transparent;color:var(--text-2);text-decoration:none;
           font-family:var(--font);font-size:12px;font-weight:600;transition:all var(--t) var(--ease)}
.sb-logout:hover{background:rgba(252,0,37,.1);border-color:var(--border-red);color:var(--red)}

/* ── Content ── */
.panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:20px}
.stat-card{background:var(--panel-2);border-radius:10px;padding:14px;text-align:center}
.stat-v{font-size:1.8rem;font-weight:800}
.stat-l{font-size:11px;color:var(--text-2);margin-top:2px}
#log{background:#000;border-radius:8px;padding:10px;font-family:monospace;font-size:12px;color:#4ade80;max-height:160px;overflow-y:auto;min-height:44px}
.inp{background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;padding:7px 11px;color:var(--text);font-size:13px;width:100%;font-family:var(--font)}
.inp:focus{outline:none;border-color:rgba(249,115,22,.5)}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{padding:8px 10px;color:var(--text-2);font-weight:600;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;font-size:11px;text-transform:uppercase;letter-spacing:.5px}
.tbl td{padding:8px 10px;border-bottom:1px solid var(--border);vertical-align:middle}
.tbl tr:hover td{background:rgba(255,255,255,.02)}
.tag{display:inline-block;background:rgba(249,115,22,.15);color:#fb923c;border-radius:4px;padding:1px 6px;font-size:11px;margin:1px}
.tag.blue{background:rgba(59,130,246,.15);color:#60a5fa}
.tag.green{background:rgba(34,197,94,.12);color:#4ade80}
.tag.red{background:rgba(239,68,68,.12);color:#f87171}
.tag.gray{background:rgba(255,255,255,.07);color:#9ca3af}
.btn-ic{background:none;border:1px solid var(--border-md);border-radius:6px;padding:4px 8px;color:var(--text-2);cursor:pointer;font-size:12px;transition:.15s}
.btn-ic:hover{border-color:rgba(249,115,22,.5);color:#fb923c}
.btn-ic.danger:hover{border-color:rgba(239,68,68,.5);color:#f87171}
.btn-ic.success:hover{border-color:rgba(34,197,94,.5);color:#4ade80}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:var(--panel);border:1px solid var(--border-md);border-radius:var(--radius);padding:24px;width:min(700px,96vw);max-height:90vh;overflow-y:auto}
.modal-box h5{margin:0 0 18px;font-weight:700}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
.form-row.full{grid-template-columns:1fr}
.f-label{display:block;font-size:11px;color:var(--text-2);margin-bottom:5px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.chip-group{display:flex;flex-wrap:wrap;gap:6px;margin-top:4px}
.chip{display:flex;align-items:center;gap:4px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:6px;padding:4px 8px;font-size:12px;cursor:pointer;user-select:none;transition:.15s}
.chip:hover{border-color:rgba(249,115,22,.4)}
.chip.sel{background:rgba(249,115,22,.15);border-color:rgba(249,115,22,.5);color:#fb923c}
.chip.sel.blue{background:rgba(59,130,246,.15);border-color:rgba(59,130,246,.5);color:#60a5fa}
.chip.sel.green{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.4);color:#4ade80}
.pag{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.pag button{background:var(--panel-2);border:1px solid var(--border-md);border-radius:6px;padding:5px 11px;color:var(--text-2);cursor:pointer;font-size:12px;font-family:var(--font)}
.pag button:hover{border-color:rgba(249,115,22,.4);color:#fb923c}
.pag button.active{background:rgba(249,115,22,.15);border-color:rgba(249,115,22,.5);color:#fb923c}
.search-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.search-bar .inp{flex:1;min-width:160px}
.page-title{font-size:20px;font-weight:800;color:var(--text);margin-bottom:4px}
.page-sub{font-size:12px;color:var(--text-2);margin-bottom:24px}
/* ── Mobile ── */
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:199}
.sb-overlay.open{display:block}
.sb-close{display:none;align-items:center;justify-content:center;background:none;border:1px solid var(--border);border-radius:8px;color:var(--text);width:30px;height:30px;cursor:pointer;flex-shrink:0}
.mob-bar{display:none;align-items:center;gap:10px;height:52px;padding:0 14px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.mob-ham{background:none;border:1px solid var(--border);border-radius:8px;color:var(--text);width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px}
.mob-title{font-size:14px;font-weight:800;color:var(--text);flex:1}
.mob-title span{color:var(--red)}
@media(max-width:768px){
  .sidebar{transform:translateX(-100%);transition:transform 280ms var(--ease);z-index:200}
  .sidebar.open{transform:translateX(0)}
  .sb-close{display:flex}
  .mob-bar{display:flex}
  .page-content{margin-left:0;padding-top:0}
}
</style>
</head>
<body>
<div class="page-layout">

<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-logo">FBA</div>
    <div class="sb-brand">FBA <span>Admin</span></div>
    <button class="sb-close" onclick="closeSidebar()"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?= $userInitial ?></div>
    <div class="sb-user-name"><?= htmlspecialchars($u['nome'] ?? '') ?></div>
    <div class="sb-user-role">Administrador</div>
  </div>
  <div class="sb-stats">
    <div class="sb-stat">
      <img src="../moeda.png" style="width:18px;height:18px;object-fit:contain">
      <div class="sb-stat-val"><?= number_format($u['pontos'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">Moedas</div>
    </div>
    <div class="sb-stat">
      <img src="../lebron.png" style="width:18px;height:18px;object-fit:contain">
      <div class="sb-stat-val"><?= number_format($u['fba_points'] ?? 0, 0, ',', '.') ?></div>
      <div class="sb-stat-label">FBA Pts</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-section">Menu</div>
    <a href="../index.php"              class="sb-link"><i class="bi bi-lightning-charge"></i>Apostas</a>
    <a href="../games.php"              class="sb-link"><i class="bi bi-joystick"></i>Games</a>
    <a href="../user/ranking-geral.php" class="sb-link"><i class="bi bi-trophy"></i>Ranking Geral</a>
    <div class="sb-nav-section">Admin</div>
    <a href="controlegames.php"         class="sb-link"><i class="bi bi-gear-fill"></i>Controle de Jogos</a>
    <a href="dashboard.php"             class="sb-link"><i class="bi bi-receipt-cutoff"></i>Controle Apostas</a>
    <a href="dadosjogadores.php"        class="sb-link active"><i class="bi bi-person-lines-fill"></i>Dados dos Jogadores</a>
  </nav>
  <div class="sb-footer">
    <a href="../auth/logout.php" class="sb-logout"><i class="bi bi-box-arrow-right"></i>Sair</a>
  </div>
</aside>

<!-- Mob topbar -->
<div class="mob-bar">
  <button class="mob-ham" onclick="openSidebar()"><i class="bi bi-list"></i></button>
  <span class="mob-title">FBA <span>Admin</span></span>
</div>

<!-- CONTEÚDO -->
<div class="page-content">
  <div class="page-title">🏀 Controle de Jogadores NBA</div>
  <div class="page-sub">Banco centralizado usado pelo Hoop Grid e outros jogos. Importe via API ou gerencie manualmente.</div>

  <!-- Stats -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-v"><?= $totalAll ?></div><div class="stat-l">Total no banco</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-v" style="color:#4ade80"><?= $totalPlayers ?></div><div class="stat-l">Ativos</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-v" style="color:#f59e0b"><?= $comPremios ?></div><div class="stat-l">Com prêmios</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-v" style="color:#f87171"><?= $totalPlayers - $comPremios ?></div><div class="stat-l">Sem prêmios</div></div></div>
  </div>

  <!-- Import (acordeon fechado) -->
  <div class="panel mb-4">
    <div style="display:flex;justify-content:space-between;align-items:center;cursor:pointer" onclick="toggleImport()">
      <h6 style="margin:0;font-weight:700;font-size:13px"><i class="bi bi-cloud-download me-2" style="color:var(--red)"></i>Importar via NBA Stats API</h6>
      <i class="bi bi-chevron-down" id="importChevron" style="transition:.2s;color:var(--text-2)"></i>
    </div>
    <div id="importBody" style="display:none;margin-top:16px">
      <p style="font-size:12px;color:var(--text-2);margin-bottom:12px">Busca todos os jogadores desde 1980. Existentes não são sobrescritos (prêmios/país preservados).</p>
      <div class="d-flex gap-2 mb-3 flex-wrap">
        <button class="btn btn-sm btn-danger fw-bold" id="btnImport"><i class="bi bi-cloud-download me-1"></i>Importar Jogadores (API)</button>
        <button class="btn btn-sm fw-bold" id="btnImportTeams" style="background:rgba(59,130,246,.2);border:1px solid rgba(59,130,246,.4);color:#60a5fa"><i class="bi bi-building me-1"></i>Completar Times (46 temp.)</button>
        <button class="btn btn-sm fw-bold" id="btnImportAwards" style="background:rgba(34,197,94,.15);border:1px solid rgba(34,197,94,.3);color:#4ade80"><i class="bi bi-award me-1"></i>Prêmios via NBA API</button>
        <button class="btn btn-sm fw-bold" id="btnImportAll" style="background:rgba(252,0,37,.15);border:1px solid rgba(252,0,37,.4);color:#fc0025;font-weight:800"><i class="bi bi-stars me-1"></i>Importar Tudo (Estático + Wikidata)</button>
        <button class="btn btn-sm fw-bold" id="btnImportWikidata" style="background:rgba(99,102,241,.15);border:1px solid rgba(99,102,241,.4);color:#818cf8"><i class="bi bi-globe me-1"></i>Prêmios via Wikidata</button>
        <button class="btn btn-sm fw-bold" id="btnImportStatic" style="background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.4);color:#fbbf24"><i class="bi bi-file-earmark-code me-1"></i>Prêmios Estáticos (JSON)</button>
      </div>
      <div id="log">Aguardando...</div>
    </div>
  </div>

  <!-- Gerenciador -->
  <div class="panel">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
      <h6 style="margin:0;font-weight:700;font-size:13px">Gerenciar Jogadores</h6>
      <button class="btn btn-sm btn-warning fw-bold" onclick="openModal(null)"><i class="bi bi-plus-circle me-1"></i>Novo Jogador</button>
    </div>

    <!-- Filtros -->
    <div class="search-bar">
      <input class="inp" id="fq" placeholder="Buscar por nome..." oninput="debSearch()">
      <select class="inp" style="flex:0 0 110px" id="fAtivo" onchange="loadPlayers(1)">
        <option value="">Todos</option>
        <option value="1" selected>Ativos</option>
        <option value="0">Inativos</option>
      </select>
      <input class="inp" style="flex:0 0 90px" id="fPais" placeholder="País" oninput="debSearch()">
      <select class="inp" style="flex:0 0 100px" id="fEra" onchange="loadPlayers(1)">
        <option value="">Era</option>
        <option>80s</option><option>90s</option><option>00s</option><option>10s</option><option>20s</option>
      </select>
      <select class="inp" style="flex:0 0 110px" id="fTime" onchange="loadPlayers(1)">
        <option value="">Time</option>
        <?php foreach(['ATL','BOS','BKN','CHA','CHI','CLE','DAL','DEN','DET','GSW','HOU','IND','LAC','LAL','MEM','MIA','MIL','MIN','NOP','NYK','OKC','ORL','PHI','PHX','POR','SAC','SAS','TOR','UTA','WAS'] as $t): ?>
        <option><?= $t ?></option>
        <?php endforeach; ?>
      </select>
      <select class="inp" style="flex:0 0 120px" id="fPremio" onchange="loadPlayers(1)">
        <option value="">Prêmio</option>
        <?php foreach(['MVP','DPOY','MIP','6THMAN','ROY','CHAMP','ALLSTAR','ALLNBA1','ALLNBA2','ALLNBA3','HOF'] as $p): ?>
        <option><?= $p ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Tabela -->
    <div style="overflow-x:auto">
    <table class="tbl">
      <thead>
        <tr>
          <th>Nome</th>
          <th>Times</th>
          <th>País</th>
          <th>Prêmios</th>
          <th>Eras</th>
          <th>Status</th>
          <th style="text-align:right">Ações</th>
        </tr>
      </thead>
      <tbody id="tbody"><tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-3)">Carregando...</td></tr></tbody>
    </table>
    </div>

    <!-- Paginação -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;flex-wrap:wrap;gap:8px">
      <span id="pageInfo" style="font-size:12px;color:var(--text-2)"></span>
      <div class="pag" id="pagination"></div>
    </div>
  </div>
</div><!-- /page-content -->
</div><!-- /page-layout -->

<!-- MODAL EDIÇÃO -->
<div class="modal-overlay" id="modalOverlay" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <h5 id="modalTitle" style="font-size:16px">Editar Jogador</h5>
    <input type="hidden" id="eId">

    <div class="form-row">
      <div>
        <label class="f-label">Nome *</label>
        <input class="inp" id="eNome" placeholder="LeBron James">
      </div>
      <div>
        <label class="f-label">País</label>
        <input class="inp" id="ePais" placeholder="USA" maxlength="5">
      </div>
    </div>

    <div class="form-row full">
      <label class="f-label">Times (clique para selecionar)</label>
      <div class="chip-group" id="chipTimes"></div>
    </div>

    <div class="form-row full">
      <label class="f-label">Eras</label>
      <div class="chip-group" id="chipEras"></div>
    </div>

    <div class="form-row full">
      <label class="f-label">Prêmios</label>
      <div class="chip-group" id="chipPremios"></div>
    </div>

    <div class="form-row" style="margin-top:14px">
      <div>
        <label class="f-label">NBA Person ID</label>
        <input class="inp" id="ePid" type="number" placeholder="opcional">
      </div>
      <div>
        <label class="f-label">Status</label>
        <select class="inp" id="eAtivo">
          <option value="1">Ativo</option>
          <option value="0">Inativo</option>
        </select>
      </div>
    </div>

    <div style="display:flex;gap:10px;margin-top:16px">
      <button class="btn btn-warning fw-bold flex-fill" onclick="savePlayer()"><i class="bi bi-save me-1"></i>Salvar</button>
      <button class="btn btn-outline-secondary" onclick="closeModal()">Cancelar</button>
    </div>
    <div id="modalErr" style="color:#f87171;font-size:12px;margin-top:8px"></div>
  </div>
</div>

<script>
const NBA_TEAMS = ['ATL','BOS','BKN','CHA','CHI','CLE','DAL','DEN','DET','GSW','HOU','IND','LAC','LAL','MEM','MIA','MIL','MIN','NOP','NYK','OKC','ORL','PHI','PHX','POR','SAC','SAS','TOR','UTA','WAS'];
const ALL_ERAS  = ['80s','90s','00s','10s','20s'];
const ALL_PREMIOS = ['MVP','DPOY','MIP','6THMAN','ROY','CHAMP','ALLSTAR','ALLNBA1','ALLNBA2','ALLNBA3','HOF'];

let _debTimer = null;
let _currentPage = 1;

function debSearch() { clearTimeout(_debTimer); _debTimer = setTimeout(() => loadPlayers(1), 350); }

function qs(id) { return document.getElementById(id); }

async function loadPlayers(page = 1) {
  _currentPage = page;
  const params = new URLSearchParams({
    action: 'list', page,
    q:     qs('fq').value,
    ativo: qs('fAtivo').value,
    pais:  qs('fPais').value,
    era:   qs('fEra').value,
    time:  qs('fTime').value,
    premio:qs('fPremio').value,
  });
  const r = await fetch('?' + params);
  const d = await r.json();
  renderTable(d);
}

function renderTable(d) {
  const tb = qs('tbody');
  if (!d.players?.length) {
    tb.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#555">Nenhum jogador encontrado.</td></tr>';
    qs('pageInfo').textContent = '';
    qs('pagination').innerHTML = '';
    return;
  }

  tb.innerHTML = d.players.map(p => {
    const times   = (JSON.parse(p.times  ||'[]')).map(t => `<span class="tag blue">${t}</span>`).join('');
    const premios = (JSON.parse(p.premios||'[]')).map(a => `<span class="tag">${a}</span>`).join('');
    const eras    = (JSON.parse(p.eras   ||'[]')).map(e => `<span class="tag green">${e}</span>`).join('');
    const ativo   = p.ativo == 1
      ? '<span class="tag green">ativo</span>'
      : '<span class="tag red">inativo</span>';
    return `<tr>
      <td><strong>${esc(p.nome)}</strong></td>
      <td>${times || '<span style="color:#555">—</span>'}</td>
      <td><span class="tag gray">${esc(p.pais)}</span></td>
      <td>${premios || '<span style="color:#555">—</span>'}</td>
      <td>${eras || '<span style="color:#555">—</span>'}</td>
      <td>${ativo}</td>
      <td style="text-align:right;white-space:nowrap">
        <button class="btn-ic" onclick="openModal(${JSON.stringify(p).replace(/"/g,'&quot;')})" title="Editar"><i class="bi bi-pencil"></i></button>
        <button class="btn-ic ${p.ativo==1?'danger':'success'}" onclick="toggleActive(${p.id},${p.ativo==1?0:1})" title="${p.ativo==1?'Desativar':'Ativar'}">
          <i class="bi bi-${p.ativo==1?'eye-slash':'eye'}"></i>
        </button>
        <button class="btn-ic danger" onclick="deletePlayer(${p.id},'${esc(p.nome)}')" title="Excluir"><i class="bi bi-trash"></i></button>
      </td>
    </tr>`;
  }).join('');

  qs('pageInfo').textContent = `${d.total} jogadores · página ${d.page} de ${d.pages}`;

  const pag = qs('pagination');
  pag.innerHTML = '';
  const from = Math.max(1, d.page - 3);
  const to   = Math.min(d.pages, d.page + 3);
  if (d.page > 1) addPagBtn(pag, '‹', d.page - 1);
  for (let i = from; i <= to; i++) addPagBtn(pag, i, i, i === d.page);
  if (d.page < d.pages) addPagBtn(pag, '›', d.page + 1);
}

function addPagBtn(container, label, page, active = false) {
  const b = document.createElement('button');
  b.textContent = label;
  if (active) b.classList.add('active');
  b.onclick = () => loadPlayers(page);
  container.appendChild(b);
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── MODAL ────────────────────────────────────────────────────────────────────
let _selTimes = [], _selEras = [], _selPremios = [];

function buildChips(containerId, options, selected, color='') {
  const c = qs(containerId);
  c.innerHTML = options.map(o => {
    const s = selected.includes(o);
    return `<div class="chip ${s ? 'sel '+color : ''}" onclick="toggleChip(this,'${o}','${containerId}','${color}')">${o}</div>`;
  }).join('');
}

function toggleChip(el, val, containerId, color) {
  el.classList.toggle('sel');
  if (color) el.classList.toggle(color, el.classList.contains('sel'));
  syncChips();
}

function syncChips() {
  _selTimes  = [...qs('chipTimes').querySelectorAll('.sel')].map(e => e.textContent);
  _selEras   = [...qs('chipEras').querySelectorAll('.sel')].map(e => e.textContent);
  _selPremios= [...qs('chipPremios').querySelectorAll('.sel')].map(e => e.textContent);
}

function openModal(player) {
  qs('modalTitle').textContent = player ? 'Editar Jogador' : 'Novo Jogador';
  const p = player || {};
  qs('eId').value    = p.id   || '';
  qs('eNome').value  = p.nome || '';
  qs('ePais').value  = p.pais || 'USA';
  qs('ePid').value   = p.nba_person_id || '';
  qs('eAtivo').value = p.ativo != null ? p.ativo : 1;
  qs('modalErr').textContent = '';

  _selTimes   = JSON.parse(p.times   || '[]');
  _selEras    = JSON.parse(p.eras    || '[]');
  _selPremios = JSON.parse(p.premios || '[]');

  buildChips('chipTimes',  NBA_TEAMS,   _selTimes,   'blue');
  buildChips('chipEras',   ALL_ERAS,    _selEras,    'green');
  buildChips('chipPremios',ALL_PREMIOS, _selPremios, '');

  qs('modalOverlay').classList.add('open');
}

function closeModal() { qs('modalOverlay').classList.remove('open'); }

async function savePlayer() {
  syncChips();
  const id = qs('eId').value;
  const body = {
    id:            id ? parseInt(id) : 0,
    nome:          qs('eNome').value.trim(),
    pais:          qs('ePais').value.trim().toUpperCase() || 'USA',
    times:         _selTimes,
    eras:          _selEras,
    premios:       _selPremios,
    nba_person_id: qs('ePid').value ? parseInt(qs('ePid').value) : null,
    ativo:         parseInt(qs('eAtivo').value),
  };
  if (!body.nome) { qs('modalErr').textContent = 'Nome é obrigatório.'; return; }
  const r = await fetch('?action=save_player', { method:'POST', body: JSON.stringify(body) });
  const d = await r.json();
  if (d.ok) { closeModal(); loadPlayers(_currentPage); } else { qs('modalErr').textContent = d.error; }
}

async function toggleActive(id, ativo) {
  await fetch('?action=toggle_active', { method:'POST', body: JSON.stringify({id, ativo}) });
  loadPlayers(_currentPage);
}

async function deletePlayer(id, nome) {
  if (!confirm(`Excluir permanentemente "${nome}"?`)) return;
  await fetch('?action=delete_player', { method:'POST', body: JSON.stringify({id}) });
  loadPlayers(_currentPage);
}

// ── ACORDEON IMPORT ──────────────────────────────────────────────────────────
function toggleImport() {
  const body = qs('importBody');
  const chev = qs('importChevron');
  const open = body.style.display === 'none';
  body.style.display = open ? 'block' : 'none';
  chev.style.transform = open ? 'rotate(180deg)' : '';
}

// ── IMPORT ───────────────────────────────────────────────────────────────────
qs('btnImport').addEventListener('click', async () => {
  const log = qs('log');
  const btn = qs('btnImport');
  btn.disabled = true;
  log.textContent = 'Conectando à NBA Stats API...\n';
  try {
    const r = await fetch('?action=import');
    const d = await r.json();
    if (d.ok) {
      log.textContent += `✅ Concluído!\nTotal na API: ${d.total}\nInseridos/atualizados: ${d.inserted}\nIgnorados: ${d.skipped}\n\nAtualize a página para ver o novo total.`;
      loadPlayers(1);
    } else {
      log.textContent += `❌ Erro: ${d.error}`;
    }
  } catch(e) {
    log.textContent += `❌ Falha: ${e.message}`;
  }
  btn.disabled = false;
});

// ── IMPORT TIMES (streaming) ─────────────────────────────────────────────────
qs('btnImportTeams').addEventListener('click', async () => {
  const log = qs('log');
  const btn = qs('btnImportTeams');
  const btn2 = qs('btnImport');
  btn.disabled = true;
  btn2.disabled = true;
  log.textContent = 'Iniciando busca de times por temporada (pode demorar ~3 min)...\n';

  try {
    const r = await fetch('?action=import_teams');
    if (!r.body) {
      log.textContent += '❌ Streaming não suportado neste servidor.\n';
      btn.disabled = false; btn2.disabled = false;
      return;
    }
    const reader = r.body.getReader();
    const dec = new TextDecoder();
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      const chunk = dec.decode(value);
      log.textContent += chunk;
      log.scrollTop = log.scrollHeight;
    }
    loadPlayers(1);
  } catch(e) {
    log.textContent += `\n❌ Falha: ${e.message}`;
  }
  btn.disabled = false;
  btn2.disabled = false;
});

// ── IMPORT PRÊMIOS (chunked, auto-chain) ─────────────────────────────────────
async function importAwardsChunk(offset) {
  const log = qs('log');
  try {
    const r = await fetch(`?action=import_awards&offset=${offset}`);
    const reader = r.body.getReader();
    const dec = new TextDecoder();
    let buf = '';
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      const txt = dec.decode(value);
      buf += txt;
      log.textContent += txt;
      log.scrollTop = log.scrollHeight;
    }
    const mNext = buf.match(/NEXT:(\d+):(\d+)/);
    const mDone = buf.match(/DONE:(\d+)/);
    if (mNext) {
      const pct = Math.round((parseInt(mNext[1]) / parseInt(mNext[2])) * 100);
      log.textContent += `--- ${pct}% (${mNext[1]}/${mNext[2]}) ---\n`;
      log.scrollTop = log.scrollHeight;
      setTimeout(() => importAwardsChunk(parseInt(mNext[1])), 200);
    } else if (mDone) {
      log.textContent += `\n✅ Prêmios importados! ${mDone[1]} jogadores verificados.\n`;
      log.scrollTop = log.scrollHeight;
      ['btnImportAwards','btnImport','btnImportTeams'].forEach(id => { const e = qs(id); if(e) e.disabled = false; });
      loadPlayers(1);
    } else {
      log.textContent += '\n⚠️ Resposta inesperada. Verifique o servidor.\n';
      ['btnImportAwards','btnImport','btnImportTeams'].forEach(id => { const e = qs(id); if(e) e.disabled = false; });
    }
  } catch(e) {
    log.textContent += `\n❌ Falha: ${e.message} — tentando novamente...\n`;
    log.scrollTop = log.scrollHeight;
    setTimeout(() => importAwardsChunk(offset), 3000);
  }
}

qs('btnImportAwards').addEventListener('click', () => {
  const log = qs('log');
  ['btnImportAwards','btnImport','btnImportTeams'].forEach(id => { const e = qs(id); if(e) e.disabled = true; });
  log.textContent = 'Importando prêmios (MVP, All-Star, All-NBA, CHAMP, HOF...).\nProcessa 100 jogadores por bloco com 15 req. paralelas.\n\n';
  importAwardsChunk(0);
});

// ── IMPORTAR TUDO (Estático + Wikidata em sequência) ─────────────────────────
qs('btnImportAll').addEventListener('click', async () => {
  const log = qs('log');
  const allBtns = ['btnImportAll','btnImport','btnImportTeams','btnImportAwards','btnImportWikidata','btnImportStatic'];
  allBtns.forEach(id => { const e = qs(id); if (e) e.disabled = true; });

  log.textContent = '═══ ETAPA 1/2 — Prêmios Estáticos ═══\n';
  try {
    const r = await fetch('?action=import_static');
    const d = await r.json();
    if (d.ok) {
      log.textContent += `✅ Estático concluído: ${d.updated} atualizados, ${d.skipped} não encontrados\n\n`;
    } else {
      log.textContent += `⚠️ Estático: ${d.error}\n\n`;
    }
  } catch(e) {
    log.textContent += `❌ Falha no estático: ${e.message}\n\n`;
  }
  log.scrollTop = log.scrollHeight;

  log.textContent += '═══ ETAPA 2/2 — Wikidata ═══\n';
  try {
    const r = await fetch('?action=import_wikidata');
    if (!r.body) { log.textContent += '❌ Streaming não suportado.\n'; }
    else {
      const reader = r.body.getReader();
      const dec = new TextDecoder();
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        log.textContent += dec.decode(value);
        log.scrollTop = log.scrollHeight;
      }
    }
  } catch(e) {
    log.textContent += `❌ Falha no Wikidata: ${e.message}\n`;
  }

  log.textContent += '\n✅ Importação completa!\n';
  log.scrollTop = log.scrollHeight;
  allBtns.forEach(id => { const e = qs(id); if (e) e.disabled = false; });
  loadPlayers(1);
});

// ── IMPORT WIKIDATA (streaming) ──────────────────────────────────────────────
qs('btnImportWikidata').addEventListener('click', async () => {
  const log = qs('log');
  const btn = qs('btnImportWikidata');
  btn.disabled = true;
  log.textContent = 'Buscando prêmios no Wikidata (MVP, DPOY, ROY, MIP, 6THMAN, HOF, All-NBA)...\nCada prêmio leva ~2s. Aguarde.\n\n';
  try {
    const r = await fetch('?action=import_wikidata');
    if (!r.body) { log.textContent += '❌ Streaming não suportado.\n'; btn.disabled = false; return; }
    const reader = r.body.getReader();
    const dec = new TextDecoder();
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      log.textContent += dec.decode(value);
      log.scrollTop = log.scrollHeight;
    }
    loadPlayers(1);
  } catch(e) {
    log.textContent += `\n❌ Falha: ${e.message}`;
  }
  btn.disabled = false;
});

// ── IMPORT ESTÁTICO ──────────────────────────────────────────────────────────
qs('btnImportStatic').addEventListener('click', async () => {
  const log = qs('log');
  const btn = qs('btnImportStatic');
  btn.disabled = true;
  log.textContent = 'Importando prêmios do arquivo estático (awards-static.json)...\n';
  try {
    const r = await fetch('?action=import_static');
    const d = await r.json();
    if (d.ok) {
      log.textContent += `✅ Concluído!\nAtualizados: ${d.updated} | Não encontrados: ${d.skipped} | Total no JSON: ${d.total}\n`;
      loadPlayers(1);
    } else {
      log.textContent += `❌ Erro: ${d.error}\n`;
    }
  } catch(e) {
    log.textContent += `❌ Falha: ${e.message}\n`;
  }
  btn.disabled = false;
});

// Carregar ao abrir
loadPlayers(1);

function openSidebar() {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sbOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sbOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
</script>
</body>
</html>
