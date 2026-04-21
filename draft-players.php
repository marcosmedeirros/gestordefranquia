<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

if (!$team) {
    header('Location: /onboarding.php');
    exit;
}

$draftPlayers = [
    ['name' => 'Markelle Fultz',      'position' => 'PG/SG', 'country' => 'United States', 'school' => 'Washington (Fr.)',            'ovr' => 95],
    ['name' => 'Lonzo Ball',          'position' => 'PG',    'country' => 'United States', 'school' => 'UCLA (Fr.)',                   'ovr' => 92],
    ['name' => 'Jayson Tatum',        'position' => 'SF',    'country' => 'United States', 'school' => 'Duke (Fr.)',                   'ovr' => 92],
    ['name' => 'Josh Jackson',        'position' => 'SF',    'country' => 'United States', 'school' => 'Kansas (Fr.)',                 'ovr' => 90],
    ['name' => "De'Aaron Fox",        'position' => 'PG',    'country' => 'United States', 'school' => 'Kentucky (Fr.)',               'ovr' => 89],
    ['name' => 'Jonathan Isaac',      'position' => 'SF/PF', 'country' => 'United States', 'school' => 'Florida State (Fr.)',          'ovr' => 87],
    ['name' => 'Lauri Markkanen',     'position' => 'PF',    'country' => 'Finland',       'school' => 'Arizona (Fr.)',                'ovr' => 86],
    ['name' => 'Frank Ntilikina',     'position' => 'PG',    'country' => 'France',        'school' => 'SIG Strasbourg (France)',      'ovr' => 84],
    ['name' => 'Dennis Smith Jr.',    'position' => 'PG',    'country' => 'United States', 'school' => 'NC State (Fr.)',               'ovr' => 84],
    ['name' => 'Zach Collins',        'position' => 'C/PF',  'country' => 'United States', 'school' => 'Gonzaga (Fr.)',                'ovr' => 83],
    ['name' => 'Malik Monk',          'position' => 'SG',    'country' => 'United States', 'school' => 'Kentucky (Fr.)',               'ovr' => 82],
    ['name' => 'Luke Kennard',        'position' => 'SG',    'country' => 'United States', 'school' => 'Duke (So.)',                   'ovr' => 82],
    ['name' => 'Donovan Mitchell',    'position' => 'SG',    'country' => 'United States', 'school' => 'Louisville (So.)',             'ovr' => 81],
    ['name' => 'Bam Adebayo',         'position' => 'PF/C',  'country' => 'United States', 'school' => 'Kentucky (Fr.)',               'ovr' => 80],
    ['name' => 'Justin Jackson',      'position' => 'SF',    'country' => 'United States', 'school' => 'North Carolina (Jr.)',         'ovr' => 79],
    ['name' => 'Justin Patton',       'position' => 'C',     'country' => 'United States', 'school' => 'Creighton (Fr.)',              'ovr' => 78],
    ['name' => 'D. J. Wilson',        'position' => 'PF/SF', 'country' => 'United States', 'school' => 'Michigan (Jr.)',               'ovr' => 77],
    ['name' => 'T. J. Leaf',          'position' => 'PF',    'country' => 'Israel',        'school' => 'UCLA (Fr.)',                   'ovr' => 76],
    ['name' => 'John Collins',        'position' => 'PF',    'country' => 'United States', 'school' => 'Wake Forest (So.)',            'ovr' => 75],
    ['name' => 'Harry Giles III',     'position' => 'PF/C',  'country' => 'United States', 'school' => 'Duke (Fr.)',                   'ovr' => 75],
    ['name' => 'Terrance Ferguson',   'position' => 'SG',    'country' => 'United States', 'school' => 'Adelaide 36ers (Australia)',   'ovr' => 74],
    ['name' => 'Jarrett Allen',       'position' => 'C',     'country' => 'United States', 'school' => 'Texas (Fr.)',                  'ovr' => 74],
    ['name' => 'OG Anunoby',          'position' => 'SF',    'country' => 'United Kingdom','school' => 'Indiana (So.)',                'ovr' => 73],
    ['name' => 'Tyler Lydon',         'position' => 'PF',    'country' => 'United States', 'school' => 'Syracuse (So.)',               'ovr' => 72],
    ['name' => 'Anžejs Pasečņiks',    'position' => 'C',     'country' => 'Latvia',        'school' => 'Herbalife Gran Canaria (Spain)','ovr'=> 71],
    ['name' => 'Caleb Swanigan',      'position' => 'PF',    'country' => 'United States', 'school' => 'Purdue (So.)',                 'ovr' => 71],
    ['name' => 'Kyle Kuzma',          'position' => 'PF',    'country' => 'United States', 'school' => 'Utah (Jr.)',                   'ovr' => 70],
    ['name' => 'Tony Bradley',        'position' => 'PF/C',  'country' => 'United States', 'school' => 'North Carolina (Fr.)',         'ovr' => 69],
    ['name' => 'Derrick White',       'position' => 'PG/SG', 'country' => 'United States', 'school' => 'Colorado (Sr.)',               'ovr' => 68],
    ['name' => 'Josh Hart',           'position' => 'SG',    'country' => 'United States', 'school' => 'Villanova (Sr.)',              'ovr' => 68],
    ['name' => 'Frank Jackson',       'position' => 'PG',    'country' => 'United States', 'school' => 'Duke (Fr.)',                   'ovr' => 67],
    ['name' => 'Davon Reed',          'position' => 'SG',    'country' => 'United States', 'school' => 'Miami (Sr.)',                  'ovr' => 66],
    ['name' => 'Wes Iwundu',          'position' => 'SF',    'country' => 'United States', 'school' => 'Kansas State (Sr.)',           'ovr' => 65],
    ['name' => 'Frank Mason III',     'position' => 'PG',    'country' => 'United States', 'school' => 'Kansas (Sr.)',                 'ovr' => 65],
    ['name' => 'Ivan Rabb',           'position' => 'PF',    'country' => 'United States', 'school' => 'California (So.)',             'ovr' => 64],
    ['name' => 'Jonah Bolden',        'position' => 'PF',    'country' => 'Australia',     'school' => 'Crvena zvezda (Serbia)',       'ovr' => 64],
    ['name' => 'Semi Ojeleye',        'position' => 'SF/PF', 'country' => 'United States', 'school' => 'SMU (Jr.)',                    'ovr' => 63],
    ['name' => 'Jordan Bell',         'position' => 'PF',    'country' => 'United States', 'school' => 'Oregon (Jr.)',                 'ovr' => 63],
    ['name' => 'Jawun Evans',         'position' => 'PG',    'country' => 'United States', 'school' => 'Oklahoma State (So.)',         'ovr' => 62],
    ['name' => 'Dwayne Bacon',        'position' => 'SG',    'country' => 'United States', 'school' => 'Florida State (So.)',          'ovr' => 61],
    ['name' => 'Tyler Dorsey',        'position' => 'SG',    'country' => 'Greece',        'school' => 'Oregon (So.)',                 'ovr' => 60],
    ['name' => 'Thomas Bryant',       'position' => 'PF',    'country' => 'United States', 'school' => 'Indiana (So.)',                'ovr' => 60],
    ['name' => 'Isaiah Hartenstein',  'position' => 'PF/C',  'country' => 'Germany',       'school' => 'Žalgiris (Lithuania)',         'ovr' => 59],
    ['name' => 'Damyean Dotson',      'position' => 'SG',    'country' => 'United States', 'school' => 'Houston (Sr.)',                'ovr' => 58],
    ['name' => 'Dillon Brooks',       'position' => 'SF',    'country' => 'Canada',        'school' => 'Oregon (Jr.)',                 'ovr' => 58],
    ['name' => 'Sterling Brown',      'position' => 'SG',    'country' => 'United States', 'school' => 'SMU (Sr.)',                    'ovr' => 57],
    ['name' => 'Ike Anigbogu',        'position' => 'C',     'country' => 'United States', 'school' => 'UCLA (Fr.)',                   'ovr' => 56],
    ['name' => 'Sindarius Thornwell', 'position' => 'SG',    'country' => 'United States', 'school' => 'South Carolina (Sr.)',         'ovr' => 56],
    ['name' => 'Vlatko Čančar',       'position' => 'SF',    'country' => 'Slovenia',      'school' => 'Mega Leks (Serbia)',           'ovr' => 55],
    ['name' => 'Mathias Lessort',     'position' => 'PF/C',  'country' => 'France',        'school' => 'Nanterre 92 (France)',         'ovr' => 54],
    ['name' => 'Monté Morris',        'position' => 'PG',    'country' => 'United States', 'school' => 'Iowa State (Sr.)',             'ovr' => 53],
    ['name' => 'Edmond Sumner',       'position' => 'PG',    'country' => 'United States', 'school' => 'Xavier (Jr.)',                 'ovr' => 53],
    ['name' => 'Kadeem Allen',        'position' => 'SG',    'country' => 'United States', 'school' => 'Arizona (Sr.)',                'ovr' => 52],
    ['name' => 'Alec Peters',         'position' => 'SF',    'country' => 'United States', 'school' => 'Valparaiso (Sr.)',             'ovr' => 51],
    ['name' => 'Nigel Williams-Goss', 'position' => 'PG',    'country' => 'United States', 'school' => 'Gonzaga (Jr.)',                'ovr' => 50],
    ['name' => 'Jabari Bird',         'position' => 'SG',    'country' => 'United States', 'school' => 'California (Sr.)',             'ovr' => 50],
    ['name' => 'Sasha Vezenkov',      'position' => 'PF',    'country' => 'Bulgaria',      'school' => 'FC Barcelona Lassa (Spain)',   'ovr' => 49],
    ['name' => 'Ognjen Jaramaz',      'position' => 'PG',    'country' => 'Serbia',        'school' => 'Mega Leks (Serbia)',           'ovr' => 48],
    ['name' => 'Jaron Blossomgame',   'position' => 'SF',    'country' => 'United States', 'school' => 'Clemson (Sr.)',                'ovr' => 47],
    ['name' => 'Alpha Kaba',          'position' => 'PF/C',  'country' => 'Guinea',        'school' => 'Mega Leks (Serbia)',           'ovr' => 45],
];

function getOvrColor($ovr) {
    if ($ovr >= 95) return '#22c55e';
    if ($ovr >= 90) return '#86efac';
    if ($ovr >= 85) return '#f59e0b';
    if ($ovr >= 80) return '#fb923c';
    if ($ovr >= 70) return '#f87171';
    return '#868690';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Próximo Draft — FBA Manager</title>
    <link rel="manifest" href="/manifest.json?v=3">
    <meta name="theme-color" content="#07070a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* ── Tokens ──────────────────────────────────── */
        :root {
            --red:        #fc0025;
            --red-soft:   rgba(252,0,37,.10);
            --bg:         #07070a;
            --panel:      #101013;
            --panel-2:    #16161a;
            --panel-3:    #1c1c21;
            --border:     rgba(255,255,255,.06);
            --border-md:  rgba(255,255,255,.10);
            --border-red: rgba(252,0,37,.22);
            --text:       #f0f0f3;
            --text-2:     #868690;
            --text-3:     #48484f;
            --green:      #22c55e;
            --amber:      #f59e0b;
            --font:       'Poppins', sans-serif;
            --radius:     14px;
            --radius-sm:  10px;
            --ease:       cubic-bezier(.2,.8,.2,1);
            --t:          200ms;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; }
        body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; min-height: 100vh; }

        /* ── Layout ──────────────────────────────────── */
        .app-wrap { max-width: 1100px; margin: 0 auto; padding: 24px 20px 56px; }

        /* ── Topbar ──────────────────────────────────── */
        .app-topbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 14px; flex-wrap: wrap;
            padding: 14px 20px;
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            margin-bottom: 28px;
        }
        .app-topbar-left { display: flex; align-items: center; gap: 12px; }
        .app-logo { width: 32px; height: 32px; border-radius: 8px; background: var(--red); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; color: #fff; flex-shrink: 0; }
        .app-title { font-size: 15px; font-weight: 700; line-height: 1.1; }
        .app-title span { display: block; font-size: 11px; font-weight: 400; color: var(--text-2); }
        .back-link { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: var(--text-2); text-decoration: none; transition: color var(--t) var(--ease); }
        .back-link:hover { color: var(--red); }

        /* ── Page header ──────────────────────────────── */
        .page-head { margin-bottom: 22px; }
        .page-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: var(--red); margin-bottom: 5px; }
        .page-title { font-size: 1.5rem; font-weight: 800; margin-bottom: 4px; }
        .page-sub { font-size: 13px; color: var(--text-2); }

        /* ── Panel card ──────────────────────────────── */
        .panel-card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
        .panel-card-head {
            padding: 14px 18px; border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
        }
        .panel-card-title { font-size: 13px; font-weight: 700; }
        .panel-card-body { padding: 16px 18px; }

        /* ── Search / Filter ─────────────────────────── */
        .search-input, .filter-select {
            background: var(--panel-2); border: 1px solid var(--border-md);
            border-radius: 8px; padding: 9px 12px;
            color: var(--text); font-family: var(--font); font-size: 13px;
            outline: none; transition: border-color var(--t) var(--ease); width: 100%;
        }
        .search-input:focus, .filter-select:focus { border-color: var(--red); }
        .search-input::placeholder { color: var(--text-3); }
        .filter-select option { background: var(--panel-2); }

        /* ── Stats bar ───────────────────────────────── */
        .stats-bar { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .stats-bar-item { font-size: 12px; color: var(--text-2); }
        .stats-bar-item strong { color: var(--text); }

        /* ── Table ───────────────────────────────────── */
        .data-table-wrap { background: var(--panel-2); border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .data-table th {
            font-size: 10px; font-weight: 700; letter-spacing: .8px; text-transform: uppercase;
            color: var(--text-3); padding: 10px 14px; border-bottom: 1px solid var(--border);
            text-align: left; white-space: nowrap; background: var(--panel-2);
        }
        .data-table td { padding: 10px 14px; border-bottom: 1px solid var(--border); color: var(--text-2); vertical-align: middle; }
        .data-table td.td-rank { font-weight: 700; color: var(--text-3); width: 40px; }
        .data-table td.td-name { font-weight: 600; color: var(--text); }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover td { background: var(--panel-3); }

        /* ── Position pill ───────────────────────────── */
        .pos-pill {
            display: inline-flex; padding: 2px 8px; border-radius: 999px;
            font-size: 10px; font-weight: 700;
            background: var(--panel-3); color: var(--text-2);
            border: 1px solid var(--border); white-space: nowrap;
        }

        /* ── OVR badge ───────────────────────────────── */
        .ovr-badge { font-weight: 800; font-size: 13px; }

        /* ── Hidden row ──────────────────────────────── */
        .player-row.hidden { display: none; }

        /* ── Responsive ──────────────────────────────── */
        @media (max-width: 768px) {
            .app-wrap { padding: 16px 14px 40px; }
            .data-table th:nth-child(4),
            .data-table td:nth-child(4) { display: none; }
        }
        @media (max-width: 576px) {
            .data-table th:nth-child(5),
            .data-table td:nth-child(5) { display: none; }
        }
    </style>
</head>
<body>

<!-- Topbar -->
<div class="app-topbar">
    <div class="app-topbar-left">
        <div class="app-logo">FBA</div>
        <div class="app-title">Próximo Draft <span>Prospectos disponíveis</span></div>
    </div>
    <a href="dashboard.php" class="back-link"><i class="bi bi-arrow-left"></i> Dashboard</a>
</div>

<div class="app-wrap">

    <div class="page-head">
        <div class="page-eyebrow">Draft</div>
        <h1 class="page-title">Próximo Draft</h1>
        <p class="page-sub">Lista de prospectos disponíveis para seleção.</p>
    </div>

    <div class="panel-card">
        <div class="panel-card-head">
            <div class="panel-card-title">Jogadores</div>
            <div class="stats-bar">
                <div class="stats-bar-item">Total: <strong id="totalCount"><?= count($draftPlayers) ?></strong></div>
                <div class="stats-bar-item">Exibindo: <strong id="visibleCount"><?= count($draftPlayers) ?></strong></div>
            </div>
        </div>
        <div class="panel-card-body">
            <div class="d-flex flex-column flex-md-row gap-2 mb-3">
                <input type="text" id="searchInput" class="search-input" placeholder="Buscar jogador…">
                <select id="positionFilter" class="filter-select" style="max-width:200px">
                    <option value="">Todas as posições</option>
                    <option value="PG">Point Guard (PG)</option>
                    <option value="SG">Shooting Guard (SG)</option>
                    <option value="SF">Small Forward (SF)</option>
                    <option value="PF">Power Forward (PF)</option>
                    <option value="C">Center (C)</option>
                </select>
            </div>
        </div>

        <div class="data-table-wrap" style="border-radius:0;border-left:none;border-right:none;border-bottom:none">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Jogador</th>
                        <th>Pos</th>
                        <th>Escola / Procedência</th>
                        <th>País</th>
                        <th style="text-align:right">OVR</th>
                    </tr>
                </thead>
                <tbody id="playersTableBody">
                    <?php foreach ($draftPlayers as $index => $player): ?>
                    <tr class="player-row" data-player='<?= htmlspecialchars(json_encode($player), ENT_QUOTES) ?>'>
                        <td class="td-rank"><?= $index + 1 ?></td>
                        <td class="td-name"><?= htmlspecialchars($player['name']) ?></td>
                        <td><span class="pos-pill"><?= htmlspecialchars($player['position']) ?></span></td>
                        <td><?= htmlspecialchars($player['school']) ?></td>
                        <td><?= htmlspecialchars($player['country']) ?></td>
                        <td style="text-align:right">
                            <span class="ovr-badge" style="color:<?= getOvrColor($player['ovr']) ?>"><?= $player['ovr'] ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div><!-- .panel-card -->

</div><!-- .app-wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const searchInput    = document.getElementById('searchInput');
    const positionFilter = document.getElementById('positionFilter');
    const visibleCount   = document.getElementById('visibleCount');

    function filterPlayers() {
        const term = searchInput.value.toLowerCase().trim();
        const pos  = positionFilter.value;
        const rows = document.querySelectorAll('#playersTableBody .player-row');
        let visible = 0;

        rows.forEach(row => {
            const p = JSON.parse(row.dataset.player);
            const matchName = !term || p.name.toLowerCase().includes(term);
            const matchPos  = !pos  || p.position.split('/').some(x => x.trim() === pos);
            const show = matchName && matchPos;
            row.classList.toggle('hidden', !show);
            if (show) visible++;
        });

        visibleCount.textContent = visible;
    }

    searchInput.addEventListener('input', filterPlayers);
    positionFilter.addEventListener('change', filterPlayers);
</script>
<script src="/js/pwa.js"></script>
</body>
</html>
