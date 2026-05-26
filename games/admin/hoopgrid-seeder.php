<?php
/**
 * hoopgrid-seeder.php
 * Importa todos os jogadores da história da NBA via stats.nba.com
 * Acesso restrito a admins do games
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

// ── Mapeamento: abreviatura NBA → abreviatura usada no jogo ──────────────────
// A API retorna abreviaturas atuais; times extintos/renomeados ficam sem match.
$TEAM_MAP = [
    'ATL'=>'ATL','BOS'=>'BOS','BKN'=>'BKN','CHA'=>'CHA','CHI'=>'CHI',
    'CLE'=>'CLE','DAL'=>'DAL','DEN'=>'DEN','DET'=>'DET','GSW'=>'GSW',
    'HOU'=>'HOU','IND'=>'IND','LAC'=>'LAC','LAL'=>'LAL','MEM'=>'MEM',
    'MIA'=>'MIA','MIL'=>'MIL','MIN'=>'MIN','NOP'=>'NOP','NYK'=>'NYK',
    'OKC'=>'OKC','ORL'=>'ORL','PHI'=>'PHI','PHX'=>'PHX','POR'=>'POR',
    'SAC'=>'SAC','SAS'=>'SAS','TOR'=>'TOR','UTA'=>'UTA','WAS'=>'WAS',
    // Times históricos (não estão nos critérios mas são válidos no array do jogador)
    'NJN'=>'BKN','SEA'=>'OKC','NOH'=>'NOP','NOK'=>'NOP','VAN'=>'MEM',
    'SDC'=>'LAC','KCK'=>'SAC','NJN'=>'BKN',
];

// ── Função: calcula eras a partir dos anos de carreira ───────────────────────
function calcEras(int $from, int $to): array {
    $eras = [];
    $decades = ['80s'=>[1980,1989],'90s'=>[1990,1999],'00s'=>[2000,2009],'10s'=>[2010,2019],'20s'=>[2020,2030]];
    foreach ($decades as $key => [$s, $e]) {
        if ($from <= $e && $to >= $s) $eras[] = $key;
    }
    return $eras;
}

// ── Fetch NBA Stats API ───────────────────────────────────────────────────────
$action = $_GET['action'] ?? 'status';

if ($action === 'import') {
    set_time_limit(300);
    $season = '2024-25';
    $url = "https://stats.nba.com/stats/commonallplayers?IsOnlyCurrentSeason=0&LeagueID=00&Season={$season}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9',
            'Connection: keep-alive',
            'Host: stats.nba.com',
            'Origin: https://www.nba.com',
            'Referer: https://www.nba.com/',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'x-nba-stats-origin: stats',
            'x-nba-stats-token: true',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $code !== 200) {
        echo json_encode(['ok'=>false,'error'=>"HTTP {$code}: {$err}"]);
        exit;
    }

    $data = json_decode($raw, true);
    $headers = $data['resultSets'][0]['headers'] ?? [];
    $rows    = $data['resultSets'][0]['rowSet']   ?? [];

    if (!$rows) { echo json_encode(['ok'=>false,'error'=>'Nenhum dado retornado']); exit; }

    $idx = array_flip($headers);
    $iNome  = $idx['DISPLAY_FIRST_LAST']   ?? null;
    $iFrom  = $idx['FROM_YEAR']            ?? null;
    $iTo    = $idx['TO_YEAR']              ?? null;
    $iTeam  = $idx['TEAM_ABBREVIATION']    ?? null;
    $iPid   = $idx['PERSON_ID']            ?? null;

    if ($iNome === null || $iFrom === null) {
        echo json_encode(['ok'=>false,'error'=>'Campos não encontrados no retorno']); exit;
    }

    $stmt = $pdo->prepare("INSERT INTO hoopgrid_players (nome, times, pais, premios, eras, nba_person_id)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            eras = IF(eras='[]' OR eras IS NULL, VALUES(eras), eras),
            nba_person_id = COALESCE(nba_person_id, VALUES(nba_person_id))");

    $inserted = 0; $skipped = 0;
    foreach ($rows as $row) {
        $nome = trim($row[$iNome] ?? '');
        if (!$nome) { $skipped++; continue; }

        $from = (int)($row[$iFrom] ?? 0);
        $to   = (int)($row[$iTo]   ?? date('Y'));
        if ($from < 1979) { $skipped++; continue; } // antes dos anos 80 = fora das eras

        $eras = calcEras($from, $to);
        if (empty($eras)) { $skipped++; continue; }

        $teamRaw = strtoupper(trim($row[$iTeam] ?? ''));
        $team    = $TEAM_MAP[$teamRaw] ?? $teamRaw;
        $times   = $team ? [$team] : [];
        $pid     = $iPid !== null ? (int)$row[$iPid] : null;

        try {
            $stmt->execute([$nome, json_encode($times), 'USA', '[]', json_encode($eras), $pid]);
            $inserted++;
        } catch (PDOException $e) { $skipped++; }
    }

    echo json_encode(['ok'=>true,'inserted'=>$inserted,'skipped'=>$skipped,'total'=>count($rows)]);
    exit;
}

// ── Página de status / UI ─────────────────────────────────────────────────────
$totalPlayers = (int)$pdo->query("SELECT COUNT(*) FROM hoopgrid_players WHERE ativo=1")->fetchColumn();
$comPremios   = (int)$pdo->query("SELECT COUNT(*) FROM hoopgrid_players WHERE ativo=1 AND premios != '[]'")->fetchColumn();
$semPais      = (int)$pdo->query("SELECT COUNT(*) FROM hoopgrid_players WHERE ativo=1 AND pais = 'USA' AND nome NOT IN (SELECT nome FROM hoopgrid_players WHERE pais != 'USA')")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hoop Grid — Seeder</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
body{background:#07070a;color:#f0f0f3;font-family:'Poppins',sans-serif}
.panel{background:#101013;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:24px}
.stat{background:#16161a;border-radius:10px;padding:16px;text-align:center}
.stat-v{font-size:2rem;font-weight:800}
.stat-l{font-size:11px;color:#868690;margin-top:4px}
#log{background:#000;border-radius:8px;padding:12px;font-family:monospace;font-size:12px;color:#4ade80;max-height:300px;overflow-y:auto;min-height:60px}
</style>
</head>
<body class="p-4">
<a href="dashboard.php" class="text-decoration-none text-secondary mb-4 d-inline-block"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
<h4 class="fw-800 mb-1">🏀 Hoop Grid — Seeder de Jogadores</h4>
<p class="text-secondary mb-4" style="font-size:13px">Importa todos os jogadores da história NBA via stats.nba.com. Times históricos (SEA, NJN...) são mapeados para equivalentes atuais. Prêmios e país precisam ser enriquecidos manualmente.</p>

<div class="row g-3 mb-4">
  <div class="col-4"><div class="stat"><div class="stat-v"><?= $totalPlayers ?></div><div class="stat-l">Jogadores no banco</div></div></div>
  <div class="col-4"><div class="stat"><div class="stat-v text-warning"><?= $comPremios ?></div><div class="stat-l">Com prêmios mapeados</div></div></div>
  <div class="col-4"><div class="stat"><div class="stat-v text-danger"><?= $totalPlayers - $comPremios ?></div><div class="stat-l">Sem prêmios</div></div></div>
</div>

<div class="panel mb-4">
  <h6 class="fw-700 mb-3">Importar da NBA Stats API</h6>
  <p class="text-secondary mb-3" style="font-size:12px">Busca <strong>todos os jogadores desde 1980</strong>. Já existentes no banco não são sobrescritos. Processo leva ~10s.</p>
  <button class="btn btn-danger fw-700" id="btnImport"><i class="bi bi-cloud-download me-2"></i>Importar Agora</button>
  <div id="log" class="mt-3">Aguardando...</div>
</div>

<div class="panel">
  <h6 class="fw-700 mb-2">Notas</h6>
  <ul class="text-secondary" style="font-size:12px">
    <li>A API retorna apenas o <strong>último time</strong> de cada jogador. Para múltiplos times, edite o campo <code>times</code> diretamente no banco.</li>
    <li>Todos os novos jogadores entram com <code>pais = 'USA'</code>. Corrija no banco para jogadores internacionais.</li>
    <li>Prêmios (MVP, Campeão, AllStar...) não são fornecidos pela API — adicione manualmente no banco ou via painel.</li>
    <li>Jogadores com <code>ativo = 0</code> não aparecem no jogo.</li>
  </ul>
</div>

<script>
document.getElementById('btnImport').addEventListener('click', async () => {
  const log = document.getElementById('log');
  const btn = document.getElementById('btnImport');
  btn.disabled = true;
  log.textContent = 'Conectando à NBA Stats API...\n';
  try {
    const r = await fetch('?action=import');
    const d = await r.json();
    if (d.ok) {
      log.textContent += `✅ Concluído!\n`;
      log.textContent += `Total na API: ${d.total}\n`;
      log.textContent += `Inseridos/atualizados: ${d.inserted}\n`;
      log.textContent += `Ignorados: ${d.skipped}\n`;
      log.textContent += `\nAtualize a página para ver o novo total.`;
    } else {
      log.textContent += `❌ Erro: ${d.error}`;
    }
  } catch(e) {
    log.textContent += `❌ Falha na requisição: ${e.message}`;
  }
  btn.disabled = false;
});
</script>
</body>
</html>
