<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();

// Buscar time do usuário para a sidebar
$stmtTeam = $pdo->prepare('
    SELECT t.*, COUNT(p.id) as player_count
    FROM teams t
    LEFT JOIN players p ON p.team_id = t.id
    WHERE t.user_id = ?
    GROUP BY t.id
    ORDER BY player_count DESC, t.id DESC
');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

$stmt = $pdo->prepare('
        SELECT t.id, t.city, t.name, t.mascot, t.photo_url, t.user_id,
                     u.name AS owner_name, u.phone AS owner_phone
        FROM teams t
        INNER JOIN users u ON u.id = t.user_id
        WHERE t.league = ?
        ORDER BY t.id ASC
');
$stmt->execute([$user['league']]);
$teams = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($teams as &$t) {
        if (empty($t['owner_name'])) {
                $t['owner_name'] = 'N/A';
        }

        $rawPhone = $t['owner_phone'] ?? '';
        $normalizedPhone = $rawPhone !== '' ? normalizeBrazilianPhone($rawPhone) : null;
        if (!$normalizedPhone && $rawPhone !== '') {
                $digits = preg_replace('/\D+/', '', $rawPhone);
                if ($digits !== '') {
                        $normalizedPhone = str_starts_with($digits, '55') ? $digits : '55' . $digits;
                }
        }
        $t['owner_phone_display'] = $rawPhone !== '' ? formatBrazilianPhone($rawPhone) : null;
        $t['owner_phone_whatsapp'] = $normalizedPhone;

        $playerStmt = $pdo->prepare('SELECT COUNT(*) as cnt FROM players WHERE team_id = ?');
        $playerStmt->execute([$t['id']]);
        $t['total_players'] = (int)$playerStmt->fetch()['cnt'];

        $capStmt = $pdo->prepare('SELECT SUM(ovr) as cap FROM (SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8) as top8');
        $capStmt->execute([$t['id']]);
        $capResult = $capStmt->fetch();
        $t['cap_top8'] = (int)($capResult['cap'] ?? 0);
}
unset($t); // Importante: limpar referência do foreach

$whatsappDefaultMessage = rawurlencode('Olá! Podemos conversar sobre nossas franquias na FBA?');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Times - FBA Manager</title>
    
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
    <link rel="stylesheet" href="/css/styles.css">
</head>
<body>
    <!-- Botão Hamburguer para Mobile -->
    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="bi bi-list fs-4"></i>
    </button>
    
    <!-- Overlay para fechar sidebar no mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="dashboard-sidebar" id="sidebar">
        <div class="text-center mb-4">
            <img src="<?= htmlspecialchars($team['photo_url'] ?? '/img/default-team.png') ?>" 
                 alt="<?= htmlspecialchars($team['name'] ?? 'Time') ?>" class="team-avatar">
            <h5 class="text-white mb-1"><?= htmlspecialchars(($team['city'] ?? '') . ' ' . ($team['name'] ?? 'Sem time')) ?></h5>
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
                <a href="/teams.php" class="active">
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
                <a href="/trades.php">
                    <i class="bi bi-arrow-left-right"></i>
                    Trades
                </a>
            </li>
            <li>
                <a href="/free-agency.php">
                    <i class="bi bi-coin"></i>
                    Free Agency
                </a>
            </li>
            <li>
                <a href="/drafts.php">
                    <i class="bi bi-trophy"></i>
                    Draft
                </a>
            </li>
            <li>
                <a href="/rankings.php">
                    <i class="bi bi-bar-chart-fill"></i>
                    Rankings
                </a>
            </li>
            <li>
                <a href="/history.php">
                    <i class="bi bi-clock-history"></i>
                    Histórico
                </a>
            </li>
            <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
            <li>
                <a href="/admin.php">
                    <i class="bi bi-shield-lock-fill"></i>
                    Admin
                </a>
            </li>
            <li>
                <a href="/temporadas.php">
                    <i class="bi bi-calendar3"></i>
                    Temporadas
                </a>
            </li>
            <?php endif; ?>
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
        <h1 class="display-5 fw-bold mb-4">
            <i class="bi bi-people-fill text-orange me-2"></i>Times da Liga
        </h1>

        <div class="card bg-dark-panel border-orange mb-4">
            <div class="card-header bg-dark border-bottom border-orange">
                <h5 class="mb-0 text-white"><i class="bi bi-search text-orange me-2"></i>Buscar Jogador</h5>
            </div>
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label for="playerSearchInput" class="form-label">Nome do jogador</label>
                        <input type="text" id="playerSearchInput" class="form-control" placeholder="Digite pelo menos 2 letras">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-orange w-100" id="playerSearchBtn">
                            <i class="bi bi-search me-1"></i>Buscar
                        </button>
                    </div>
                </div>
                <div class="mt-3" id="playerSearchResults" style="display:none;">
                    <div class="table-responsive">
                        <table class="table table-dark table-hover mb-0">
                            <thead style="background: var(--fba-orange); color: #000;">
                                <tr>
                                    <th>Jogador</th>
                                    <th>OVR</th>
                                    <th>Idade</th>
                                    <th>Time</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody id="playerSearchList"></tbody>
                        </table>
                    </div>
                </div>
                <div class="text-light-gray mt-2" id="playerSearchEmpty" style="display:none;">
                    Nenhum jogador encontrado.
                </div>
            </div>
        </div>

        <div class="card bg-dark-panel border-orange">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead style="background: linear-gradient(135deg, #f17507, #ff8c1a);">
                            <tr>
                                <th class="text-white fw-bold" style="width: 60px;">Logo</th>
                                <th class="text-white fw-bold">Time</th>
                                <th class="text-white fw-bold hide-mobile">Proprietário</th>
                                <th class="text-white fw-bold text-center">Jog.</th>
                                <th class="text-white fw-bold text-center hide-mobile">CAP</th>
                                <th class="text-white fw-bold text-center" style="width: 100px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teams as $t): ?>
                            <?php
                                $hasContact = !empty($t['owner_phone_whatsapp']);
                                $whatsAppLink = $hasContact
                                    ? 'https://api.whatsapp.com/send/?phone=' . rawurlencode($t['owner_phone_whatsapp']) . "&text={$whatsappDefaultMessage}&type=phone_number&app_absent=0"
                                    : null;
                            ?>
                            <tr>
                                <td>
                                    <img src="<?= htmlspecialchars($t['photo_url'] ?? '/img/default-team.png') ?>" 
                                         alt="<?= htmlspecialchars($t['name']) ?>" 
                                         style="width: 40px; height: 40px; object-fit: cover; border-radius: 8px; border: 2px solid var(--fba-orange);">
                                </td>
                                <td>
                                    <div class="fw-bold text-orange" style="font-size: 0.9rem;"><?= htmlspecialchars($t['city'] . ' ' . $t['name']) ?></div>
                                    <div class="d-md-none mt-1">
                                        <small class="text-light-gray d-block">
                                            <i class="bi bi-person me-1"></i><?= htmlspecialchars($t['owner_name']) ?>
                                        </small>
                                        <?php if ($hasContact): ?>
                                            <small class="text-light-gray d-block">
                                                <i class="bi bi-whatsapp me-1 text-success"></i><?= htmlspecialchars($t['owner_phone_display']) ?>
                                            </small>
                                        <?php else: ?>
                                            <small class="text-muted">Sem contato</small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="hide-mobile">
                                    <div><i class="bi bi-person me-1 text-orange"></i><?= htmlspecialchars($t['owner_name']) ?></div>
                                    <?php if ($hasContact): ?>
                                        <small class="text-light-gray d-block"><i class="bi bi-whatsapp me-1 text-success"></i><?= htmlspecialchars($t['owner_phone_display']) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">Sem contato</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-gradient-orange"><?= $t['total_players'] ?></span>
                                </td>
                                <td class="text-center hide-mobile">
                                    <span class="badge bg-gradient-orange"><?= $t['cap_top8'] ?></span>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2">
                                        <button class="btn btn-sm btn-orange" onclick="verJogadores(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['city'] . ' ' . $t['name'])) ?>')">
                                            <i class="bi bi-eye"></i><span class="d-none d-md-inline ms-1">Ver</span>
                                        </button>
                                        <?php if ($hasContact): ?>
                                            <a class="btn btn-sm btn-outline-success" href="<?= htmlspecialchars($whatsAppLink) ?>" target="_blank" rel="noopener">
                                                <i class="bi bi-whatsapp"></i><span class="d-none d-md-inline ms-1">Falar</span>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="playersModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark-panel border-orange">
                <div class="modal-header border-orange">
                    <h5 class="modal-title text-white" id="modalTitle"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="loading" class="text-center py-4">
                        <div class="spinner-border text-orange" role="status"></div>
                    </div>
                    <div id="content" style="display: none;">
                        <table class="table table-dark table-hover mb-0">
                            <thead style="background: var(--fba-orange); color: #000;">
                                <tr>
                                    <th>Jogador</th>
                                    <th>OVR</th>
                                    <th>Idade</th>
                                    <th>Posição</th>
                                    <th>Função</th>
                                </tr>
                            </thead>
                            <tbody id="playersList"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/sidebar.js"></script>
    <script>
        async function verJogadores(teamId, teamName) {
            document.getElementById('modalTitle').textContent = 'Elenco: ' + teamName;
            document.getElementById('loading').style.display = 'block';
            document.getElementById('content').style.display = 'none';
            
            const modal = new bootstrap.Modal(document.getElementById('playersModal'));
            modal.show();

            try {
                const res = await fetch(`/api/team-players.php?team_id=${teamId}`);
                const data = await res.json();

                if (data.success && data.players) {
                    const tbody = document.getElementById('playersList');
                    tbody.innerHTML = '';

                    if (data.players.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Nenhum jogador</td></tr>';
                    } else {
                        data.players.forEach(p => {
                            tbody.innerHTML += `<tr>
                                <td><strong>${p.name}</strong></td>
                                <td><span class="badge bg-warning text-dark">${p.ovr}</span></td>
                                <td>${p.age}</td>
                                <td>${p.position}</td>
                                <td>${p.role}</td>
                            </tr>`;
                        });
                    }

                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('content').style.display = 'block';
                }
            } catch (err) {
                document.getElementById('loading').innerHTML = '<div class="alert alert-danger">Erro ao carregar</div>';
            }
        }

        const searchInput = document.getElementById('playerSearchInput');
        const searchBtn = document.getElementById('playerSearchBtn');
        const searchResults = document.getElementById('playerSearchResults');
        const searchList = document.getElementById('playerSearchList');
        const searchEmpty = document.getElementById('playerSearchEmpty');
        const defaultMessage = '<?= $whatsappDefaultMessage ?>';

        async function buscarJogador() {
            const term = searchInput.value.trim();
            searchResults.style.display = 'none';
            searchEmpty.style.display = 'none';
            searchList.innerHTML = '';

            if (term.length < 2) {
                return;
            }

            try {
                const res = await fetch(`/api/team.php?action=search_player&query=${encodeURIComponent(term)}`);
                const data = await res.json();
                const players = data.players || [];

                if (!players.length) {
                    searchEmpty.style.display = 'block';
                    return;
                }

                players.forEach(p => {
                    const teamName = `${p.city} ${p.team_name}`;
                    const whatsappLink = p.owner_phone_whatsapp
                        ? `https://api.whatsapp.com/send/?phone=${encodeURIComponent(p.owner_phone_whatsapp)}&text=${defaultMessage}&type=phone_number&app_absent=0`
                        : '';
                    searchList.innerHTML += `
                        <tr>
                            <td><strong>${p.name}</strong></td>
                            <td><span class="badge bg-warning text-dark">${p.ovr}</span></td>
                            <td>${p.age}</td>
                            <td>${teamName}</td>
                            <td>
                                ${whatsappLink ? `
                                <a class="btn btn-sm btn-outline-success" href="${whatsappLink}" target="_blank" rel="noopener">
                                    <i class="bi bi-whatsapp"></i> Falar
                                </a>` : '<span class="text-muted">Sem contato</span>'}
                            </td>
                        </tr>
                    `;
                });

                searchResults.style.display = 'block';
            } catch (err) {
                searchEmpty.textContent = 'Erro ao buscar jogadores.';
                searchEmpty.style.display = 'block';
            }
        }

        searchBtn.addEventListener('click', buscarJogador);
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') buscarJogador();
        });
    </script>
    <script src="/js/pwa.js"></script>
</body>
</html>
