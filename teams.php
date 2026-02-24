<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();

$stmtSettings = $pdo->prepare('SELECT cap_min, cap_max, max_trades FROM league_settings WHERE league = ?');
$stmtSettings->execute([$user['league']]);
$leagueSettings = $stmtSettings->fetch(PDO::FETCH_ASSOC) ?: ['cap_min' => 0, 'cap_max' => 0, 'max_trades' => 3];
$capMin = (int)($leagueSettings['cap_min'] ?? 0);
$capMax = (int)($leagueSettings['cap_max'] ?? 0);
$maxTrades = (int)($leagueSettings['max_trades'] ?? 3);

$currentSeasonYear = null;
try {
    $stmtSeason = $pdo->prepare('
        SELECT s.season_number, s.year, sp.start_year
        FROM seasons s
        INNER JOIN sprints sp ON s.sprint_id = sp.id
        WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN (\'completed\'))
        ORDER BY s.created_at DESC
        LIMIT 1
    ');
    $stmtSeason->execute([$user['league']]);
    $season = $stmtSeason->fetch(PDO::FETCH_ASSOC);
    if ($season) {
        if (isset($season['start_year'], $season['season_number'])) {
            $currentSeasonYear = (int)$season['start_year'] + (int)$season['season_number'] - 1;
        } elseif (isset($season['year'])) {
            $currentSeasonYear = (int)$season['year'];
        }
    }
} catch (Exception $e) {
    $currentSeasonYear = null;
}
$currentSeasonYear = $currentSeasonYear ?: (int)date('Y');

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
    SELECT t.id, t.city, t.name, t.mascot, t.photo_url, t.user_id, t.tapas,
             u.name AS owner_name, u.phone AS owner_phone,
             (SELECT COUNT(*) FROM team_punishments tp WHERE tp.team_id = t.id AND tp.reverted_at IS NULL) as punicoes_count
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                <a href="/leilao.php">
                    <i class="bi bi-hammer"></i>
                    Leilão
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

        <!-- Busca de Time -->
        <div class="card bg-dark-panel border-orange mb-4">
            <div class="card-header bg-dark border-bottom border-orange">
                <h5 class="mb-0 text-white"><i class="bi bi-search text-orange me-2"></i>Buscar Time</h5>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-12">
                        <input type="text" id="teamSearchInput" class="form-control" placeholder="🔍 Digite o nome do time ou cidade...">
                    </div>
                </div>
            </div>
        </div>

        <div class="card bg-dark-panel border-orange">
            <div class="card-body p-0">
                <div class="table-responsive d-none d-md-block" id="teamsTableWrap">
                    <table class="table table-dark table-hover mb-0" id="teamsTable">
                        <thead style="background: linear-gradient(135deg, #f17507, #ff8c1a);">
                            <tr>
                                <th class="text-white fw-bold" style="width: 60px;">Logo</th>
                                <th class="text-white fw-bold">Time</th>
                                <th class="text-white fw-bold hide-mobile">Proprietário</th>
                                <th class="text-white fw-bold text-center">Jog.</th>
                                <th class="text-white fw-bold text-center hide-mobile">CAP</th>
                                <th class="text-white fw-bold text-center">Tapas</th>
                                <th class="text-white fw-bold text-center">Punições</th>
                                <th class="text-white fw-bold text-center" style="width: 100px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="teamsTableBody">
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
                                        <small class="text-light-gray d-block mt-1">
                                            <i class="bi bi-graph-up me-1 text-orange"></i>CAP: <?= number_format($t['cap_top8'], 0, ',', '.') ?>
                                        </small>
                                        <small class="text-light-gray d-block">
                                            <i class="bi bi-hand-index-thumb me-1 text-warning"></i>Tapas: <?= (int)($t['tapas'] ?? 0) ?>
                                        </small>
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
                                    <div class="d-md-none mt-2">
                                        <span class="badge bg-warning text-dark">CAP <?= $t['cap_top8'] ?></span>
                                    </div>
                                </td>
                                <td class="text-center hide-mobile">
                                    <span class="badge bg-gradient-orange"><?= $t['cap_top8'] ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-warning text-dark"><?= (int)($t['tapas'] ?? 0) ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?= (int)($t['punicoes_count'] ?? 0) ?></span>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2">
                                        <button class="btn btn-sm btn-orange" onclick="verJogadores(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['city'] . ' ' . $t['name'])) ?>')">
                                            <i class="bi bi-eye"></i><span class="d-none d-md-inline ms-1">Ver</span>
                                        </button>
                                        <button class="btn btn-sm btn-outline-light" onclick="copiarTime(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['city'] . ' ' . $t['name'])) ?>')" title="Copiar time">
                                            <i class="bi bi-clipboard-check"></i><span class="d-none d-md-inline ms-1">Copiar</span>
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
                <div id="teamsCardsWrap" class="teams-mobile-cards d-block d-md-none">
                    <?php foreach ($teams as $t): ?>
                    <?php
                        $hasContact = !empty($t['owner_phone_whatsapp']);
                        $whatsAppLink = $hasContact
                            ? 'https://api.whatsapp.com/send/?phone=' . rawurlencode($t['owner_phone_whatsapp']) . "&text={$whatsappDefaultMessage}&type=phone_number&app_absent=0"
                            : null;
                        $searchKey = strtolower(trim(
                            ($t['city'] ?? '') . ' ' .
                            ($t['name'] ?? '') . ' ' .
                            ($t['owner_name'] ?? '') . ' ' .
                            ($t['owner_phone_display'] ?? '')
                        ));
                    ?>
                    <div class="team-card-mobile" data-search="<?= htmlspecialchars($searchKey) ?>" style="margin-bottom: 18px; border: 1px solid rgba(255,255,255,0.12); border-radius: 14px; padding: 16px; background: rgba(20,20,20,1);">
                        <div class="team-card-mobile-header" style="align-items: center;">
                            <div class="team-card-mobile-logo-wrap" style="width: 70px; height: 70px;">
                                <img src="<?= htmlspecialchars($t['photo_url'] ?? '/img/default-team.png') ?>" 
                                     alt="<?= htmlspecialchars($t['name']) ?>" 
                                     class="team-card-mobile-logo"
                                     style="width:60px;height:60px;max-width:60px;max-height:60px;object-fit:cover;">
                            </div>
                            <div class="team-card-mobile-title" style="color: #ffffff;">
                                <div class="team-card-mobile-name" style="color: #fc0025; font-size: 1.2rem; font-weight: 800;">
                                    <?= htmlspecialchars($t['city'] . ' ' . $t['name']) ?>
                                </div>
                            </div>
                        </div>
                        <ul class="team-card-mobile-info" style="color: #ffffff;">
                            <li><i class="bi bi-person me-1"></i><?= htmlspecialchars($t['owner_name']) ?></li>
                            <?php if ($hasContact): ?>
                                <li><i class="bi bi-whatsapp me-1 text-success"></i><?= htmlspecialchars($t['owner_phone_display']) ?></li>
                            <?php else: ?>
                                <li>Sem contato</li>
                            <?php endif; ?>
                        </ul>
                        <div class="team-card-mobile-divider"></div>
                        <div class="team-card-mobile-stats">
                            <div class="team-card-mobile-stat">
                                <span class="stat-label">Jogadores</span>
                                <span class="stat-value text-orange"><?= (int)$t['total_players'] ?></span>
                            </div>
                            <div class="team-card-mobile-stat">
                                <span class="stat-label">CAP</span>
                                <span class="stat-value text-warning"><?= (int)$t['cap_top8'] ?></span>
                            </div>
                            <div class="team-card-mobile-stat">
                                <span class="stat-label">Punições</span>
                                <span class="stat-value"><?= (int)($t['punicoes_count'] ?? 0) ?></span>
                            </div>
                            <div class="team-card-mobile-stat">
                                <span class="stat-label">Tapas</span>
                                <span class="stat-value text-warning"><?= (int)($t['tapas'] ?? 0) ?></span>
                            </div>
                        </div>
                        <div class="team-card-mobile-actions">
                            <button class="btn btn-orange" onclick="verJogadores(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['city'] . ' ' . $t['name'])) ?>')">
                                <i class="bi bi-eye"></i> Ver
                            </button>
                            <button class="btn btn-outline-light" onclick="copiarTime(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['city'] . ' ' . $t['name'])) ?>')">
                                <i class="bi bi-clipboard-check"></i> Copiar
                            </button>
                            <?php if ($hasContact): ?>
                                <a class="btn btn-outline-success" href="<?= htmlspecialchars($whatsAppLink) ?>" target="_blank" rel="noopener">
                                    <i class="bi bi-whatsapp"></i> Falar
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div id="teamsCardsEmpty" class="text-center text-light-gray py-3" style="display: none;">
                        <i class="bi bi-search me-2"></i>Nenhum time encontrado
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="playersModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
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
                        <div class="table-responsive">
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
    </div>

    <div class="modal fade" id="copyTeamModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark-panel border-orange">
                <div class="modal-header border-orange">
                    <h5 class="modal-title text-white"><i class="bi bi-clipboard-check me-2 text-orange"></i>Copiar Time</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-light-gray mb-2">Toque e segure para copiar o texto.</p>
                    <textarea id="copyTeamTextarea" class="form-control bg-dark text-white border-orange" rows="8" readonly></textarea>
                </div>
                <div class="modal-footer border-orange">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/sidebar.js"></script>
    <script>
        const leagueCapMin = <?= (int)$capMin ?>;
        const leagueCapMax = <?= (int)$capMax ?>;
        const leagueMaxTrades = <?= (int)$maxTrades ?>;
        const currentSeasonYear = <?= $currentSeasonYear ? (int)$currentSeasonYear : 'null' ?>;

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
                            // Lógica para puxar a foto da NBA ou o Avatar de Iniciais
                            const photoUrl = p.nba_player_id 
                                ? `https://cdn.nba.com/headshots/nba/latest/1040x760/${p.nba_player_id}.png` 
                                : `https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=121212&color=f17507&rounded=true&bold=true`;

                            tbody.innerHTML += `<tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <img src="${photoUrl}" alt="${p.name}" 
                                             style="width: 32px; height: 32px; object-fit: cover; border-radius: 50%; border: 1px solid var(--fba-orange); background: #1a1a1a;"
                                             onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(p.name)}&background=121212&color=f17507&rounded=true&bold=true'">
                                        <strong>${p.name}</strong>
                                    </div>
                                </td>
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

        async function copiarTime(teamId, teamName) {
            try {
                const [teamRes, playersRes, picksRes] = await Promise.all([
                    fetch(`/api/team.php?id=${teamId}`),
                    fetch(`/api/team-players.php?team_id=${teamId}`),
                    fetch(`/api/picks.php?team_id=${teamId}`)
                ]);

                const teamData = await teamRes.json();
                const playersData = await playersRes.json();
                const picksData = await picksRes.json();

                if (!teamData || teamData.error) throw new Error(teamData.error || 'Erro ao carregar time');
                if (!playersData || playersData.error) throw new Error(playersData.error || 'Erro ao carregar jogadores');
                if (!picksData || picksData.error) throw new Error(picksData.error || 'Erro ao carregar picks');

                const teamInfo = (teamData.teams && teamData.teams[0]) ? teamData.teams[0] : null;
                if (!teamInfo) throw new Error('Time não encontrado');

                const roster = playersData.players || [];
                let picks = picksData.picks || [];
                picks = picks.filter(pk => Number(pk.season_year) > Number(currentSeasonYear));

                const positions = ['PG','SG','SF','PF','C'];
                const startersMap = {};
                positions.forEach(pos => startersMap[pos] = null);

                const formatAge = (age) => (Number.isFinite(age) && age > 0) ? `${age}y` : '-';

                const formatLine = (label, player) => {
                    if (!player) return `${label}: -`;
                    const ovr = player.ovr ?? '-';
                    const age = player.age ?? '-';
                    return `${label}: ${player.name} - ${ovr} | ${formatAge(age)}`;
                };

                const starters = roster.filter(p => p.role === 'Titular');
                starters.forEach(p => {
                    if (positions.includes(p.position) && !startersMap[p.position]) {
                        startersMap[p.position] = p;
                    }
                });

                const benchPlayers = roster.filter(p => p.role === 'Banco');
                const othersPlayers = roster.filter(p => p.role === 'Outro');
                const gleaguePlayers = roster.filter(p => (p.role || '').toLowerCase() === 'g-league');

                const round1Years = picks.filter(pk => pk.round == 1).map(pk => {
                    const isTraded = (pk.original_team_id != pk.team_id);
                    const via = pk.last_owner_city && pk.last_owner_name ? `${pk.last_owner_city} ${pk.last_owner_name}` : `${pk.original_team_city} ${pk.original_team_name}`;
                    return `-${pk.season_year}${isTraded ? ` (via ${via})` : ''} `;
                });
                const round2Years = picks.filter(pk => pk.round == 2).map(pk => {
                    const isTraded = (pk.original_team_id != pk.team_id);
                    const via = pk.last_owner_city && pk.last_owner_name ? `${pk.last_owner_city} ${pk.last_owner_name}` : `${pk.original_team_city} ${pk.original_team_name}`;
                    return `-${pk.season_year}${isTraded ? ` (via ${via})` : ''} `;
                });

                const lines = [];
                lines.push(`*${teamName}*`);
                lines.push(teamInfo.owner_name || '-');
                lines.push('');
                lines.push('_Starters_');
                positions.forEach(pos => lines.push(formatLine(pos, startersMap[pos])));
                lines.push('');
                lines.push('_Bench_');
                if (benchPlayers.length) {
                    benchPlayers.forEach(p => {
                        const ovr = p.ovr ?? '-';
                        const age = p.age ?? '-';
                        lines.push(`${p.position}: ${p.name} - ${ovr} | ${formatAge(age)}`);
                    });
                } else {
                    lines.push('-');
                }
                lines.push('');
                lines.push('_Others_');
                if (othersPlayers.length) {
                    othersPlayers.forEach(p => {
                        const ovr = p.ovr ?? '-';
                        const age = p.age ?? '-';
                        lines.push(`${p.position}: ${p.name} - ${ovr} | ${formatAge(age)}`);
                    });
                } else {
                    lines.push('-');
                }
                lines.push('');
                lines.push('_G-League_');
                if (gleaguePlayers.length) {
                    gleaguePlayers.forEach(p => {
                        const ovr = p.ovr ?? '-';
                        const age = p.age ?? '-';
                        lines.push(`${p.position}: ${p.name} - ${ovr} | ${formatAge(age)}`);
                    });
                } else {
                    lines.push('-');
                }
                lines.push('');
                lines.push('_Picks 1º round_:');
                lines.push(...(round1Years.length ? round1Years : ['-']));
                lines.push('');
                lines.push('_Picks 2º round_:');
                lines.push(...(round2Years.length ? round2Years : ['-']));
                lines.push('');
                const capTop8 = teamInfo.cap_top8 ?? 0;
                const tradesUsed = teamInfo.trades_used ?? 0;
                lines.push(`_CAP_: ${leagueCapMin} / *${capTop8}* / ${leagueCapMax}`);
                lines.push(`_Trades_: ${tradesUsed} / ${leagueMaxTrades}`);

                const text = lines.join('\n');
                try {
                    await navigator.clipboard.writeText(text);
                    alert('Time copiado para a área de transferência!');
                } catch (err) {
                    showCopyFallback(text);
                }
            } catch (err) {
                alert(err.message || 'Erro ao copiar time');
            }
        }

        function showCopyFallback(text) {
            const textarea = document.getElementById('copyTeamTextarea');
            const modalEl = document.getElementById('copyTeamModal');
            if (!textarea || !modalEl) return;
            textarea.value = text;
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
            setTimeout(() => {
                textarea.focus();
                textarea.select();
            }, 150);
        }

        // === Busca de Times ===
        const teamSearchInput = document.getElementById('teamSearchInput');
        const teamsTableBody = document.getElementById('teamsTableBody');
        const teamsCardsWrap = document.getElementById('teamsCardsWrap');
        const teamsCardsEmpty = document.getElementById('teamsCardsEmpty');

        if (teamSearchInput && teamsTableBody) {
            teamSearchInput.addEventListener('input', function() {
                const term = this.value.toLowerCase().trim();
                const rows = teamsTableBody.querySelectorAll('tr');
                let visibleCount = 0;
                
                rows.forEach(row => {
                    // Busca em todo o conteúdo da linha (time, cidade, proprietário)
                    const rowText = row.textContent.toLowerCase();
                    
                    if (term === '' || rowText.includes(term)) {
                        row.style.display = '';
                        visibleCount++;
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                // Feedback visual se não encontrar nada
                if (visibleCount === 0 && term !== '') {
                    // Verifica se já existe mensagem de "não encontrado"
                    let noResultsRow = teamsTableBody.querySelector('.no-results-row');
                    if (!noResultsRow) {
                        noResultsRow = document.createElement('tr');
                        noResultsRow.className = 'no-results-row';
                        noResultsRow.innerHTML = '<td colspan="6" class="text-center text-light-gray py-3"><i class="bi bi-search me-2"></i>Nenhum time encontrado</td>';
                        teamsTableBody.appendChild(noResultsRow);
                    }
                    noResultsRow.style.display = '';
                } else {
                    // Remove mensagem se existir
                    const noResultsRow = teamsTableBody.querySelector('.no-results-row');
                    if (noResultsRow) noResultsRow.style.display = 'none';
                }

                if (teamsCardsWrap) {
                    const cards = teamsCardsWrap.querySelectorAll('.team-card-mobile');
                    let cardsVisible = 0;
                    cards.forEach(card => {
                        const searchText = (card.getAttribute('data-search') || '').toLowerCase();
                        if (term === '' || searchText.includes(term)) {
                            card.style.display = '';
                            cardsVisible++;
                        } else {
                            card.style.display = 'none';
                        }
                    });

                    if (teamsCardsEmpty) {
                        teamsCardsEmpty.style.display = (cardsVisible === 0 && term !== '') ? '' : 'none';
                    }
                }
            });
        }
    </script>
    <script src="/js/pwa.js"></script>
</body>
</html>
