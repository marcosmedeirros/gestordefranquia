<?php
/**
 * hoopgrid-seeder.php
 * Gerenciador do banco de jogadores NBA (usado em múltiplos jogos)
 * Acesso restrito a admins
 */
session_start();
require '../core/conexao.php';
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

$stmt = $pdo->prepare("SELECT is_admin FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || !$u['is_admin']) { http_response_code(403); die("Acesso negado"); }

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
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = 50;
    $off   = ($page - 1) * $limit;
    $q     = trim($_GET['q'] ?? '');
    $fAtivo = $_GET['ativo'] ?? '';
    $fPais  = trim($_GET['pais'] ?? '');
    $fPremio = trim($_GET['premio'] ?? '');
    $fEra   = trim($_GET['era'] ?? '');
    $fTime  = trim($_GET['time'] ?? '');

    $where = ['1=1'];
    $params = [];
    if ($q !== '') { $where[] = 'nome LIKE ?'; $params[] = "%{$q}%"; }
    if ($fAtivo !== '') { $where[] = 'ativo = ?'; $params[] = (int)$fAtivo; }
    if ($fPais !== '') { $where[] = 'pais = ?'; $params[] = strtoupper($fPais); }
    if ($fPremio !== '') { $where[] = 'JSON_CONTAINS(premios, JSON_QUOTE(?))'; $params[] = $fPremio; }
    if ($fEra !== '') { $where[] = 'JSON_CONTAINS(eras, JSON_QUOTE(?))'; $params[] = $fEra; }
    if ($fTime !== '') { $where[] = 'JSON_CONTAINS(times, JSON_QUOTE(?))'; $params[] = $fTime; }

    $sql = "SELECT * FROM hoopgrid_players WHERE " . implode(' AND ', $where) . " ORDER BY nome ASC LIMIT ? OFFSET ?";
    $params[] = $limit; $params[] = $off;
    $stmtL = $pdo->prepare($sql);
    $stmtL->execute($params);
    $players = $stmtL->fetchAll(PDO::FETCH_ASSOC);

    $sqlC = "SELECT COUNT(*) FROM hoopgrid_players WHERE " . implode(' AND ', $where);
    $paramsC = array_slice($params, 0, -2);
    $stmtC = $pdo->prepare($sqlC);
    $stmtC->execute($paramsC);
    $total = (int)$stmtC->fetchColumn();

    echo json_encode(['ok'=>true,'players'=>$players,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/$limit)]);
    exit;
}

// ── SALVAR JOGADOR (POST JSON) ───────────────────────────────────────────────
if ($action === 'save_player' && $_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $body = json_decode(file_get_contents('php://input'), true);
    $id = (int)($body['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit; }
    $pdo->prepare("DELETE FROM hoopgrid_players WHERE id=?")->execute([$id]);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── TOGGLE ATIVO ─────────────────────────────────────────────────────────────
if ($action === 'toggle_active' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id    = (int)($body['id'] ?? 0);
    $ativo = (int)(bool)($body['ativo'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit; }
    $pdo->prepare("UPDATE hoopgrid_players SET ativo=? WHERE id=?")->execute([$ativo,$id]);
    echo json_encode(['ok'=>true]);
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
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Banco de Jogadores NBA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*{box-sizing:border-box}
body{background:#07070a;color:#f0f0f3;font-family:'Segoe UI',sans-serif;font-size:14px}
.panel{background:#101013;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:20px}
.stat{background:#16161a;border-radius:10px;padding:14px;text-align:center}
.stat-v{font-size:1.8rem;font-weight:800}
.stat-l{font-size:11px;color:#868690;margin-top:2px}
#log{background:#000;border-radius:8px;padding:10px;font-family:monospace;font-size:12px;color:#4ade80;max-height:160px;overflow-y:auto;min-height:44px}
.inp{background:#16161a;border:1px solid rgba(255,255,255,.1);border-radius:8px;padding:7px 11px;color:#f0f0f3;font-size:13px;width:100%}
.inp:focus{outline:none;border-color:rgba(249,115,22,.5)}
.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th{padding:8px 10px;color:#868690;font-weight:600;text-align:left;border-bottom:1px solid rgba(255,255,255,.07);white-space:nowrap}
.tbl td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
.tbl tr:hover td{background:rgba(255,255,255,.03)}
.tag{display:inline-block;background:rgba(249,115,22,.15);color:#fb923c;border-radius:4px;padding:1px 6px;font-size:11px;margin:1px}
.tag.blue{background:rgba(59,130,246,.15);color:#60a5fa}
.tag.green{background:rgba(34,197,94,.12);color:#4ade80}
.tag.red{background:rgba(239,68,68,.12);color:#f87171}
.tag.gray{background:rgba(255,255,255,.07);color:#9ca3af}
.btn-ic{background:none;border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:4px 8px;color:#9ca3af;cursor:pointer;font-size:12px;transition:.15s}
.btn-ic:hover{border-color:rgba(249,115,22,.5);color:#fb923c}
.btn-ic.danger:hover{border-color:rgba(239,68,68,.5);color:#f87171}
.btn-ic.success:hover{border-color:rgba(34,197,94,.5);color:#4ade80}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#101013;border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:24px;width:min(700px,96vw);max-height:90vh;overflow-y:auto}
.modal-box h5{margin:0 0 18px;font-weight:700}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px}
.form-row.full{grid-template-columns:1fr}
label{display:block;font-size:11px;color:#868690;margin-bottom:5px;font-weight:600;text-transform:uppercase;letter-spacing:.5px}
.chip-group{display:flex;flex-wrap:wrap;gap:6px;margin-top:4px}
.chip{display:flex;align-items:center;gap:4px;background:#16161a;border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:4px 8px;font-size:12px;cursor:pointer;user-select:none;transition:.15s}
.chip:hover{border-color:rgba(249,115,22,.4)}
.chip.sel{background:rgba(249,115,22,.15);border-color:rgba(249,115,22,.5);color:#fb923c}
.chip.sel.blue{background:rgba(59,130,246,.15);border-color:rgba(59,130,246,.5);color:#60a5fa}
.chip.sel.green{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.4);color:#4ade80}
.pag{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.pag button{background:#16161a;border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:5px 11px;color:#9ca3af;cursor:pointer;font-size:12px}
.pag button:hover{border-color:rgba(249,115,22,.4);color:#fb923c}
.pag button.active{background:rgba(249,115,22,.15);border-color:rgba(249,115,22,.5);color:#fb923c}
.search-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.search-bar .inp{flex:1;min-width:160px}
</style>
</head>
<body class="p-3 p-md-4">

<a href="dashboard.php" class="text-decoration-none text-secondary mb-3 d-inline-block"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
<h4 class="fw-bold mb-1">🏀 Banco de Jogadores NBA</h4>
<p class="text-secondary mb-4" style="font-size:12px">Banco centralizado usado pelo Hoop Grid e outros jogos. Importe via API ou gerencie manualmente.</p>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3"><div class="stat"><div class="stat-v"><?= $totalAll ?></div><div class="stat-l">Total no banco</div></div></div>
  <div class="col-6 col-md-3"><div class="stat"><div class="stat-v text-success"><?= $totalPlayers ?></div><div class="stat-l">Ativos</div></div></div>
  <div class="col-6 col-md-3"><div class="stat"><div class="stat-v text-warning"><?= $comPremios ?></div><div class="stat-l">Com prêmios</div></div></div>
  <div class="col-6 col-md-3"><div class="stat"><div class="stat-v text-danger"><?= $totalPlayers - $comPremios ?></div><div class="stat-l">Sem prêmios</div></div></div>
</div>

<!-- Import -->
<div class="panel mb-4">
  <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h6 class="fw-bold mb-0">Importar via NBA Stats API</h6>
    <button class="btn btn-sm btn-danger fw-bold" id="btnImport"><i class="bi bi-cloud-download me-1"></i>Importar Agora</button>
  </div>
  <p class="text-secondary mb-2" style="font-size:12px">Busca todos os jogadores desde 1980. Existentes não são sobrescritos (prêmios/país preservados).</p>
  <div id="log">Aguardando...</div>
</div>

<!-- Gerenciador -->
<div class="panel">
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h6 class="fw-bold mb-0">Gerenciar Jogadores</h6>
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
    <input class="inp" style="flex:0 0 90px" id="fPais" placeholder="País (USA)" oninput="debSearch()">
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
  <table class="tbl" id="tbl">
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
    <tbody id="tbody"><tr><td colspan="7" style="text-align:center;padding:30px;color:#555">Carregando...</td></tr></tbody>
  </table>
  </div>

  <!-- Paginação -->
  <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
    <span id="pageInfo" style="font-size:12px;color:#868690"></span>
    <div class="pag" id="pagination"></div>
  </div>
</div>

<!-- MODAL EDIÇÃO -->
<div class="modal-overlay" id="modalOverlay" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <h5 id="modalTitle">Editar Jogador</h5>
    <input type="hidden" id="eId">

    <div class="form-row">
      <div>
        <label>Nome *</label>
        <input class="inp" id="eNome" placeholder="LeBron James">
      </div>
      <div>
        <label>País</label>
        <input class="inp" id="ePais" placeholder="USA" maxlength="5">
      </div>
    </div>

    <div class="form-row full" style="margin-bottom:14px">
      <div>
        <label>Times (clique para selecionar)</label>
        <div class="chip-group" id="chipTimes"></div>
      </div>
    </div>

    <div class="form-row full" style="margin-bottom:14px">
      <div>
        <label>Eras</label>
        <div class="chip-group" id="chipEras"></div>
      </div>
    </div>

    <div class="form-row full" style="margin-bottom:14px">
      <div>
        <label>Prêmios</label>
        <div class="chip-group" id="chipPremios"></div>
      </div>
    </div>

    <div class="form-row">
      <div>
        <label>NBA Person ID</label>
        <input class="inp" id="ePid" type="number" placeholder="opcional">
      </div>
      <div>
        <label>Status</label>
        <select class="inp" id="eAtivo">
          <option value="1">Ativo</option>
          <option value="0">Inativo</option>
        </select>
      </div>
    </div>

    <div class="d-flex gap-2 mt-2">
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

// Carregar ao abrir
loadPlayers(1);
</script>
</body>
</html>
