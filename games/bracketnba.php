<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');

// --- DB ---
$host = 'localhost'; $dbname = 'u289267434_gamesfba';
$user = 'u289267434_gamesfba'; $pass = 'Gamesfba@123';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) { die("Erro DB: " . $e->getMessage()); }

// --- SETUP TABLES ---
$pdo->exec("CREATE TABLE IF NOT EXISTS nba_bracket_apostadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    telefone VARCHAR(30) NOT NULL,
    status_pagamento ENUM('pendente','confirmado') NOT NULL DEFAULT 'pendente',
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

// --- TIMES ---
$teams = [
    // WEST
    'OKC'  => ['name'=>'Thunder',      'seed'=>1,  'conf'=>'W', 'color'=>'#007AC1', 'abbr'=>'OKC'],
    'LAL'  => ['name'=>'Lakers',       'seed'=>4,  'conf'=>'W', 'color'=>'#552583', 'abbr'=>'LAL'],
    'HOU'  => ['name'=>'Rockets',      'seed'=>5,  'conf'=>'W', 'color'=>'#CE1141', 'abbr'=>'HOU'],
    'DEN'  => ['name'=>'Nuggets',      'seed'=>3,  'conf'=>'W', 'color'=>'#0E2240', 'abbr'=>'DEN'],
    'MIN'  => ['name'=>'Timberwolves', 'seed'=>6,  'conf'=>'W', 'color'=>'#0C2340', 'abbr'=>'MIN'],
    'SAS'  => ['name'=>'Spurs',        'seed'=>2,  'conf'=>'W', 'color'=>'#C4CED4', 'abbr'=>'SAS'],
    // Play-in West
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
    // Play-in East
    'PHI'  => ['name'=>'76ers',        'seed'=>7,  'conf'=>'E', 'color'=>'#006BB6', 'abbr'=>'PHI'],
    'ORL'  => ['name'=>'Magic',        'seed'=>8,  'conf'=>'E', 'color'=>'#0077C0', 'abbr'=>'ORL'],
    'CHA'  => ['name'=>'Hornets',      'seed'=>9,  'conf'=>'E', 'color'=>'#1D1160', 'abbr'=>'CHA'],
    'MIA'  => ['name'=>'Heat',         'seed'=>10, 'conf'=>'E', 'color'=>'#98002E', 'abbr'=>'MIA'],
    'E8'   => ['name'=>'8 Seed',       'seed'=>8,  'conf'=>'E', 'color'=>'#5D6269', 'abbr'=>'E8'],
    'E7'   => ['name'=>'7 Seed',       'seed'=>7,  'conf'=>'E', 'color'=>'#5D6269', 'abbr'=>'E7'],
];

// Estrutura dos confrontos: [higher_seed, lower_seed]
$firstRound = [
    'W1' => ['OKC','W8'], 'W2' => ['LAL','HOU'], 'W3' => ['DEN','MIN'], 'W4' => ['SAS','W7'],
    'E1' => ['DET','E8'], 'E2' => ['CLE','TOR'], 'E3' => ['NYK','ATL'], 'E4' => ['BOS','E7'],
];

$winnerMatchups = ['WP_TOP','WP_BOTTOM','WP_FINAL','EP_TOP','EP_BOTTOM','EP_FINAL','W1','W2','W3','W4','E1','E2','E3','E4','WS1','WS2','ES1','ES2','WF','EF','FINAL'];

// --- RESULTADOS OFICIAIS ---
$stmtResults = $pdo->query("SELECT matchup_id, winner, games FROM nba_bracket_resultados");
$officialResultsRows = $stmtResults->fetchAll(PDO::FETCH_ASSOC);
$officialResultsMap = [];
foreach ($officialResultsRows as $row) {
    $officialResultsMap[$row['matchup_id']] = [
        'winner' => $row['winner'] !== null ? (string)$row['winner'] : null,
        'games' => $row['games'] !== null ? (int)$row['games'] : null,
    ];
}

// --- POPULARIDADE DOS PALPITES (por confronto/time) ---
$stmtPicksPopularity = $pdo->query("SELECT a.picks
    FROM nba_bracket_apostadores a
    INNER JOIN (
        SELECT MAX(id) AS id
        FROM nba_bracket_apostadores
        WHERE status_pagamento='confirmado'
        GROUP BY nome, telefone, MD5(COALESCE(CAST(picks AS CHAR(10000)), ''))
    ) d ON d.id = a.id
    WHERE a.status_pagamento='confirmado'
");
$rowsPopularity = $stmtPicksPopularity->fetchAll(PDO::FETCH_ASSOC);

$matchupPopularityCounts = [];
foreach ($winnerMatchups as $m) {
    $matchupPopularityCounts[$m] = [];
}

foreach ($rowsPopularity as $row) {
    $pp = json_decode($row['picks'] ?? '{}', true);
    if (!is_array($pp)) {
        continue;
    }
    foreach ($winnerMatchups as $m) {
        $wk = 'winner_'.$m;
        $teamCode = $pp[$wk] ?? null;
        if (!is_string($teamCode) || $teamCode === '') {
            continue;
        }
        if (!isset($matchupPopularityCounts[$m][$teamCode])) {
            $matchupPopularityCounts[$m][$teamCode] = 0;
        }
        $matchupPopularityCounts[$m][$teamCode]++;
    }
}

// --- CARREGAR RANKING ---
$ranking = $pdo->query("SELECT a.nome, a.pontos, a.status_pagamento, a.created_at, a.picks
    FROM nba_bracket_apostadores a
    INNER JOIN (
        SELECT MAX(id) AS id
        FROM nba_bracket_apostadores
        WHERE status_pagamento='confirmado'
        GROUP BY nome, telefone, MD5(COALESCE(CAST(picks AS CHAR(10000)), ''))
    ) d ON d.id = a.id
    WHERE a.status_pagamento='confirmado'
    ORDER BY a.pontos DESC, a.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);

foreach ($ranking as &$rk) {
    $rowPicks = json_decode($rk['picks'] ?? '{}', true);
    $champCode = (is_array($rowPicks) && !empty($rowPicks['winner_FINAL'])) ? (string)$rowPicks['winner_FINAL'] : '';
    $rk['champion_name'] = $champCode !== '' ? ($teams[$champCode]['name'] ?? $champCode) : '';
}
unset($rk);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NBA Playoffs 2025-26 — Bracket Challenge</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root {
    --bg: #0a0b0f;
    --panel: #13141a;
    --panel2: #1a1c25;
    --border: #252734;
    --accent: #c8102e;
    --gold: #FFD700;
    --silver: #C0C0C0;
    --bronze: #CD7F32;
    --text: #e9eaee;
    --muted: #8a90a8;
}
* { box-sizing: border-box; }
body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Segoe UI', system-ui, sans-serif;
    min-height: 100vh;
}
.nba-header {
    background: linear-gradient(135deg, #c8102e 0%, #1d428a 100%);
    padding: 2rem 1rem 3rem;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.nba-header::before {
    content: '';
    position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Ccircle cx='30' cy='30' r='20'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    pointer-events: none;
}
.nba-header h1 { font-size: 2rem; font-weight: 900; letter-spacing: 2px; text-shadow: 0 2px 8px rgba(0,0,0,.5); }
.nba-header p { opacity: .85; margin: 0; }

/* BRACKET */
.bracket-wrap {
    overflow-x: auto;
    padding: 1.5rem 1rem;
    scrollbar-width: thin;
    scrollbar-color: #3a3f52 var(--panel);
    -webkit-overflow-scrolling: touch;
}
.bracket-wrap::-webkit-scrollbar {
    height: 10px;
}
.bracket-wrap::-webkit-scrollbar-track {
    background: var(--panel);
    border-radius: 999px;
}
.bracket-wrap::-webkit-scrollbar-thumb {
    background: linear-gradient(90deg, #3a3f52, #59617d);
    border-radius: 999px;
    border: 2px solid var(--panel);
}
.bracket-wrap::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(90deg, #4a5170, #6a7496);
}
.bracket-grid {
    display: grid;
    grid-template-columns: 0.9fr 1fr 1fr 1fr 140px 1fr 1fr 1fr 0.9fr;
    gap: 0;
    min-width: 1240px;
}
.bracket-col {
    display: flex;
    flex-direction: column;
    justify-content: space-around;
    padding: 0 6px;
    gap: 8px;
}
.mobile-bracket-tip {
    display: none;
}
.mobile-phase-nav {
    display: none;
}
.bracket-col-label {
    text-align: center;
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: 1px;
    color: var(--muted);
    text-transform: uppercase;
    padding: 6px 0 10px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 6px;
}

.matchup {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
    position: relative;
}
.matchup.locked { opacity: .6; pointer-events: none; }

.team-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 10px;
    cursor: default;
    transition: background .15s;
    border-bottom: 1px solid var(--border);
    position: relative;
}
.team-row:last-child { border-bottom: none; }
.team-row:hover { background: transparent; }
.team-row.selected {
    background: linear-gradient(90deg, rgba(200,16,46,.18), transparent);
    border-left: 3px solid var(--accent);
}
.team-row.selected .team-name { color: #fff; font-weight: 700; }
.team-row.winner-result {
    background: linear-gradient(90deg, rgba(47,159,73,.24), transparent);
    border-left: 3px solid #2f9f49;
}
.team-row.loser-result {
    background: rgba(255,255,255,.03);
    color: #8d94ab;
    filter: grayscale(1);
}
.team-row.loser-result .team-name,
.team-row.loser-result .seed-badge {
    color: #8d94ab;
}
.seed-badge {
    font-size: .65rem;
    font-weight: 800;
    color: var(--muted);
    min-width: 16px;
    text-align: center;
}
.team-dot {
    width: 28px; height: 28px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .65rem; font-weight: 800; color: #fff;
    flex-shrink: 0;
}
.team-name { font-size: .8rem; flex: 1; }
.check-icon { color: var(--accent); font-size: .9rem; opacity: 0; }
.team-row.selected .check-icon { opacity: 1; }

.games-selector {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 5px 8px;
    background: var(--panel2);
    border-top: 1px solid var(--border);
}
.games-selector span { font-size: .7rem; color: var(--muted); margin-right: 4px; }
.games-btn {
    width: 26px; height: 26px;
    border-radius: 50%;
    border: 1px solid var(--border);
    background: var(--panel);
    color: var(--muted);
    font-size: .75rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .15s;
    display: flex; align-items: center; justify-content: center;
}
.games-btn:hover { border-color: var(--accent); color: #fff; }
.games-btn.active { background: var(--accent); border-color: var(--accent); color: #fff; }

/* FINALS CENTER */
.finals-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 0 8px;
    position: relative;
    z-index: 2;
}
.finals-logo {
    text-align: center;
    padding: 12px;
}
.finals-logo .trophy { font-size: 2.5rem; }
.finals-title { font-size: .75rem; font-weight: 800; letter-spacing: 2px; color: var(--gold); text-transform: uppercase; }

/* CHAMPION BOX */
.champion-box {
    background: linear-gradient(135deg, rgba(255,215,0,.12), rgba(200,16,46,.1));
    border: 2px solid var(--gold);
    border-radius: 12px;
    padding: 12px;
    text-align: center;
    min-width: 120px;
    transform: translateY(-22px);
    position: relative;
    z-index: 4;
}
.champion-box .label { font-size: .65rem; font-weight: 800; letter-spacing: 1px; color: var(--gold); text-transform: uppercase; }
.champion-box .team { font-size: .85rem; font-weight: 700; margin-top: 4px; }

/* PROGRESS */
.progress-bar-custom { background: var(--panel2); border-radius: 8px; height: 6px; margin-top: 8px; }
.progress-fill { height: 100%; border-radius: 8px; background: linear-gradient(90deg, var(--accent), #1d428a); transition: width .4s; }

/* PIX MODAL */
.pix-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 1.5rem;
    max-width: 480px;
    margin: 0 auto;
}
.pix-key {
    background: var(--panel2);
    border: 1px dashed #4CAF50;
    border-radius: 8px;
    padding: .75rem 1rem;
    font-family: monospace;
    font-size: .9rem;
    word-break: break-all;
    color: #81c784;
}
.btn-copy {
    background: #4CAF50;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: .5rem 1.2rem;
    cursor: pointer;
    font-weight: 700;
    transition: background .2s;
}
.btn-copy:hover { background: #388e3c; }

/* RANKING */
.rank-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
}
.rank-num { font-size: 1.5rem; font-weight: 900; min-width: 40px; }
.rank-num.gold   { color: var(--gold); }
.rank-num.silver { color: var(--silver); }
.rank-num.bronze { color: var(--bronze); }

.rank-card.pending-review {
    border-color: #8a6b2a;
    background: linear-gradient(180deg, rgba(255, 152, 0, .12), var(--panel));
    box-shadow: 0 0 0 0 rgba(255, 179, 71, .28);
    animation: pendingPulse 1.9s ease-in-out infinite;
}

@keyframes pendingPulse {
    0% {
        box-shadow: 0 0 0 0 rgba(255, 179, 71, .28);
    }
    70% {
        box-shadow: 0 0 0 9px rgba(255, 179, 71, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(255, 179, 71, 0);
    }
}

.status-pill {
    font-size: .7rem;
    font-weight: 800;
    padding: .2rem .5rem;
    border-radius: 999px;
    border: 1px solid transparent;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.status-pill.pending {
    color: #ffca6a;
    border-color: rgba(255, 202, 106, .45);
    background: rgba(255, 152, 0, .15);
}
.status-pill.confirmed {
    color: #87df8d;
    border-color: rgba(135, 223, 141, .45);
    background: rgba(76, 175, 80, .15);
}
.admin-actions {
    display: flex;
    align-items: center;
    gap: 6px;
}
.admin-actions form { margin: 0; }
.admin-btn {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    border: 1px solid var(--border);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .95rem;
    cursor: pointer;
    color: #fff;
}
.admin-btn.approve { background: #2f9f49; border-color: #2f9f49; }
.admin-btn.reject  { background: #7d2a31; border-color: #7d2a31; }
.admin-btn:hover { opacity: .9; }

/* FORM */
.form-control-dark {
    background: var(--panel2);
    border: 1px solid var(--border);
    color: var(--text);
    border-radius: 8px;
    padding: .6rem 1rem;
    width: 100%;
    outline: none;
    transition: border-color .2s;
}
.form-control-dark:focus { border-color: var(--accent); }
.btn-apostar {
    background: linear-gradient(135deg, var(--accent), #1d428a);
    border: none;
    color: #fff;
    font-weight: 800;
    font-size: 1rem;
    padding: .75rem 2rem;
    border-radius: 10px;
    cursor: pointer;
    width: 100%;
    transition: opacity .2s;
}
.btn-apostar:hover { opacity: .88; }
.btn-apostar:disabled { opacity: .4; cursor: not-allowed; }

.alert-dark { background: var(--panel2); border-radius: 10px; padding: .75rem 1rem; margin-bottom: 1rem; }
.alert-danger-dark { border-left: 4px solid var(--accent); }
.alert-success-dark { border-left: 4px solid #4CAF50; }

/* Connector lines (CSS only) */
.connector-right { border-right: 2px solid var(--border); }
.connector-left  { border-left: 2px solid var(--border); }

@media (max-width: 700px) {
    .nba-header {
        padding: 1.4rem .9rem 2rem;
    }
    .nba-header h1 {
        font-size: 1.4rem;
        letter-spacing: 1px;
    }
    .mobile-bracket-tip {
        display: block;
        margin: 0 .25rem .75rem;
        background: rgba(255,255,255,.04);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: .55rem .7rem;
        font-size: .78rem;
        color: var(--muted);
        text-align: center;
    }
    .mobile-phase-nav {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .6rem;
        position: sticky;
        top: 8px;
        z-index: 9;
        margin: 0 .25rem .7rem;
        background: rgba(19, 20, 26, .94);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: .45rem .55rem;
        backdrop-filter: blur(4px);
    }
    .mobile-phase-nav button {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        border: 1px solid var(--border);
        background: var(--panel2);
        color: var(--text);
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .mobile-phase-nav button:disabled {
        opacity: .35;
    }
    .phase-meta {
        flex: 1;
        text-align: center;
        line-height: 1.1;
    }
    .phase-name {
        font-size: .8rem;
        font-weight: 800;
        color: #fff;
    }
    .phase-step {
        margin-top: 2px;
        font-size: .72rem;
        color: var(--muted);
    }
    .bracket-wrap {
        padding: .4rem 0 1rem;
        scroll-snap-type: x mandatory;
        mask-image: linear-gradient(to right, transparent 0, #000 16px, #000 calc(100% - 16px), transparent 100%);
    }
    .bracket-grid {
        display: flex;
        gap: 10px;
        min-width: 0;
        padding: 0 1rem;
    }
    .bracket-col,
    .finals-col {
        flex: 0 0 calc(88vw - 1rem);
        max-width: 340px;
        min-width: 270px;
        background: rgba(19, 20, 26, .8);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: .7rem;
        gap: .55rem;
        scroll-snap-align: start;
    }
    .finals-col {
        justify-content: flex-start;
    }
    .bracket-col-label {
        font-size: .66rem;
        margin-bottom: 2px;
        padding: 3px 0 7px;
    }
    .matchup {
        border-radius: 9px;
    }
    .team-row {
        padding: 9px 10px;
    }
    .seed-badge {
        min-width: 20px;
        font-size: .7rem;
    }
    .team-dot {
        width: 30px;
        height: 30px;
        font-size: .63rem;
    }
    .team-name {
        font-size: .86rem;
    }
    .games-selector {
        padding: 7px 8px;
    }
    .games-btn {
        width: 30px;
        height: 30px;
    }
    .finals-logo .trophy {
        font-size: 2rem;
    }
    .champion-box {
        transform: translateY(-6px);
        width: 100%;
    }
    #progressWrap {
        margin-bottom: 1rem !important;
    }
    .pix-card {
        padding: 1rem;
    }
}
</style>
</head>
<body>

<!-- HEADER -->
<div class="nba-header">
    <h1>🏀 NBA PLAYOFFS 2025-26</h1>
    <p style="font-size:1rem;font-weight:600;">Bracket Challenge &bull; Aposte R$15 e concorra a prêmios!</p>
    <p style="font-size:.85rem;opacity:.7;margin-top:4px;">1° lugar leva o maior prêmio &bull; 2° e 3° também ganham</p>
</div>

<div class="container-fluid py-4" style="max-width:1400px;">

<!-- PROGRESS BAR -->
<div id="progressWrap" style="max-width:600px;margin:0 auto 2rem;">
    <div style="display:flex;justify-content:space-between;font-size:.8rem;color:var(--muted);">
        <span>Resultado oficial preenchido</span>
        <span id="progressLabel">0 / 21</span>
    </div>
    <div class="progress-bar-custom"><div class="progress-fill" id="progressFill" style="width:0%"></div></div>
</div>

<!-- BRACKET -->
<div class="mobile-bracket-tip">
    Arraste para o lado para navegar entre as fases do bracket oficial.
</div>

<div class="mobile-phase-nav" id="mobilePhaseNav">
    <button type="button" id="phasePrevBtn" aria-label="Fase anterior"><i class="bi bi-chevron-left"></i></button>
    <div class="phase-meta">
        <div class="phase-name" id="phaseName">West Play-In</div>
        <div class="phase-step" id="phaseStep">Fase 1 de 9</div>
    </div>
    <button type="button" id="phaseNextBtn" aria-label="Proxima fase"><i class="bi bi-chevron-right"></i></button>
</div>

<div class="bracket-wrap">
<div class="bracket-grid">

<?php
// Helper: render a matchup card
function matchupCard($id, $t1key, $t2key, $teams, $col, $singleGame = false) {
    $t1 = $teams[$t1key] ?? ['name'=>$t1key,'seed'=>'?','color'=>'#555','abbr'=>$t1key];
    $t2 = $teams[$t2key] ?? ['name'=>$t2key,'seed'=>'?','color'=>'#777','abbr'=>$t2key];
    $gameCounts = [4,5,6,7];
    echo '<div class="matchup" id="matchup-'.$id.'" data-id="'.$id.'" data-col="'.$col.'">';
    echo '<div class="team-row" data-team="'.$t1key.'">';
    echo '<span class="seed-badge">'.$t1['seed'].'</span>';
    echo '<div class="team-dot" style="background:'.$t1['color'].';">'.$t1['abbr'].'</div>';
    echo '<span class="team-name">'.$t1['name'].'</span>';
    echo '<i class="bi bi-check-circle-fill check-icon"></i>';
    echo '</div>';
    echo '<div class="team-row" data-team="'.$t2key.'">';
    echo '<span class="seed-badge">'.$t2['seed'].'</span>';
    echo '<div class="team-dot" style="background:'.$t2['color'].';">'.$t2['abbr'].'</div>';
    echo '<span class="team-name">'.$t2['name'].'</span>';
    echo '<i class="bi bi-check-circle-fill check-icon"></i>';
    echo '</div>';
    if (!$singleGame) {
        echo '<div class="games-selector">';
        echo '<span>Jogos:</span>';
        foreach ($gameCounts as $g) {
            echo '<button type="button" class="games-btn" data-matchup="'.$id.'" data-games="'.$g.'" disabled>'.$g.'</button>';
        }
        echo '</div>';
    }
    echo '</div>';
}
?>

<!-- COL 0: WEST PLAY-IN -->
<div class="bracket-col phase-col" data-phase-label="West Play-In">
    <div class="bracket-col-label">West<br>Play-In</div>
    <div style="font-size:.72rem;color:var(--muted);font-weight:700;text-align:center;">9 x 10</div>
    <?php matchupCard('WP_TOP','LAC','GSW',$teams,0,true); ?>
    <div style="font-size:.72rem;color:var(--muted);font-weight:700;text-align:center;">7 x 8</div>
    <?php matchupCard('WP_BOTTOM','PHX','POR',$teams,0,true); ?>
    <div style="font-size:.72rem;color:var(--muted);font-weight:700;text-align:center;">Winner 9/10 x Loser 7/8</div>
    <?php matchupCard('WP_FINAL','WP_TOP_winner','WP_BOTTOM_loser',$teams,0,true); ?>
</div>

<!-- COL 1: WEST FIRST ROUND -->
<div class="bracket-col phase-col" data-phase-label="West 1a Rodada">
    <div class="bracket-col-label">West<br>1ª Rodada</div>
    <?php matchupCard('W1','OKC','W8',$teams,1); ?>
    <?php matchupCard('W2','LAL','HOU',$teams,1); ?>
    <?php matchupCard('W3','DEN','MIN',$teams,1); ?>
    <?php matchupCard('W4','SAS','W7',$teams,1); ?>
</div>

<!-- COL 2: WEST SEMIS -->
<div class="bracket-col phase-col" data-phase-label="West Semis">
    <div class="bracket-col-label">West<br>Semis</div>
    <div style="flex:1;display:flex;flex-direction:column;justify-content:space-around;gap:60px;">
    <?php matchupCard('WS1','W1_winner','W2_winner',$teams,2); ?>
    <?php matchupCard('WS2','W3_winner','W4_winner',$teams,2); ?>
    </div>
</div>

<!-- COL 3: WEST FINALS -->
<div class="bracket-col phase-col" data-phase-label="West Finals">
    <div class="bracket-col-label">West<br>Finals</div>
    <div style="flex:1;display:flex;flex-direction:column;justify-content:center;">
    <?php matchupCard('WF','WS1_winner','WS2_winner',$teams,3); ?>
    </div>
</div>

<!-- COL CENTER: NBA FINALS -->
<div class="finals-col phase-col" data-phase-label="NBA Finals">
    <div class="finals-logo">
        <div class="trophy">🏆</div>
        <div class="finals-title">NBA Finals</div>
    </div>
    <?php matchupCard('FINAL','WF_winner','EF_winner',$teams,4); ?>
    <div class="champion-box" id="championDisplay" style="opacity:.4;">
        <div class="label">Campeão</div>
        <div class="team" id="championName">???</div>
    </div>
</div>

<!-- COL 5: EAST FINALS -->
<div class="bracket-col phase-col" data-phase-label="East Finals">
    <div class="bracket-col-label">East<br>Finals</div>
    <div style="flex:1;display:flex;flex-direction:column;justify-content:center;">
    <?php matchupCard('EF','ES1_winner','ES2_winner',$teams,5); ?>
    </div>
</div>

<!-- COL 6: EAST SEMIS -->
<div class="bracket-col phase-col" data-phase-label="East Semis">
    <div class="bracket-col-label">East<br>Semis</div>
    <div style="flex:1;display:flex;flex-direction:column;justify-content:space-around;gap:60px;">
    <?php matchupCard('ES1','E1_winner','E2_winner',$teams,6); ?>
    <?php matchupCard('ES2','E3_winner','E4_winner',$teams,6); ?>
    </div>
</div>

<!-- COL 7: EAST FIRST ROUND -->
<div class="bracket-col phase-col" data-phase-label="East 1a Rodada">
    <div class="bracket-col-label">East<br>1ª Rodada</div>
    <?php matchupCard('E1','DET','E8',$teams,7); ?>
    <?php matchupCard('E2','CLE','TOR',$teams,7); ?>
    <?php matchupCard('E3','NYK','ATL',$teams,7); ?>
    <?php matchupCard('E4','BOS','E7',$teams,7); ?>
</div>

<!-- COL 8: EAST PLAY-IN -->
<div class="bracket-col phase-col" data-phase-label="East Play-In">
    <div class="bracket-col-label">East<br>Play-In</div>
    <div style="font-size:.72rem;color:var(--muted);font-weight:700;text-align:center;">9 x 10</div>
    <?php matchupCard('EP_TOP','CHA','MIA',$teams,8,true); ?>
    <div style="font-size:.72rem;color:var(--muted);font-weight:700;text-align:center;">7 x 8</div>
    <?php matchupCard('EP_BOTTOM','PHI','ORL',$teams,8,true); ?>
    <div style="font-size:.72rem;color:var(--muted);font-weight:700;text-align:center;">Winner 9/10 x Loser 7/8</div>
    <?php matchupCard('EP_FINAL','EP_TOP_winner','EP_BOTTOM_loser',$teams,8,true); ?>
</div>

</div><!-- bracket-grid -->
</div><!-- bracket-wrap -->

<div style="max-width:700px;margin:1.5rem auto 0;">
    <div class="alert-dark" style="border-left:4px solid #2f9f49;text-align:center;">
        Este bracket é atualizado pela administração e não é editável.
    </div>
</div>

<!-- RANKING -->
<div style="max-width:600px;margin:3rem auto 1rem;">
    <h5 style="font-weight:800;text-align:center;margin-bottom:1.5rem;">
        <i class="bi bi-bar-chart-fill" style="color:var(--gold);"></i>
        Ranking — Participantes Confirmados
    </h5>

    <?php if (empty($ranking)): ?>
    <div style="text-align:center;color:var(--muted);padding:2rem;">Nenhum apostador confirmado ainda. Seja o primeiro!</div>
    <?php else: ?>
    <?php foreach ($ranking as $i => $r):
        $pos = $i + 1;
        $cls = $pos === 1 ? 'gold' : ($pos === 2 ? 'silver' : ($pos === 3 ? 'bronze' : ''));
        $medal = $pos === 1 ? '🥇' : ($pos === 2 ? '🥈' : ($pos === 3 ? '🥉' : '#'.$pos));
    ?>
    <div class="rank-card mb-2">
        <div class="rank-num <?= $cls ?>"><?= $medal ?></div>
        <div style="flex:1;">
            <div style="font-weight:700;"><?= htmlspecialchars($r['nome']) ?><?= !empty($r['champion_name']) ? ' (' . htmlspecialchars($r['champion_name']) . ')' : '' ?></div>
        </div>
        <div style="font-size:1.25rem;font-weight:900;color:var(--gold);"><?= $r['pontos'] ?> pts</div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- PRIZES INFO -->
<div style="max-width:600px;margin:0 auto 3rem;">
    <div style="background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:1.25rem;text-align:center;">
        <div style="font-weight:800;margin-bottom:.75rem;font-size:1rem;">Como funciona a pontuação</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;font-size:.85rem;color:var(--muted);">
            <div style="background:var(--panel2);border-radius:8px;padding:.75rem;">
                <div style="font-weight:700;color:var(--text);">Acertar o vencedor</div>
                <div>1ª Rodada: <strong style="color:#fff;">2 pts</strong></div>
                <div>Semis: <strong style="color:#fff;">4 pts</strong></div>
                <div>Finals Conf.: <strong style="color:#fff;">8 pts</strong></div>
                <div>Finals NBA: <strong style="color:#fff;">16 pts</strong></div>
            </div>
            <div style="background:var(--panel2);border-radius:8px;padding:.75rem;">
                <div style="font-weight:700;color:var(--text);">Bônus de jogos</div>
                <div>Acertar nº exato: <strong style="color:var(--gold);">+2 pts</strong></div>
                <div style="margin-top:.5rem;font-size:.75rem;">Os 3 melhores pontuadores ganham os prêmios do pool!</div>
            </div>
        </div>
    </div>
</div>

<script>
const teams = <?= json_encode($teams, JSON_UNESCAPED_UNICODE) ?>;
const officialResults = <?= json_encode($officialResultsMap, JSON_UNESCAPED_UNICODE) ?>;
const matchupPopularityCounts = <?= json_encode($matchupPopularityCounts, JSON_UNESCAPED_UNICODE) ?>;

const playInMatchups = ['WP_TOP','WP_BOTTOM','WP_FINAL','EP_TOP','EP_BOTTOM','EP_FINAL'];
const seriesMatchups = ['W1','W2','W3','W4','E1','E2','E3','E4','WS1','WS2','ES1','ES2','WF','EF','FINAL'];
const totalMatchups = playInMatchups.length + seriesMatchups.length;
const phaseMatchups = [
    ['WP_TOP','WP_BOTTOM','WP_FINAL'],
    ['W1','W2','W3','W4'],
    ['WS1','WS2'],
    ['WF'],
    ['FINAL'],
    ['EF'],
    ['ES1','ES2'],
    ['E1','E2','E3','E4'],
    ['EP_TOP','EP_BOTTOM','EP_FINAL'],
];

let phaseCols = [];
let phaseIndex = 0;
const mobilePhaseMedia = window.matchMedia('(max-width: 700px)');
let phaseScrollTimer = null;

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

function getLoser(matchupId, winnerKey) {
    const teamsInMatchup = matchupTeams[matchupId] || [];
    if (!winnerKey || teamsInMatchup.length < 2) return null;
    if (teamsInMatchup[0] === winnerKey) return teamsInMatchup[1] || null;
    if (teamsInMatchup[1] === winnerKey) return teamsInMatchup[0] || null;
    return null;
}

function getValidWinner(matchupId) {
    const winner = picks[matchupId]?.winner || null;
    if (!winner) return null;
    const teamsInMatchup = matchupTeams[matchupId] || [];
    return teamsInMatchup.includes(winner) ? winner : null;
}

function applySeriesWinnerFeed(sourceId, targetId, slotIndex) {
    matchupTeams[targetId][slotIndex] = getValidWinner(sourceId);
}

function getTeamPickPercent(matchupId, teamKey) {
    if (!teamKey) return 0;
    const byTeam = matchupPopularityCounts[matchupId] || {};
    const total = Object.values(byTeam).reduce((acc, n) => acc + (parseInt(n, 10) || 0), 0);
    if (total <= 0) return 0;
    const count = parseInt(byTeam[teamKey] || 0, 10);
    return Math.round((count / total) * 100);
}

function renderMatchupTeams(matchupId) {
    const matchup = document.getElementById('matchup-' + matchupId);
    if (!matchup) return;

    const [t1, t2] = matchupTeams[matchupId];
    const rows = matchup.querySelectorAll('.team-row');
    const isSeries = seriesMatchups.includes(matchupId);
    const pickedWinner = picks[matchupId]?.winner || null;
    const pickedGames = parseInt(picks[matchupId]?.games, 10);
    const loserShouldBeGray = !isSeries || (pickedGames >= 4 && pickedGames <= 7);

    function updateRow(row, teamKey) {
        if (!teamKey) {
            row.dataset.team = '';
            row.querySelector('.team-dot').style.background = '#333';
            row.querySelector('.team-dot').textContent = '?';
            row.querySelector('.team-name').textContent = '?';
            row.querySelector('.seed-badge').textContent = '?';
            return;
        }

        const t = teams[teamKey] || {name: teamKey, seed: '?', color: '#555', abbr: teamKey};
        const pickPct = getTeamPickPercent(matchupId, teamKey);
        row.dataset.team = teamKey;
        row.querySelector('.team-dot').style.background = t.color;
        row.querySelector('.team-dot').textContent = t.abbr;
        row.querySelector('.team-name').textContent = `${t.name} | ${pickPct}%`;
        row.querySelector('.seed-badge').textContent = t.seed;
    }

    updateRow(rows[0], t1);
    updateRow(rows[1], t2);

    rows.forEach(r => r.classList.remove('selected', 'winner-result', 'loser-result'));
    if (pickedWinner) {
        rows.forEach(r => {
            if (r.dataset.team === pickedWinner) {
                r.classList.add('selected', 'winner-result');
            } else if (loserShouldBeGray && r.dataset.team) {
                r.classList.add('loser-result');
            }
        });
    }

    document.querySelectorAll('[data-matchup="' + matchupId + '"]').forEach(btn => {
        btn.classList.toggle('active', parseInt(btn.dataset.games, 10) === pickedGames);
    });

    matchup.classList.toggle('locked', !(t1 && t2));
}

function recomputeBracket() {
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

    Object.keys(baseMatchupTeams).forEach(renderMatchupTeams);
    updateChampion(getValidWinner('FINAL'));
}

function hydrateOfficialResults() {
    Object.keys(officialResults || {}).forEach(matchupId => {
        const data = officialResults[matchupId] || {};
        if (!data.winner && !data.games) return;
        picks[matchupId] = {};
        if (data.winner) {
            picks[matchupId].winner = data.winner;
        }
        if (data.games) {
            picks[matchupId].games = parseInt(data.games, 10);
        }
    });
}

function updateChampion(teamKey) {
    const box = document.getElementById('championDisplay');
    const nameEl = document.getElementById('championName');
    if (!box || !nameEl) return;
    if (!teamKey) {
        box.style.opacity = '.4';
        nameEl.textContent = '???';
        return;
    }
    box.style.opacity = '1';
    nameEl.textContent = teams[teamKey]?.name || teamKey;
}

function countDone() {
    let done = 0;
    playInMatchups.forEach(id => {
        if (picks[id]?.winner) done++;
    });
    seriesMatchups.forEach(id => {
        const p = picks[id];
        const g = parseInt(p?.games, 10);
        if (p?.winner && g >= 4 && g <= 7) done++;
    });
    return done;
}

function updateProgress() {
    const done = countDone();
    document.getElementById('progressLabel').textContent = done + ' / ' + totalMatchups;
    document.getElementById('progressFill').style.width = (done / totalMatchups * 100) + '%';
}

function isMatchupComplete(matchupId) {
    const p = picks[matchupId];
    if (!p || !p.winner) return false;
    if (playInMatchups.includes(matchupId)) return true;
    const g = parseInt(p.games, 10);
    return g >= 4 && g <= 7;
}

function isPhaseComplete(idx) {
    const phase = phaseMatchups[idx] || [];
    return phase.every(isMatchupComplete);
}

function updatePhaseNavUI() {
    if (!phaseCols.length) return;
    const nameEl = document.getElementById('phaseName');
    const stepEl = document.getElementById('phaseStep');
    const prevBtn = document.getElementById('phasePrevBtn');
    const nextBtn = document.getElementById('phaseNextBtn');
    if (!nameEl || !stepEl || !prevBtn || !nextBtn) return;

    const activeCol = phaseCols[phaseIndex];
    const label = activeCol ? (activeCol.dataset.phaseLabel || `Fase ${phaseIndex + 1}`) : `Fase ${phaseIndex + 1}`;
    nameEl.textContent = label;
    stepEl.textContent = `Fase ${phaseIndex + 1} de ${phaseCols.length}`;
    prevBtn.disabled = phaseIndex <= 0;
    nextBtn.disabled = phaseIndex >= phaseCols.length - 1;
}

function scrollToPhase(newIndex, smooth = true) {
    if (!phaseCols.length) return;
    const bounded = Math.max(0, Math.min(newIndex, phaseCols.length - 1));
    phaseIndex = bounded;
    const targetCol = phaseCols[bounded];
    if (targetCol) {
        targetCol.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'nearest', inline: 'start' });
    }
    updatePhaseNavUI();
}

function syncPhaseByScrollPosition() {
    if (!mobilePhaseMedia.matches || !phaseCols.length) return;
    const wrap = document.querySelector('.bracket-wrap');
    if (!wrap) return;
    const baseLeft = wrap.scrollLeft;
    let closestIndex = 0;
    let minDistance = Number.MAX_SAFE_INTEGER;
    phaseCols.forEach((col, idx) => {
        const distance = Math.abs(col.offsetLeft - baseLeft - 8);
        if (distance < minDistance) {
            minDistance = distance;
            closestIndex = idx;
        }
    });
    if (closestIndex !== phaseIndex) {
        phaseIndex = closestIndex;
        updatePhaseNavUI();
    }
}

function maybeAutoAdvancePhase() {
    return;
}

function initMobilePhaseUI() {
    phaseCols = Array.from(document.querySelectorAll('.phase-col'));
    if (!phaseCols.length) return;

    const prevBtn = document.getElementById('phasePrevBtn');
    const nextBtn = document.getElementById('phaseNextBtn');
    const wrap = document.querySelector('.bracket-wrap');

    if (prevBtn && !prevBtn.dataset.bound) {
        prevBtn.addEventListener('click', () => scrollToPhase(phaseIndex - 1, true));
        prevBtn.dataset.bound = '1';
    }
    if (nextBtn && !nextBtn.dataset.bound) {
        nextBtn.addEventListener('click', () => scrollToPhase(phaseIndex + 1, true));
        nextBtn.dataset.bound = '1';
    }
    if (wrap && !wrap.dataset.boundPhase) {
        wrap.addEventListener('scroll', () => {
            if (phaseScrollTimer) clearTimeout(phaseScrollTimer);
            phaseScrollTimer = setTimeout(syncPhaseByScrollPosition, 40);
        });
        wrap.dataset.boundPhase = '1';
    }

    phaseIndex = Math.max(0, Math.min(phaseIndex, phaseCols.length - 1));
    updatePhaseNavUI();
}

// Init dynamic state
hydrateOfficialResults();
recomputeBracket();
initMobilePhaseUI();
updateProgress();

window.addEventListener('resize', initMobilePhaseUI);
</script>
</body>
</html>
