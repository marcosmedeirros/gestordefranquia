<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

if (!$team) {
    header('Location: /onboarding.php');
    exit;
}

// Lista de jogadores do draft
$draftPlayers = [
    ['name' => 'Markelle Fultz', 'position' => 'PG/SG', 'country' => 'United States', 'school' => 'Washington (Fr.)', 'ovr' => 95],
    ['name' => 'Lonzo Ball', 'position' => 'PG', 'country' => 'United States', 'school' => 'UCLA (Fr.)', 'ovr' => 92],
    ['name' => 'Jayson Tatum', 'position' => 'SF', 'country' => 'United States', 'school' => 'Duke (Fr.)', 'ovr' => 92],
    ['name' => 'Josh Jackson', 'position' => 'SF', 'country' => 'United States', 'school' => 'Kansas (Fr.)', 'ovr' => 90],
    ['name' => 'De\'Aaron Fox', 'position' => 'PG', 'country' => 'United States', 'school' => 'Kentucky (Fr.)', 'ovr' => 89],
    ['name' => 'Jonathan Isaac', 'position' => 'SF/PF', 'country' => 'United States', 'school' => 'Florida State (Fr.)', 'ovr' => 87],
    ['name' => 'Lauri Markkanen', 'position' => 'PF', 'country' => 'Finland', 'school' => 'Arizona (Fr.)', 'ovr' => 86],
    ['name' => 'Frank Ntilikina', 'position' => 'PG', 'country' => 'France', 'school' => 'SIG Strasbourg (France)', 'ovr' => 84],
    ['name' => 'Dennis Smith Jr.', 'position' => 'PG', 'country' => 'United States', 'school' => 'NC State (Fr.)', 'ovr' => 84],
    ['name' => 'Zach Collins', 'position' => 'C/PF', 'country' => 'United States', 'school' => 'Gonzaga (Fr.)', 'ovr' => 83],
    ['name' => 'Malik Monk', 'position' => 'SG', 'country' => 'United States', 'school' => 'Kentucky (Fr.)', 'ovr' => 82],
    ['name' => 'Luke Kennard', 'position' => 'SG', 'country' => 'United States', 'school' => 'Duke (So.)', 'ovr' => 82],
    ['name' => 'Donovan Mitchell', 'position' => 'SG', 'country' => 'United States', 'school' => 'Louisville (So.)', 'ovr' => 81],
    ['name' => 'Bam Adebayo', 'position' => 'PF/C', 'country' => 'United States', 'school' => 'Kentucky (Fr.)', 'ovr' => 80],
    ['name' => 'Justin Jackson', 'position' => 'SF', 'country' => 'United States', 'school' => 'North Carolina (Jr.)', 'ovr' => 79],
    ['name' => 'Justin Patton', 'position' => 'C', 'country' => 'United States', 'school' => 'Creighton (Fr.)', 'ovr' => 78],
    ['name' => 'D. J. Wilson', 'position' => 'PF/SF', 'country' => 'United States', 'school' => 'Michigan (Jr.)', 'ovr' => 77],
    ['name' => 'T. J. Leaf', 'position' => 'PF', 'country' => 'Israel', 'school' => 'UCLA (Fr.)', 'ovr' => 76],
    ['name' => 'John Collins', 'position' => 'PF', 'country' => 'United States', 'school' => 'Wake Forest (So.)', 'ovr' => 75],
    ['name' => 'Harry Giles III', 'position' => 'PF/C', 'country' => 'United States', 'school' => 'Duke (Fr.)', 'ovr' => 75],
    ['name' => 'Terrance Ferguson', 'position' => 'SG', 'country' => 'United States', 'school' => 'Adelaide 36ers (Australia)', 'ovr' => 74],
    ['name' => 'Jarrett Allen', 'position' => 'C', 'country' => 'United States', 'school' => 'Texas (Fr.)', 'ovr' => 74],
    ['name' => 'OG Anunoby', 'position' => 'SF', 'country' => 'United Kingdom', 'school' => 'Indiana (So.)', 'ovr' => 73],
    ['name' => 'Tyler Lydon', 'position' => 'PF', 'country' => 'United States', 'school' => 'Syracuse (So.)', 'ovr' => 72],
    ['name' => 'Anžejs Pasečņiks', 'position' => 'C', 'country' => 'Latvia', 'school' => 'Herbalife Gran Canaria (Spain)', 'ovr' => 71],
    ['name' => 'Caleb Swanigan', 'position' => 'PF', 'country' => 'United States', 'school' => 'Purdue (So.)', 'ovr' => 71],
    ['name' => 'Kyle Kuzma', 'position' => 'PF', 'country' => 'United States', 'school' => 'Utah (Jr.)', 'ovr' => 70],
    ['name' => 'Tony Bradley', 'position' => 'PF/C', 'country' => 'United States', 'school' => 'North Carolina (Fr.)', 'ovr' => 69],
    ['name' => 'Derrick White', 'position' => 'PG/SG', 'country' => 'United States', 'school' => 'Colorado (Sr.)', 'ovr' => 68],
    ['name' => 'Josh Hart', 'position' => 'SG', 'country' => 'United States', 'school' => 'Villanova (Sr.)', 'ovr' => 68],
    ['name' => 'Frank Jackson', 'position' => 'PG', 'country' => 'United States', 'school' => 'Duke (Fr.)', 'ovr' => 67],
    ['name' => 'Davon Reed', 'position' => 'SG', 'country' => 'United States', 'school' => 'Miami (Sr.)', 'ovr' => 66],
    ['name' => 'Wes Iwundu', 'position' => 'SF', 'country' => 'United States', 'school' => 'Kansas State (Sr.)', 'ovr' => 65],
    ['name' => 'Frank Mason III', 'position' => 'PG', 'country' => 'United States', 'school' => 'Kansas (Sr.)', 'ovr' => 65],
    ['name' => 'Ivan Rabb', 'position' => 'PF', 'country' => 'United States', 'school' => 'California (So.)', 'ovr' => 64],
    ['name' => 'Jonah Bolden', 'position' => 'PF', 'country' => 'Australia', 'school' => 'Crvena zvezda (Serbia)', 'ovr' => 64],
    ['name' => 'Semi Ojeleye', 'position' => 'SF/PF', 'country' => 'United States', 'school' => 'SMU (Jr.)', 'ovr' => 63],
    ['name' => 'Jordan Bell', 'position' => 'PF', 'country' => 'United States', 'school' => 'Oregon (Jr.)', 'ovr' => 63],
    ['name' => 'Jawun Evans', 'position' => 'PG', 'country' => 'United States', 'school' => 'Oklahoma State (So.)', 'ovr' => 62],
    ['name' => 'Dwayne Bacon', 'position' => 'SG', 'country' => 'United States', 'school' => 'Florida State (So.)', 'ovr' => 61],
    ['name' => 'Tyler Dorsey', 'position' => 'SG', 'country' => 'Greece', 'school' => 'Oregon (So.)', 'ovr' => 60],
    ['name' => 'Thomas Bryant', 'position' => 'PF', 'country' => 'United States', 'school' => 'Indiana (So.)', 'ovr' => 60],
    ['name' => 'Isaiah Hartenstein', 'position' => 'PF/C', 'country' => 'Germany', 'school' => 'Žalgiris (Lithuania)', 'ovr' => 59],
    ['name' => 'Damyean Dotson', 'position' => 'SG', 'country' => 'United States', 'school' => 'Houston (Sr.)', 'ovr' => 58],
    ['name' => 'Dillon Brooks', 'position' => 'SF', 'country' => 'Canada', 'school' => 'Oregon (Jr.)', 'ovr' => 58],
    ['name' => 'Sterling Brown', 'position' => 'SG', 'country' => 'United States', 'school' => 'SMU (Sr.)', 'ovr' => 57],
    ['name' => 'Ike Anigbogu', 'position' => 'C', 'country' => 'United States', 'school' => 'UCLA (Fr.)', 'ovr' => 56],
    ['name' => 'Sindarius Thornwell', 'position' => 'SG', 'country' => 'United States', 'school' => 'South Carolina (Sr.)', 'ovr' => 56],
    ['name' => 'Vlatko Čančar', 'position' => 'SF', 'country' => 'Slovenia', 'school' => 'Mega Leks (Serbia)', 'ovr' => 55],
    ['name' => 'Mathias Lessort', 'position' => 'PF/C', 'country' => 'France', 'school' => 'Nanterre 92 (France)', 'ovr' => 54],
    ['name' => 'Monté Morris', 'position' => 'PG', 'country' => 'United States', 'school' => 'Iowa State (Sr.)', 'ovr' => 53],
    ['name' => 'Edmond Sumner', 'position' => 'PG', 'country' => 'United States', 'school' => 'Xavier (Jr.)', 'ovr' => 53],
    ['name' => 'Kadeem Allen', 'position' => 'SG', 'country' => 'United States', 'school' => 'Arizona (Sr.)', 'ovr' => 52],
    ['name' => 'Alec Peters', 'position' => 'SF', 'country' => 'United States', 'school' => 'Valparaiso (Sr.)', 'ovr' => 51],
    ['name' => 'Nigel Williams-Goss', 'position' => 'PG', 'country' => 'United States', 'school' => 'Gonzaga (Jr.)', 'ovr' => 50],
    ['name' => 'Jabari Bird', 'position' => 'SG', 'country' => 'United States', 'school' => 'California (Sr.)', 'ovr' => 50],
    ['name' => 'Sasha Vezenkov', 'position' => 'PF', 'country' => 'Bulgaria', 'school' => 'FC Barcelona Lassa (Spain)', 'ovr' => 49],
    ['name' => 'Ognjen Jaramaz', 'position' => 'PG', 'country' => 'Serbia', 'school' => 'Mega Leks (Serbia)', 'ovr' => 48],
    ['name' => 'Jaron Blossomgame', 'position' => 'SF', 'country' => 'United States', 'school' => 'Clemson (Sr.)', 'ovr' => 47],
    ['name' => 'Alpha Kaba', 'position' => 'PF/C', 'country' => 'Guinea', 'school' => 'Mega Leks (Serbia)', 'ovr' => 45],
];

function getOvrColor($ovr) {
    if ($ovr >= 95) return '#00ff00';
    if ($ovr >= 90) return '#80ff00';
    if ($ovr >= 85) return '#ffff00';
    if ($ovr >= 80) return '#ff9900';
    if ($ovr >= 70) return '#ff6600';
    return '#ff3333';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Próximo Draft - FBA Manager</title>
    
    <!-- PWA Meta Tags -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0a0a0c">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/icon-192.png">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css" />
</head>
<body>
    <!-- Sidebar -->
    <div class="dashboard-sidebar">
        <div class="text-center mb-4">
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" 
                 alt="<?= htmlspecialchars($team['name']) ?>" class="team-avatar">
            <h5 class="text-white mb-1"><?= htmlspecialchars($team['city'] . ' ' . $team['name']) ?></h5>
            <span class="badge bg-gradient-orange"><?= htmlspecialchars($user['league']) ?></span>
        </div>

        <hr style="border-color: var(--fba-border);">

        <ul class="sidebar-menu">
            <li>
                <a href="/dashboard.php">
                    <i class="bi bi-house-door-fill"></i>
                    Dashboard
                </a>
            </li>
            <li>
                <a href="/teams.php">
                    <i class="bi bi-people-fill"></i>
                    Times
                </a>
            </li>
            <li>
                <a href="/my-roster.php">
                    <i class="bi bi-person-fill"></i>
                    Meu Elenco
                </a>
            </li>
            <li>
                <a href="/picks.php">
                    <i class="bi bi-calendar-check-fill"></i>
                    Picks
                </a>
            </li>
            <li>
                <a href="/settings.php">
                    <i class="bi bi-gear-fill"></i>
                    Configurações
                </a>
            </li>
        </ul>

        <hr style="border-color: var(--fba-border);">

        <div class="text-center">
            <a href="/logout.php" class="btn btn-outline-danger btn-sm w-100">
                <i class="bi bi-box-arrow-right me-2"></i>Sair
            </a>
        </div>

        <div class="text-center mt-3">
            <small class="text-light-gray">
                <i class="bi bi-person-circle me-1"></i>
                <?= htmlspecialchars($user['name']) ?>
            </small>
        </div>
    </div>

    <!-- Main Content -->
    <div class="dashboard-content">
        <div class="mb-4">
            <h1 class="text-white fw-bold mb-2">Próximo Draft</h1>
            <p class="text-light-gray">Confira a lista de jogadores disponíveis para o draft</p>
        </div>

        <!-- Search and Filter -->
        <div class="row mb-4 g-3">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-dark-panel border-orange">
                        <i class="bi bi-search text-orange"></i>
                    </span>
                    <input type="text" class="form-control bg-dark-panel border-orange text-white" 
                           id="searchInput" placeholder="Buscar jogador...">
                </div>
            </div>
            <div class="col-md-6">
                <select class="form-select bg-dark-panel border-orange text-white" id="positionFilter">
                    <option value="">Todas as posições</option>
                    <option value="PG">Point Guard (PG)</option>
                    <option value="SG">Shooting Guard (SG)</option>
                    <option value="SF">Small Forward (SF)</option>
                    <option value="PF">Power Forward (PF)</option>
                    <option value="C">Center (C)</option>
                </select>
            </div>
        </div>

        <!-- Players Table -->
        <div class="table-responsive">
            <table class="table table-dark table-hover mb-0">
                <thead style="background: linear-gradient(135deg, #f17507, #ff8c1a);">
                    <tr>
                        <th class="text-white fw-bold">#</th>
                        <th class="text-white fw-bold">Jogador</th>
                        <th class="text-white fw-bold">Posição</th>
                        <th class="text-white fw-bold">Escola</th>
                        <th class="text-white fw-bold">País</th>
                    </tr>
                </thead>
                <tbody id="playersTableBody">
                    <?php foreach ($draftPlayers as $index => $player): ?>
                        <tr class="player-row" data-player='<?= json_encode($player) ?>'>
                            <td class="text-orange fw-bold"><?= $index + 1 ?></td>
                            <td class="fw-bold"><?= htmlspecialchars($player['name']) ?></td>
                            <td><span class="badge bg-orange"><?= htmlspecialchars($player['position']) ?></span></td>
                            <td class="text-light-gray"><?= htmlspecialchars($player['school']) ?></td>
                            <td class="text-light-gray"><?= htmlspecialchars($player['country']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            filterPlayers();
        });

        document.getElementById('positionFilter').addEventListener('change', function() {
            filterPlayers();
        });

        function filterPlayers() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const positionFilter = document.getElementById('positionFilter').value;
            const rows = document.querySelectorAll('#playersTableBody tr');

            rows.forEach(row => {
                const playerData = JSON.parse(row.dataset.player);
                const playerName = playerData.name.toLowerCase();
                const positions = playerData.position.split('/');
                
                const matchesSearch = playerName.includes(searchTerm);
                const matchesPosition = !positionFilter || positions.some(pos => pos.trim() === positionFilter);
                
                row.style.display = (matchesSearch && matchesPosition) ? '' : 'none';
            });
        }
    </script>
    <script src="/js/pwa.js"></script>
</body>
</html>
