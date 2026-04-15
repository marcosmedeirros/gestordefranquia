<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');

$adminKey = 'nba2026admin';
if (isset($_GET['admin']) && $_GET['admin'] === $adminKey) {
    $_SESSION['bracket_admin_ok'] = true;
}
$isAdmin = !empty($_SESSION['bracket_admin_ok']);

if (!$isAdmin) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acesso negado</title></head><body style="font-family:Arial,sans-serif;background:#0a0b0f;color:#fff;padding:24px;">';
    echo '<h2>Acesso negado</h2><p>Use o link de admin para acessar esta pagina.</p>';
    echo '<p><a style="color:#7cc3ff;" href="bracketadmin.php?admin=' . urlencode($adminKey) . '">Abrir com chave de admin</a></p>';
    echo '</body></html>';
    exit;
}

// --- DB ---
$host = 'localhost';
$dbname = 'u289267434_gamesfba';
$user = 'u289267434_gamesfba';
$pass = 'Gamesfba@123';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Erro DB: ' . $e->getMessage());
}

$pdo->exec("CREATE TABLE IF NOT EXISTS nba_bracket_apostadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    telefone VARCHAR(30) NOT NULL,
    status_pagamento ENUM('pendente','confirmado','rejeitado') NOT NULL DEFAULT 'pendente',
    picks JSON NULL,
    pontos INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status_pagamento)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS nba_bracket_resultados (
    matchup_id VARCHAR(20) PRIMARY KEY,
    winner VARCHAR(20) NULL,
    games TINYINT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Garante compatibilidade para bases antigas que ainda nao possuem o status "rejeitado"
$pdo->exec("ALTER TABLE nba_bracket_apostadores MODIFY status_pagamento ENUM('pendente','confirmado','rejeitado') NOT NULL DEFAULT 'pendente'");

$winnerMatchups = ['WP_TOP','WP_BOTTOM','WP_FINAL','EP_TOP','EP_BOTTOM','EP_FINAL','W1','W2','W3','W4','E1','E2','E3','E4','WS1','WS2','ES1','ES2','WF','EF','FINAL'];
$seriesMatchups = ['W1','W2','W3','W4','E1','E2','E3','E4','WS1','WS2','ES1','ES2','WF','EF','FINAL'];
$matchupPoints = [
    'WP_TOP' => 1, 'WP_BOTTOM' => 1, 'WP_FINAL' => 1,
    'EP_TOP' => 1, 'EP_BOTTOM' => 1, 'EP_FINAL' => 1,
    'W1' => 2, 'W2' => 2, 'W3' => 2, 'W4' => 2,
    'E1' => 2, 'E2' => 2, 'E3' => 2, 'E4' => 2,
    'WS1' => 4, 'WS2' => 4, 'ES1' => 4, 'ES2' => 4,
    'WF' => 8, 'EF' => 8,
    'FINAL' => 16,
];

$statusFilter = $_GET['status'] ?? 'todos';
if (!in_array($statusFilter, ['todos', 'pendente', 'confirmado', 'rejeitado'], true)) {
    $statusFilter = 'todos';
}

function calculateBracketScore(array $userPicks, array $officialResults, array $winnerMatchups, array $seriesMatchups, array $matchupPoints): int {
    $score = 0;
    foreach ($winnerMatchups as $matchupId) {
        $officialWinner = $officialResults[$matchupId]['winner'] ?? null;
        if (!$officialWinner) {
            continue;
        }

        $userWinner = $userPicks['winner_'.$matchupId] ?? null;
        if ($userWinner !== $officialWinner) {
            continue;
        }

        $score += (int)($matchupPoints[$matchupId] ?? 0);

        if (in_array($matchupId, $seriesMatchups, true)) {
            $officialGames = isset($officialResults[$matchupId]['games']) ? (int)$officialResults[$matchupId]['games'] : 0;
            $userGames = isset($userPicks['games_'.$matchupId]) ? (int)$userPicks['games_'.$matchupId] : 0;
            if ($officialGames >= 4 && $officialGames <= 7 && $userGames === $officialGames) {
                $score += 2;
            }
        }
    }
    return $score;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_results') {
    $posted = $_POST['results'] ?? [];
    $officialResults = [];

    foreach ($winnerMatchups as $matchupId) {
        $winnerKey = 'winner_'.$matchupId;
        $gamesKey = 'games_'.$matchupId;

        $winner = isset($posted[$winnerKey]) ? trim((string)$posted[$winnerKey]) : '';
        $winner = $winner !== '' ? $winner : null;

        $games = null;
        if (in_array($matchupId, $seriesMatchups, true)) {
            $gamesRaw = isset($posted[$gamesKey]) ? (int)$posted[$gamesKey] : 0;
            if ($gamesRaw >= 4 && $gamesRaw <= 7) {
                $games = $gamesRaw;
            }
        }

        $officialResults[$matchupId] = ['winner' => $winner, 'games' => $games];

        $stmtUpsert = $pdo->prepare("INSERT INTO nba_bracket_resultados (matchup_id, winner, games) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE winner = VALUES(winner), games = VALUES(games)");
        $stmtUpsert->execute([$matchupId, $winner, $games]);
    }

    $stmtUsers = $pdo->query("SELECT id, picks FROM nba_bracket_apostadores");
    $apostadoresPicks = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
    $stmtUpdatePts = $pdo->prepare("UPDATE nba_bracket_apostadores SET pontos = ? WHERE id = ?");

    foreach ($apostadoresPicks as $row) {
        $userPicks = json_decode($row['picks'] ?? '{}', true);
        if (!is_array($userPicks)) {
            $userPicks = [];
        }
        $score = calculateBracketScore($userPicks, $officialResults, $winnerMatchups, $seriesMatchups, $matchupPoints);
        $stmtUpdatePts->execute([$score, (int)$row['id']]);
    }

    header('Location: bracketadmin.php?ok_results=1&status=' . urlencode($statusFilter ?? 'todos'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_status_id'], $_POST['admin_status'])) {
    $id = (int)$_POST['admin_status_id'];
    $novoStatus = in_array($_POST['admin_status'], ['confirmado', 'pendente', 'rejeitado'], true) ? $_POST['admin_status'] : 'pendente';

    $stmt = $pdo->prepare('UPDATE nba_bracket_apostadores SET status_pagamento = ? WHERE id = ?');
    $stmt->execute([$novoStatus, $id]);

    header('Location: bracketadmin.php?ok=' . $novoStatus);
    exit;
}

$where = '';
$params = [];
if ($statusFilter !== 'todos') {
    $where = 'WHERE status_pagamento = ?';
    $params[] = $statusFilter;
}

$stmtList = $pdo->prepare("SELECT a.id, a.nome, a.telefone, a.status_pagamento, a.pontos, a.created_at
    FROM nba_bracket_apostadores a
    INNER JOIN (
        SELECT MAX(id) AS id
        FROM nba_bracket_apostadores
        GROUP BY nome, telefone, MD5(COALESCE(CAST(picks AS CHAR(10000)), ''))
    ) d ON d.id = a.id
    $where
    ORDER BY
        CASE WHEN a.status_pagamento='pendente' THEN 0 WHEN a.status_pagamento='confirmado' THEN 1 ELSE 2 END ASC,
        CASE WHEN a.status_pagamento='pendente' THEN a.created_at END DESC,
        CASE WHEN a.status_pagamento='confirmado' THEN a.pontos END DESC,
        a.created_at DESC
");
$stmtList->execute($params);
$apostadores = $stmtList->fetchAll(PDO::FETCH_ASSOC);

$stmtResults = $pdo->query("SELECT matchup_id, winner, games FROM nba_bracket_resultados");
$officialResultsRows = $stmtResults->fetchAll(PDO::FETCH_ASSOC);
$officialResultsMap = [];
foreach ($officialResultsRows as $r) {
    $officialResultsMap[$r['matchup_id']] = [
        'winner' => $r['winner'] !== null ? (string)$r['winner'] : null,
        'games' => $r['games'] !== null ? (int)$r['games'] : null,
    ];
}

$teams = [
    // WEST
    'OKC'  => ['name'=>'Thunder',      'seed'=>1,  'conf'=>'W', 'color'=>'#007AC1', 'abbr'=>'OKC'],
    'LAL'  => ['name'=>'Lakers',       'seed'=>4,  'conf'=>'W', 'color'=>'#552583', 'abbr'=>'LAL'],
    'HOU'  => ['name'=>'Rockets',      'seed'=>5,  'conf'=>'W', 'color'=>'#CE1141', 'abbr'=>'HOU'],
    'DEN'  => ['name'=>'Nuggets',      'seed'=>3,  'conf'=>'W', 'color'=>'#0E2240', 'abbr'=>'DEN'],
    'MIN'  => ['name'=>'Timberwolves', 'seed'=>6,  'conf'=>'W', 'color'=>'#0C2340', 'abbr'=>'MIN'],
    'SAS'  => ['name'=>'Spurs',        'seed'=>2,  'conf'=>'W', 'color'=>'#C4CED4', 'abbr'=>'SAS'],
    'PHX'  => ['name'=>'Suns',         'seed'=>7,  'conf'=>'W', 'color'=>'#1D1160', 'abbr'=>'PHX'],
    'POR'  => ['name'=>'Trail Blazers','seed'=>8,  'conf'=>'W', 'color'=>'#E03A3E', 'abbr'=>'POR'],
    'LAC'  => ['name'=>'Clippers',     'seed'=>9,  'conf'=>'W', 'color'=>'#C8102E', 'abbr'=>'LAC'],
    'GSW'  => ['name'=>'Warriors',     'seed'=>10, 'conf'=>'W', 'color'=>'#1D428A', 'abbr'=>'GSW'],
    'W8'   => ['name'=>'8 Seed',       'seed'=>8,  'conf'=>'W', 'color'=>'#5D6269', 'abbr'=>'W8'],
    'W7'   => ['name'=>'7 Seed',       'seed'=>7,  'conf'=>'W', 'color'=>'#5D6269', 'abbr'=>'W7'],
    // EAST
    'DET'  => ['name'=>'Pistons',      'seed'=>1,  'conf'=>'E', 'color'=>'#C8102E', 'abbr'=>'DET'],
    'CLE'  => ['name'=>'Cavaliers',    'seed'=>4,  'conf'=>'E', 'color'=>'#860038', 'abbr'=>'CLE'],
    'TOR'  => ['name'=>'Raptors',      'seed'=>5,  'conf'=>'E', 'color'=>'#CE1141', 'abbr'=>'TOR'],
    'NYK'  => ['name'=>'Knicks',       'seed'=>3,  'conf'=>'E', 'color'=>'#006BB6', 'abbr'=>'NYK'],
    'ATL'  => ['name'=>'Hawks',        'seed'=>6,  'conf'=>'E', 'color'=>'#E03A3E', 'abbr'=>'ATL'],
    'BOS'  => ['name'=>'Celtics',      'seed'=>2,  'conf'=>'E', 'color'=>'#007A33', 'abbr'=>'BOS'],
    'PHI'  => ['name'=>'76ers',        'seed'=>7,  'conf'=>'E', 'color'=>'#006BB6', 'abbr'=>'PHI'],
    'ORL'  => ['name'=>'Magic',        'seed'=>8,  'conf'=>'E', 'color'=>'#0077C0', 'abbr'=>'ORL'],
    'CHA'  => ['name'=>'Hornets',      'seed'=>9,  'conf'=>'E', 'color'=>'#1D1160', 'abbr'=>'CHA'],
    'MIA'  => ['name'=>'Heat',         'seed'=>10, 'conf'=>'E', 'color'=>'#98002E', 'abbr'=>'MIA'],
    'E8'   => ['name'=>'8 Seed',       'seed'=>8,  'conf'=>'E', 'color'=>'#5D6269', 'abbr'=>'E8'],
    'E7'   => ['name'=>'7 Seed',       'seed'=>7,  'conf'=>'E', 'color'=>'#5D6269', 'abbr'=>'E7'],
];

function matchupCardAdmin($id, $t1key, $t2key, $teams, $singleGame = false) {
    $t1 = $teams[$t1key] ?? ['name'=>$t1key,'seed'=>'?','color'=>'#555','abbr'=>$t1key];
    $t2 = $teams[$t2key] ?? ['name'=>$t2key,'seed'=>'?','color'=>'#777','abbr'=>$t2key];
    $gameCounts = [4,5,6,7];
    echo '<div class="matchup" id="matchup-'.$id.'">';
    echo '<div class="team-row" data-team="'.$t1key.'" onclick="selectResultTeam(\''.$id.'\',\''.$t1key.'\',this)">';
    echo '<span class="seed-badge">'.$t1['seed'].'</span>';
    echo '<div class="team-dot" style="background:'.$t1['color'].';">'.$t1['abbr'].'</div>';
    echo '<span class="team-name">'.$t1['name'].'</span>';
    echo '<i class="bi bi-check-circle-fill check-icon"></i>';
    echo '</div>';
    echo '<div class="team-row" data-team="'.$t2key.'" onclick="selectResultTeam(\''.$id.'\',\''.$t2key.'\',this)">';
    echo '<span class="seed-badge">'.$t2['seed'].'</span>';
    echo '<div class="team-dot" style="background:'.$t2['color'].';">'.$t2['abbr'].'</div>';
    echo '<span class="team-name">'.$t2['name'].'</span>';
    echo '<i class="bi bi-check-circle-fill check-icon"></i>';
    echo '</div>';
    if (!$singleGame) {
        echo '<div class="games-selector"><span>Jogos:</span>';
        foreach ($gameCounts as $g) {
            echo '<button type="button" class="games-btn" data-matchup="'.$id.'" data-games="'.$g.'" onclick="selectResultGames(\''.$id.'\','.$g.',this)">'.$g.'</button>';
        }
        echo '</div>';
    }
    echo '<input type="hidden" name="results[winner_'.$id.']" id="result-winner-'.$id.'" value="">';
    if (!$singleGame) {
        echo '<input type="hidden" name="results[games_'.$id.']" id="result-games-'.$id.'" value="">';
    }
    echo '</div>';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bracket Admin - NBA 2026</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root {
    --bg: #0a0b0f;
    --panel: #13141a;
    --panel2: #1a1c25;
    --border: #252734;
    --text: #e9eaee;
    --muted: #8a90a8;
}
body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Segoe UI', system-ui, sans-serif;
}
.container-admin {
    max-width: 1100px;
    margin: 0 auto;
    padding: 2rem 1rem 3rem;
}
.admin-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 1rem;
}
.admin-card.pending {
    border-color: #8a6b2a;
    background: linear-gradient(180deg, rgba(255, 152, 0, .12), var(--panel));
    box-shadow: 0 0 0 0 rgba(255, 179, 71, .28);
    animation: pendingPulse 1.9s ease-in-out infinite;
}
.admin-card.rejected {
    border-color: #7d2a31;
    background: linear-gradient(180deg, rgba(180, 45, 56, .14), var(--panel));
}
@keyframes pendingPulse {
    0% { box-shadow: 0 0 0 0 rgba(255, 179, 71, .28); }
    70% { box-shadow: 0 0 0 9px rgba(255, 179, 71, 0); }
    100% { box-shadow: 0 0 0 0 rgba(255, 179, 71, 0); }
}
.status-pill {
    font-size: .72rem;
    font-weight: 800;
    padding: .2rem .55rem;
    border-radius: 999px;
    text-transform: uppercase;
}
.status-pill.pending { background: rgba(255, 152, 0, .16); color: #ffca6a; }
.status-pill.confirmed { background: rgba(76, 175, 80, .16); color: #87df8d; }
.status-pill.rejected { background: rgba(180, 45, 56, .2); color: #ff9ea6; }
.admin-btn {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.admin-btn.approve { background: #2f9f49; }
.admin-btn.reject { background: #7d2a31; }
.meta {
    font-size: .78rem;
    color: var(--muted);
}

.filter-bar {
    display: flex;
    gap: .5rem;
    flex-wrap: wrap;
    margin-bottom: .9rem;
}
.filter-link {
    background: var(--panel2);
    border: 1px solid var(--border);
    color: var(--text);
    text-decoration: none;
    border-radius: 999px;
    padding: .35rem .75rem;
    font-size: .8rem;
    font-weight: 700;
}
.filter-link.active {
    border-color: #4a4f67;
    background: #272b38;
}

.results-panel {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 1rem;
}
.results-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: .8rem;
}
.results-progress {
    font-size: .8rem;
    color: var(--muted);
}
.bracket-wrap {
    overflow-x: auto;
    padding-bottom: .5rem;
}
.bracket-grid {
    display: grid;
    grid-template-columns: repeat(9, minmax(180px, 1fr));
    min-width: 1560px;
    gap: 10px;
}
.bracket-col,
.finals-col {
    background: rgba(255,255,255,.02);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 8px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.bracket-col-label {
    font-size: .7rem;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: var(--muted);
    text-align: center;
    border-bottom: 1px solid var(--border);
    padding-bottom: 6px;
}
.matchup {
    background: var(--panel2);
    border: 1px solid var(--border);
    border-radius: 9px;
    overflow: hidden;
}
.matchup.locked { opacity: .55; pointer-events: none; }
.team-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 9px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
}
.team-row:last-child { border-bottom: none; }
.team-row.selected {
    background: linear-gradient(90deg, rgba(47,159,73,.22), transparent);
    border-left: 3px solid #2f9f49;
}
.seed-badge {
    min-width: 16px;
    text-align: center;
    font-size: .66rem;
    color: var(--muted);
    font-weight: 800;
}
.team-dot {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    color: #fff;
    font-size: .62rem;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.team-name { flex: 1; font-size: .78rem; }
.check-icon { color: #2f9f49; opacity: 0; }
.team-row.selected .check-icon { opacity: 1; }
.games-selector {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    border-top: 1px solid var(--border);
    padding: 6px 8px;
}
.games-selector span { font-size: .68rem; color: var(--muted); }
.games-btn {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    border: 1px solid var(--border);
    background: #0f1118;
    color: var(--text);
    font-size: .72rem;
    line-height: 1;
}
.games-btn.active {
    background: #2f9f49;
    border-color: #2f9f49;
}
.finals-col .trophy { font-size: 2rem; text-align: center; }
.finals-col .finals-title {
    text-align: center;
    color: #ffd86b;
    text-transform: uppercase;
    font-size: .72rem;
    letter-spacing: 1px;
    font-weight: 800;
}
.champion-box {
    margin-top: 8px;
    border: 1px solid #8a6b2a;
    border-radius: 9px;
    padding: 8px;
    text-align: center;
    background: rgba(255,216,107,.08);
}
.champion-box .label { color: #ffd86b; font-size: .66rem; text-transform: uppercase; font-weight: 700; }
.champion-box .team { font-size: .84rem; font-weight: 700; }
</style>
</head>
<body>
<div class="container-admin">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h3 class="m-0"><i class="bi bi-shield-check"></i> Bracket Admin - Todos os Palpites</h3>
        <a class="btn btn-outline-light btn-sm" href="bracketnba.php">Abrir pagina publica</a>
    </div>

    <?php if (isset($_GET['ok']) && $_GET['ok'] === 'confirmado'): ?>
        <div class="alert alert-success py-2">Pagamento confirmado.</div>
    <?php endif; ?>
    <?php if (isset($_GET['ok']) && $_GET['ok'] === 'pendente'): ?>
        <div class="alert alert-warning py-2">Pagamento marcado como pendente.</div>
    <?php endif; ?>
    <?php if (isset($_GET['ok']) && $_GET['ok'] === 'rejeitado'): ?>
        <div class="alert alert-danger py-2">Pagamento marcado como rejeitado.</div>
    <?php endif; ?>
    <?php if (isset($_GET['ok_results']) && $_GET['ok_results'] === '1'): ?>
        <div class="alert alert-success py-2">Resultado oficial salvo e pontuacoes atualizadas.</div>
    <?php endif; ?>

    <div class="results-panel">
        <form method="POST" id="resultsForm" action="bracketadmin.php?status=<?= urlencode($statusFilter) ?>">
            <input type="hidden" name="action" value="save_results">

            <div class="results-header">
                <div>
                    <h5 class="mb-1"><i class="bi bi-diagram-3-fill me-2"></i>Bracket Oficial (Resultado Real)</h5>
                    <div class="results-progress" id="resultsProgress">Preenchido: 0 / 21</div>
                </div>
                <button type="submit" class="btn btn-warning btn-sm fw-bold" id="saveResultsBtn">
                    <i class="bi bi-check2-square me-1"></i>Salvar Resultado e Recalcular
                </button>
            </div>

            <div class="bracket-wrap">
                <div class="bracket-grid">
                    <div class="bracket-col">
                        <div class="bracket-col-label">West<br>Play-In</div>
                        <?php matchupCardAdmin('WP_TOP','LAC','GSW',$teams,true); ?>
                        <?php matchupCardAdmin('WP_BOTTOM','PHX','POR',$teams,true); ?>
                        <?php matchupCardAdmin('WP_FINAL','WP_TOP_winner','WP_BOTTOM_loser',$teams,true); ?>
                    </div>
                    <div class="bracket-col">
                        <div class="bracket-col-label">West<br>1a Rodada</div>
                        <?php matchupCardAdmin('W1','OKC','W8',$teams,false); ?>
                        <?php matchupCardAdmin('W2','LAL','HOU',$teams,false); ?>
                        <?php matchupCardAdmin('W3','DEN','MIN',$teams,false); ?>
                        <?php matchupCardAdmin('W4','SAS','W7',$teams,false); ?>
                    </div>
                    <div class="bracket-col">
                        <div class="bracket-col-label">West<br>Semis</div>
                        <?php matchupCardAdmin('WS1','W1_winner','W2_winner',$teams,false); ?>
                        <?php matchupCardAdmin('WS2','W3_winner','W4_winner',$teams,false); ?>
                    </div>
                    <div class="bracket-col">
                        <div class="bracket-col-label">West<br>Finals</div>
                        <?php matchupCardAdmin('WF','WS1_winner','WS2_winner',$teams,false); ?>
                    </div>
                    <div class="finals-col">
                        <div class="trophy">🏆</div>
                        <div class="finals-title">NBA Finals</div>
                        <?php matchupCardAdmin('FINAL','WF_winner','EF_winner',$teams,false); ?>
                        <div class="champion-box" id="adminChampionBox" style="opacity:.45;">
                            <div class="label">Campeao</div>
                            <div class="team" id="adminChampionName">???</div>
                        </div>
                    </div>
                    <div class="bracket-col">
                        <div class="bracket-col-label">East<br>Finals</div>
                        <?php matchupCardAdmin('EF','ES1_winner','ES2_winner',$teams,false); ?>
                    </div>
                    <div class="bracket-col">
                        <div class="bracket-col-label">East<br>Semis</div>
                        <?php matchupCardAdmin('ES1','E1_winner','E2_winner',$teams,false); ?>
                        <?php matchupCardAdmin('ES2','E3_winner','E4_winner',$teams,false); ?>
                    </div>
                    <div class="bracket-col">
                        <div class="bracket-col-label">East<br>1a Rodada</div>
                        <?php matchupCardAdmin('E1','DET','E8',$teams,false); ?>
                        <?php matchupCardAdmin('E2','CLE','TOR',$teams,false); ?>
                        <?php matchupCardAdmin('E3','NYK','ATL',$teams,false); ?>
                        <?php matchupCardAdmin('E4','BOS','E7',$teams,false); ?>
                    </div>
                    <div class="bracket-col">
                        <div class="bracket-col-label">East<br>Play-In</div>
                        <?php matchupCardAdmin('EP_TOP','CHA','MIA',$teams,true); ?>
                        <?php matchupCardAdmin('EP_BOTTOM','PHI','ORL',$teams,true); ?>
                        <?php matchupCardAdmin('EP_FINAL','EP_TOP_winner','EP_BOTTOM_loser',$teams,true); ?>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="filter-bar">
        <a class="filter-link <?= $statusFilter === 'todos' ? 'active' : '' ?>" href="bracketadmin.php?status=todos">Todos</a>
        <a class="filter-link <?= $statusFilter === 'pendente' ? 'active' : '' ?>" href="bracketadmin.php?status=pendente">Pendentes</a>
        <a class="filter-link <?= $statusFilter === 'confirmado' ? 'active' : '' ?>" href="bracketadmin.php?status=confirmado">Confirmados</a>
        <a class="filter-link <?= $statusFilter === 'rejeitado' ? 'active' : '' ?>" href="bracketadmin.php?status=rejeitado">Rejeitados</a>
    </div>

    <?php if (empty($apostadores)): ?>
        <div class="admin-card">Nenhum palpite encontrado.</div>
    <?php else: ?>
        <div class="d-grid gap-2">
            <?php foreach ($apostadores as $a):
                $isConfirmado = $a['status_pagamento'] === 'confirmado';
                $isPendente = $a['status_pagamento'] === 'pendente';
                $isRejeitado = $a['status_pagamento'] === 'rejeitado';
            ?>
            <div class="admin-card <?= $isPendente ? 'pending' : '' ?> <?= $isRejeitado ? 'rejected' : '' ?>">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <div>
                        <div class="fw-bold"><?= htmlspecialchars($a['nome']) ?></div>
                        <div class="meta"><?= htmlspecialchars($a['telefone']) ?> | #<?= (int)$a['id'] ?> | <?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="status-pill <?= $isConfirmado ? 'confirmed' : ($isRejeitado ? 'rejected' : 'pending') ?>"><?= $isConfirmado ? 'confirmado' : ($isRejeitado ? 'rejeitado' : 'pendente') ?></span>
                        <span class="fw-bold" style="min-width:72px;text-align:right;"><?= (int)$a['pontos'] ?> pts</span>
                        <form method="POST" action="bracketadmin.php" class="m-0">
                            <input type="hidden" name="admin_status_id" value="<?= (int)$a['id'] ?>">
                            <input type="hidden" name="admin_status" value="confirmado">
                            <button type="submit" class="admin-btn approve" title="Confirmar pagamento"><i class="bi bi-check-lg"></i></button>
                        </form>
                        <form method="POST" action="bracketadmin.php" class="m-0">
                            <input type="hidden" name="admin_status_id" value="<?= (int)$a['id'] ?>">
                            <input type="hidden" name="admin_status" value="rejeitado">
                            <button type="submit" class="admin-btn reject" title="Marcar rejeitado"><i class="bi bi-x-lg"></i></button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script>
const teams = <?= json_encode($teams, JSON_UNESCAPED_UNICODE) ?>;
const officialResults = <?= json_encode($officialResultsMap, JSON_UNESCAPED_UNICODE) ?>;

const playInMatchups = ['WP_TOP','WP_BOTTOM','WP_FINAL','EP_TOP','EP_BOTTOM','EP_FINAL'];
const seriesMatchups = ['W1','W2','W3','W4','E1','E2','E3','E4','WS1','WS2','ES1','ES2','WF','EF','FINAL'];
const totalMatchups = playInMatchups.length + seriesMatchups.length;

const baseMatchupTeams = {
    'WP_TOP': ['LAC','GSW'],
    'WP_BOTTOM': ['PHX','POR'],
    'WP_FINAL': [null,null],
    'W1': ['OKC',null],
    'W2': ['LAL','HOU'],
    'W3': ['DEN','MIN'],
    'W4': ['SAS',null],
    'WS1': [null,null],
    'WS2': [null,null],
    'WF': [null,null],
    'EP_TOP': ['CHA','MIA'],
    'EP_BOTTOM': ['PHI','ORL'],
    'EP_FINAL': [null,null],
    'E1': ['DET',null],
    'E2': ['CLE','TOR'],
    'E3': ['NYK','ATL'],
    'E4': ['BOS',null],
    'ES1': [null,null],
    'ES2': [null,null],
    'EF': [null,null],
    'FINAL': [null,null],
};

const picks = {};
let matchupTeams = JSON.parse(JSON.stringify(baseMatchupTeams));

function clearOfficialPick(matchupId) {
    if (picks[matchupId]) delete picks[matchupId];
    const winnerInput = document.getElementById('result-winner-' + matchupId);
    if (winnerInput) winnerInput.value = '';
    const gamesInput = document.getElementById('result-games-' + matchupId);
    if (gamesInput) gamesInput.value = '';
    document.querySelectorAll('[data-matchup="' + matchupId + '"]').forEach(b => b.classList.remove('active'));
}

function getLoser(matchupId, winnerKey) {
    const arr = matchupTeams[matchupId] || [];
    if (!winnerKey || arr.length < 2) return null;
    if (arr[0] === winnerKey) return arr[1] || null;
    if (arr[1] === winnerKey) return arr[0] || null;
    return null;
}

function getValidWinner(matchupId) {
    const winner = picks[matchupId]?.winner || null;
    if (!winner) return null;
    const arr = matchupTeams[matchupId] || [];
    if (!arr.includes(winner)) {
        clearOfficialPick(matchupId);
        return null;
    }
    return winner;
}

function applySeriesWinnerFeed(sourceId, targetId, slotIndex) {
    const winner = getValidWinner(sourceId);
    matchupTeams[targetId][slotIndex] = winner;
}

function selectResultTeam(matchupId, teamKey, rowEl) {
    const matchup = document.getElementById('matchup-' + matchupId);
    if (!matchup || matchup.classList.contains('locked')) return;
    let validTeam = false;
    matchup.querySelectorAll('.team-row').forEach(r => {
        if (r.dataset.team === teamKey) validTeam = true;
    });
    if (!validTeam) return;

    picks[matchupId] = picks[matchupId] || {};
    picks[matchupId].winner = teamKey;
    const input = document.getElementById('result-winner-' + matchupId);
    if (input) input.value = teamKey;

    recomputeOfficialBracket();
    updateResultsProgress();
}

function selectResultGames(matchupId, games) {
    picks[matchupId] = picks[matchupId] || {};
    picks[matchupId].games = games;
    const input = document.getElementById('result-games-' + matchupId);
    if (input) input.value = games;
    document.querySelectorAll('[data-matchup="' + matchupId + '"]').forEach(b => {
        b.classList.toggle('active', parseInt(b.dataset.games, 10) === games);
    });
    updateResultsProgress();
}

function renderMatchup(matchupId) {
    const matchup = document.getElementById('matchup-' + matchupId);
    if (!matchup) return;
    const rows = matchup.querySelectorAll('.team-row');
    const [t1, t2] = matchupTeams[matchupId];

    function updateRow(row, teamKey) {
        if (!teamKey) {
            row.dataset.team = '';
            row.querySelector('.team-dot').style.background = '#333';
            row.querySelector('.team-dot').textContent = '?';
            row.querySelector('.team-name').textContent = '?';
            row.querySelector('.seed-badge').textContent = '?';
            row.setAttribute('onclick', '');
        } else {
            const t = teams[teamKey] || {name: teamKey, seed: '?', color: '#555', abbr: teamKey};
            row.dataset.team = teamKey;
            row.querySelector('.team-dot').style.background = t.color;
            row.querySelector('.team-dot').textContent = t.abbr;
            row.querySelector('.team-name').textContent = t.name;
            row.querySelector('.seed-badge').textContent = t.seed;
            row.setAttribute('onclick', `selectResultTeam('${matchupId}','${teamKey}',this)`);
        }
    }

    updateRow(rows[0], t1);
    updateRow(rows[1], t2);

    rows.forEach(r => r.classList.remove('selected'));
    const winner = picks[matchupId]?.winner;
    if (winner) {
        rows.forEach(r => { if (r.dataset.team === winner) r.classList.add('selected'); });
    }

    matchup.classList.toggle('locked', !(t1 && t2));
}

function updateChampion(teamKey) {
    const box = document.getElementById('adminChampionBox');
    const nameEl = document.getElementById('adminChampionName');
    if (!box || !nameEl) return;
    if (!teamKey) {
        box.style.opacity = '.45';
        nameEl.textContent = '???';
        return;
    }
    box.style.opacity = '1';
    nameEl.textContent = (teams[teamKey]?.name || teamKey);
}

function updateResultsProgress() {
    let done = 0;
    playInMatchups.forEach(id => { if (picks[id]?.winner) done++; });
    seriesMatchups.forEach(id => {
        const p = picks[id];
        const g = parseInt(p?.games, 10);
        if (p?.winner && g >= 4 && g <= 7) done++;
    });
    const el = document.getElementById('resultsProgress');
    if (el) el.textContent = `Preenchido: ${done} / ${totalMatchups}`;
}

function recomputeOfficialBracket() {
    matchupTeams = JSON.parse(JSON.stringify(baseMatchupTeams));

    const wpTopWinner = getValidWinner('WP_TOP');
    const wpBottomWinner = getValidWinner('WP_BOTTOM');
    const wpBottomLoser = getLoser('WP_BOTTOM', wpBottomWinner);
    matchupTeams['WP_FINAL'] = [wpTopWinner, wpBottomLoser];
    matchupTeams['W4'][1] = wpBottomWinner;
    matchupTeams['W1'][1] = getValidWinner('WP_FINAL');

    const epTopWinner = getValidWinner('EP_TOP');
    const epBottomWinner = getValidWinner('EP_BOTTOM');
    const epBottomLoser = getLoser('EP_BOTTOM', epBottomWinner);
    matchupTeams['EP_FINAL'] = [epTopWinner, epBottomLoser];
    matchupTeams['E4'][1] = epBottomWinner;
    matchupTeams['E1'][1] = getValidWinner('EP_FINAL');

    applySeriesWinnerFeed('W1', 'WS1', 0);
    applySeriesWinnerFeed('W2', 'WS1', 1);
    applySeriesWinnerFeed('W3', 'WS2', 0);
    applySeriesWinnerFeed('W4', 'WS2', 1);
    applySeriesWinnerFeed('WS1', 'WF', 0);
    applySeriesWinnerFeed('WS2', 'WF', 1);
    applySeriesWinnerFeed('E1', 'ES1', 0);
    applySeriesWinnerFeed('E2', 'ES1', 1);
    applySeriesWinnerFeed('E3', 'ES2', 0);
    applySeriesWinnerFeed('E4', 'ES2', 1);
    applySeriesWinnerFeed('ES1', 'EF', 0);
    applySeriesWinnerFeed('ES2', 'EF', 1);
    applySeriesWinnerFeed('WF', 'FINAL', 0);
    applySeriesWinnerFeed('EF', 'FINAL', 1);

    Object.keys(baseMatchupTeams).forEach(renderMatchup);
    updateChampion(getValidWinner('FINAL'));
}

function hydrateOfficialResults() {
    Object.keys(officialResults || {}).forEach(matchupId => {
        const data = officialResults[matchupId] || {};
        if (!data.winner && !data.games) return;
        picks[matchupId] = {};
        if (data.winner) {
            picks[matchupId].winner = data.winner;
            const w = document.getElementById('result-winner-' + matchupId);
            if (w) w.value = data.winner;
        }
        if (data.games) {
            picks[matchupId].games = parseInt(data.games, 10);
            const g = document.getElementById('result-games-' + matchupId);
            if (g) g.value = data.games;
        }
    });
}

hydrateOfficialResults();
recomputeOfficialBracket();
updateResultsProgress();

const resultsForm = document.getElementById('resultsForm');
if (resultsForm) {
    resultsForm.addEventListener('submit', () => {
        const btn = document.getElementById('saveResultsBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Salvando...';
        }
    });
}
</script>
</body>
</html>
