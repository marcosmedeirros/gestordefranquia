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

// Buscar prazo ativo
$stmtDeadline = $pdo->prepare("
    SELECT * FROM directive_deadlines 
    WHERE league = ? AND is_active = 1 
    ORDER BY deadline_date DESC LIMIT 1
");
$stmtDeadline->execute([$team['league']]);
$deadline = $stmtDeadline->fetch();

$deadlineDisplay = null;
$deadlineIso = null;
if ($deadline && !empty($deadline['deadline_date'])) {
    try {
        $deadlineDateTime = new DateTime($deadline['deadline_date'], new DateTimeZone('America/Sao_Paulo'));
        $deadlineDisplay = $deadlineDateTime->format('d/m/Y \à\s H:i');
        $deadlineIso = $deadlineDateTime->format(DateTime::ATOM);
    } catch (Exception $e) {
        $deadlineDisplay = date('d/m/Y', strtotime($deadline['deadline_date']));
    }
}

// Buscar jogadores do time
$stmtPlayers = $pdo->prepare("
    SELECT id, name, position, ovr, role 
    FROM players 
    WHERE team_id = ? 
    ORDER BY ovr DESC
");
$stmtPlayers->execute([$team['id']]);
$players = $stmtPlayers->fetchAll();

// Contar jogadores para regras da G-League
$playerCount = count($players);
// G-League: Se time tem 15+ jogadores, pode mandar 2; se tem 14, pode mandar 1
$gleagueSlots = 0;
if ($playerCount >= 15) {
    $gleagueSlots = 2;
} elseif ($playerCount >= 14) {
    $gleagueSlots = 1;
}

// Buscar temporada atual da liga (para validar minutagem regular vs playoffs)
$currentSeason = null;
try {
    $stmtSeason = $pdo->prepare("
        SELECT season_number, year, status 
        FROM seasons 
        WHERE league = ? AND status IN ('draft', 'regular', 'playoffs')
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmtSeason->execute([$team['league']]);
    $currentSeason = $stmtSeason->fetch();
} catch (Exception $e) {
    $currentSeason = null;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <?php include __DIR__ . '/includes/head-pwa.php'; ?>
    <title>Enviar Diretrizes - FBA Manager</title>
    
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
            <li><a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a></li>
            <li><a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a></li>
            <li><a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a></li>
            <li><a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a></li>
            <li><a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a></li>
            <li><a href="/free-agency.php"><i class="bi bi-person-plus-fill"></i> Free Agency</a></li>
            <li><a href="/diretrizes.php" class="active"><i class="bi bi-clipboard-check"></i> Diretrizes</a></li>
            <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
            <li><a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a></li>
            <li><a href="/temporadas.php"><i class="bi bi-calendar3"></i> Temporadas</a></li>
            <?php endif; ?>
            <li><a href="/settings.php"><i class="bi bi-gear-fill"></i> Configurações</a></li>
        </ul>

        <hr style="border-color: var(--fba-border);">

        <div class="text-center">
            <a href="/logout.php" class="btn btn-outline-danger btn-sm w-100">
                <i class="bi bi-box-arrow-right me-2"></i>Sair
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="dashboard-content">
        <div class="mb-4">
            <h1 class="text-white fw-bold"><i class="bi bi-clipboard-check me-2 text-orange"></i>Diretrizes de Jogo</h1>
            <p class="text-light-gray">Configure seu quinteto titular, banco e estratégias de jogo</p>
        </div>

        <?php if (!$deadline): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Não há prazo ativo para envio de diretrizes no momento.
        </div>
        <?php else: ?>
        <div class="alert alert-info bg-dark-panel border-orange mb-4">
            <div class="d-flex align-items-center">
                <i class="bi bi-calendar-event text-orange me-3 fs-3"></i>
                <div>
                    <h5 class="text-white mb-1">Prazo de Envio</h5>
                    <p class="text-light-gray mb-0">
                        <?= htmlspecialchars($deadline['description'] ?? 'Envio de diretrizes') ?> - 
                        <strong>Até <?= htmlspecialchars($deadlineDisplay ?? date('d/m/Y', strtotime($deadline['deadline_date'] ?? 'now'))) ?> (Horário de Brasília)</strong>
                    </p>
                </div>
            </div>
        </div>

        <form id="form-diretrizes">
            <input type="hidden" id="deadline-id" value="<?= $deadline['id'] ?>">
            
            <!-- Quinteto Titular -->
            <div class="card bg-dark-panel border-orange mb-4">
                <div class="card-header bg-transparent border-orange">
                    <h5 class="text-white mb-0"><i class="bi bi-trophy me-2"></i>Quinteto Titular (5 jogadores)</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php 
                        $starterPositions = ['PG', 'SG', 'SF', 'PF', 'C'];
                        for ($i = 1; $i <= 5; $i++): 
                            $posLabel = $starterPositions[$i - 1] ?? $i;
                        ?>
                        <div class="col-md-4">
                            <label class="form-label text-white">Titular <?= htmlspecialchars($posLabel) ?></label>
                            <select class="form-select bg-dark text-white border-orange" name="starter_<?= $i ?>_id" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($players as $p): ?>
                                <option value="<?= $p['id'] ?>" data-ovr="<?= $p['ovr'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> - <?= $p['position'] ?> (<?= $p['ovr'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Banco -->
            <div class="card bg-dark-panel border-orange mb-4">
                <div class="card-header bg-transparent border-orange">
                    <h5 class="text-white mb-0"><i class="bi bi-people me-2"></i>Banco (selecione quantos quiser)</h5>
                </div>
                <div class="card-body">
                    <p class="text-light-gray mb-3">Marque os jogadores que farão parte do banco. Cada jogador selecionado deve jogar no mínimo 5 minutos.</p>
                    <div class="row g-2" id="bench-players-container">
                        <?php foreach ($players as $p): ?>
                        <div class="col-md-4 col-lg-3">
                            <div class="form-check bg-dark rounded p-2 border border-secondary bench-player-item">
                                <input class="form-check-input bench-player-checkbox" type="checkbox" 
                                       name="bench_players[]" value="<?= $p['id'] ?>" id="bench_<?= $p['id'] ?>">
                                <label class="form-check-label text-white small" for="bench_<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> <span class="text-light-gray">(<?= $p['position'] ?> - <?= $p['ovr'] ?>)</span>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3">
                        <small class="text-orange"><i class="bi bi-info-circle me-1"></i>Selecionados: <span id="bench-count">0</span> jogadores</small>
                    </div>
                </div>
            </div>

            <!-- Estilos de Jogo -->
            <div class="card bg-dark-panel border-orange mb-4">
                <div class="card-header bg-transparent border-orange">
                    <h5 class="text-white mb-0"><i class="bi bi-gear me-2"></i>Configurações de Estilo de Jogo</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-white">Estilo de Jogo</label>
                            <select class="form-select bg-dark text-white border-orange" name="game_style">
                                <option value="balanced">Balanced</option>
                                <option value="triangle">Triangle</option>
                                <option value="grit_grind">Grit & Grind</option>
                                <option value="pace_space">Pace & Space</option>
                                <option value="perimeter_centric">Perimeter Centric</option>
                                <option value="post_centric">Post Centric</option>
                                <option value="seven_seconds">Seven Seconds</option>
                                <option value="defense">Defense</option>
                                <option value="defensive_focus">Defensive Focus</option>
                                <option value="franchise_player">Melhor esquema pro Franchise Player</option>
                                <option value="most_stars">Maior nº de Estrelas</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white">Estilo de Ataque</label>
                            <select class="form-select bg-dark text-white border-orange" name="offense_style">
                                <option value="no_preference">No Preference</option>
                                <option value="pick_roll">Pick & Roll Offense</option>
                                <option value="neutral">Neutral Offensive Focus</option>
                                <option value="play_through_star">Play Through Star</option>
                                <option value="get_to_basket">Get to The Basket</option>
                                <option value="get_shooters_open">Get Shooters Open</option>
                                <option value="feed_post">Feed The Post</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white">Estilo de Rotação</label>
                            <select class="form-select bg-dark text-white border-orange" name="rotation_style">
                                <option value="auto">Automática</option>
                                <option value="manual">Manual</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white">Tempo de Ataque</label>
                            <select class="form-select bg-dark text-white border-orange" name="pace">
                                <option value="no_preference">No Preference</option>
                                <option value="patient">Patient Offense</option>
                                <option value="average">Average Tempo</option>
                                <option value="shoot_at_will">Shoot at Will</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Configurações Defensivas -->
            <div class="card bg-dark-panel border-orange mb-4">
                <div class="card-header bg-transparent border-orange">
                    <h5 class="text-white mb-0"><i class="bi bi-shield me-2"></i>Configurações Defensivas e Rebotes</h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-white">Agressividade Defensiva</label>
                            <select class="form-select bg-dark text-white border-orange" name="offensive_aggression">
                                <option value="physical">Play Physical Defense</option>
                                <option value="no_preference" selected>No Preference</option>
                                <option value="conservative">Conservative Defense</option>
                                <option value="neutral">Neutral Defensive Aggression</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white">Rebote Ofensivo</label>
                            <select class="form-select bg-dark text-white border-orange" name="offensive_rebound">
                                <option value="limit_transition">Limit Transition</option>
                                <option value="no_preference" selected>No Preference</option>
                                <option value="crash_glass">Crash Offensive Glass</option>
                                <option value="some_crash">Some Crash, Others Get Back</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white">Rebote Defensivo</label>
                            <select class="form-select bg-dark text-white border-orange" name="defensive_rebound">
                                <option value="run_transition">Run in Transition</option>
                                <option value="crash_glass">Crash Defensive Glass</option>
                                <option value="some_crash">Some Crash Others Run</option>
                                <option value="no_preference" selected>No Preference</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-white">Defensive Focus</label>
                            <select class="form-select bg-dark text-white border-orange" name="defensive_focus">
                                <option value="no_preference" selected>No Preference</option>
                                <option value="neutral">Neutral Defensive Focus</option>
                                <option value="protect_paint">Protect the Paint</option>
                                <option value="limit_perimeter">Limit Perimeter Shots</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Rotação e Foco -->
            <div class="card bg-dark-panel border-orange mb-4" id="rotation-config-card">
                <div class="card-header bg-transparent border-orange">
                    <h5 class="text-white mb-0"><i class="bi bi-sliders me-2"></i>Rotação e Foco</h5>
                </div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-md-6" id="rotation-players-field">
                            <label class="form-label text-white">Jogadores na Rotação</label>
                            <select class="form-select bg-dark text-white border-orange" name="rotation_players">
                                <?php for ($i = 8; $i <= 15; $i++): ?>
                                <option value="<?= $i ?>" <?= $i == 10 ? 'selected' : '' ?>><?= $i ?> jogadores</option>
                                <?php endfor; ?>
                            </select>
                            <small class="text-light-gray">Quantidade de jogadores que entram em quadra</small>
                        </div>
                        <div class="col-md-6" id="veteran-focus-field">
                            <label class="form-label text-white">% Foco em Jogadores Jovens</label>
                            <input type="range" class="form-range" name="veteran_focus" id="veteran_focus" min="0" max="100" value="50">
                            <div class="d-flex justify-content-between text-light-gray small">
                                <span>Veteranos (0%)</span>
                                <span id="veteran_focus-value">50%</span>
                                <span>Jovens (100%)</span>
                            </div>
                            <small class="text-light-gray">Define prioridade de minutos entre jovens e veteranos</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Minutagem por Jogador -->
            <div class="card bg-dark-panel border-orange mb-4" id="player-minutes-card">
                <div class="card-header bg-transparent border-orange">
                    <h5 class="text-white mb-0"><i class="bi bi-clock me-2"></i>Minutagem por Jogador</h5>
                </div>
                <div class="card-body">
                    <p class="text-light-gray mb-3">Configure os minutos por jogo para cada jogador (mínimo 5; máximo definido pelo prazo: 40 na regular / 45 nos playoffs)</p>
                    <div id="player-minutes-container" class="row g-3">
                        <!-- Preenchido dinamicamente com JavaScript -->
                    </div>
                </div>
            </div>

            <!-- G-League -->
            <?php if ($gleagueSlots > 0): ?>
            <div class="card bg-dark-panel border-orange mb-4">
                <div class="card-header bg-transparent border-orange">
                    <h5 class="text-white mb-0"><i class="bi bi-arrow-down-circle me-2"></i>G-League (Opcional)</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info bg-dark border-info mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        Seu elenco tem <strong><?= $playerCount ?></strong> jogadores. Você pode enviar até <strong><?= $gleagueSlots ?></strong> jogador(es) para a G-League.
                        <br><small class="text-light-gray">Regra: 15+ jogadores = 2 vagas | 14 jogadores = 1 vaga | &lt;14 jogadores = sem vagas</small>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-white">Jogador G-League 1</label>
                            <select class="form-select bg-dark text-white border-orange" name="gleague_1_id">
                                <option value="">Nenhum</option>
                                <?php foreach ($players as $p): ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> - <?= $p['position'] ?> (<?= $p['ovr'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($gleagueSlots >= 2): ?>
                        <div class="col-md-6">
                            <label class="form-label text-white">Jogador G-League 2</label>
                            <select class="form-select bg-dark text-white border-orange" name="gleague_2_id">
                                <option value="">Nenhum</option>
                                <?php foreach ($players as $p): ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> - <?= $p['position'] ?> (<?= $p['ovr'] ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Observações -->
            <div class="card bg-dark-panel border-orange mb-4">
                <div class="card-header bg-transparent border-orange">
                    <h5 class="text-white mb-0"><i class="bi bi-chat-text me-2"></i>Observações</h5>
                </div>
                <div class="card-body">
                    <textarea class="form-control bg-dark text-white border-orange" name="notes" rows="4" 
                              placeholder="Observações adicionais sobre sua estratégia..."></textarea>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-3">
                <button type="button" class="btn btn-outline-secondary" onclick="window.location.href='/dashboard.php'">
                    Cancelar
                </button>
                <button type="submit" class="btn btn-orange btn-lg">
                    <i class="bi bi-send me-2"></i>Enviar Diretrizes
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <script>
        window.__DEADLINE_ID__ = <?= $deadline ? (int)$deadline['id'] : 'null' ?>;
        window.__SEASON_STATUS__ = <?= json_encode($currentSeason['status'] ?? null) ?>;
        window.__DEADLINE_PHASE__ = <?= json_encode($deadline['phase'] ?? null) ?>;
        window.__DEADLINE_ISO__ = <?= json_encode($deadlineIso) ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/sidebar.js"></script>
    <script src="/js/diretrizes.js?v=<?= time() ?>"></script>
    <script src="/js/pwa.js"></script>
</body>
</html>
