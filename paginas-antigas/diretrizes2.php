<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();
ensureTeamDirectiveProfileColumns($pdo);

// Buscar time do usuario
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch();

if (!$team) {
    header('Location: /onboarding.php');
    exit;
}

// Buscar prazo ativo (somente se ainda nao expirou - usando horario de Brasilia)
$nowBrasilia = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d H:i:s');

$stmtDeadline = $pdo->prepare("
    SELECT * FROM directive_deadlines 
    WHERE league = ? AND is_active = 1 AND deadline_date > ?
    ORDER BY deadline_date ASC LIMIT 1
");
$stmtDeadline->execute([$team['league'], $nowBrasilia]);
$deadline = $stmtDeadline->fetch();

$forceProfileMode = isset($_GET['mode']) && $_GET['mode'] === 'profile';
$directiveMode = ($forceProfileMode || !$deadline) ? 'profile' : 'deadline';

$deadlineDisplay = null;
$deadlineIso = null;
$hasDirectiveSubmission = false;
if ($deadline && !empty($deadline['deadline_date'])) {
    try {
        $deadlineDateTime = new DateTime($deadline['deadline_date'], new DateTimeZone('America/Sao_Paulo'));
        $deadlineDisplay = $deadlineDateTime->format('d/m/Y \\a\\s H:i');
        $deadlineIso = $deadlineDateTime->format(DateTime::ATOM);
    } catch (Exception $e) {
        $deadlineDisplay = date('d/m/Y', strtotime($deadline['deadline_date']));
    }
}

if ($deadline && !empty($team['id'])) {
    $stmtHasDirective = $pdo->prepare("SELECT id FROM team_directives WHERE team_id = ? AND deadline_id = ? LIMIT 1");
    $stmtHasDirective->execute([(int)$team['id'], (int)$deadline['id']]);
    $hasDirectiveSubmission = (bool)$stmtHasDirective->fetchColumn();
}

// Buscar jogadores do time
$stmtPlayers = $pdo->prepare("
    SELECT id, name, position, ovr, age, role
    FROM players
    WHERE team_id = ? 
    ORDER BY ovr DESC
");
$stmtPlayers->execute([$team['id']]);
$players = $stmtPlayers->fetchAll();

// Contar jogadores para regras da G-League
$playerCount = count($players);
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

    <link rel="manifest" href="/manifest.json?v=3">
    <meta name="theme-color" content="#0a0a0c">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FBA Manager">
    <link rel="apple-touch-icon" href="/img/fba-logo.png?v=3">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css" />
    <style>
        .directive-step { border-left: 3px solid var(--fba-orange); padding-left: 14px; }
        .sticky-summary { position: sticky; top: 20px; }
        .chip { background: rgba(255, 147, 0, 0.15); color: #ffb24a; border: 1px solid rgba(255, 147, 0, 0.35); }
    </style>
</head>
<body>
    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="bi bi-list fs-4"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

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
            <li><a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a></li>
            <li><a href="/leilao.php"><i class="bi bi-hammer"></i> Leilao</a></li>
            <li><a href="/diretrizes.php"><i class="bi bi-clipboard-check"></i> Diretrizes</a></li>
            <li><a href="/diretrizes2.php" class="active"><i class="bi bi-clipboard2-check"></i> Diretrizes 2</a></li>
            <?php if (($user['user_type'] ?? 'jogador') === 'admin'): ?>
            <li><a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a></li>
            <li><a href="/temporadas.php"><i class="bi bi-calendar3"></i> Temporadas</a></li>
            <?php endif; ?>
            <li><a href="/settings.php"><i class="bi bi-gear-fill"></i> Configuracoes</a></li>
        </ul>

        <hr style="border-color: var(--fba-border);">

        <div class="text-center">
            <a href="/logout.php" class="btn btn-outline-danger btn-sm w-100">
                <i class="bi bi-box-arrow-right me-2"></i>Sair
            </a>
        </div>
    </div>

    <div class="dashboard-content">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="text-white fw-bold mb-1"><i class="bi bi-clipboard-check me-2 text-orange"></i>Diretrizes de Jogo</h1>
                <p class="text-light-gray mb-0">Fluxo simplificado, com etapas claras e envio rapido</p>
            </div>
            <span class="badge chip px-3 py-2">Modo: <?= $directiveMode === 'profile' ? 'Diretriz Base' : 'Envio de Prazo' ?></span>
        </div>

        <?php if (!$deadline): ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle me-2"></i>
            Nao ha prazo ativo para envio de diretrizes no momento. Voce pode salvar a diretriz base do seu time.
        </div>
        <?php endif; ?>
        <?php if ($deadline && $directiveMode === 'profile'): ?>
        <div class="alert alert-warning bg-dark border-warning mb-4">
            <i class="bi bi-info-circle me-2"></i>
            Voce esta editando a diretriz base do time. Para enviar no prazo, use o card de envio no dashboard.
        </div>
        <?php endif; ?>
        <?php if ($deadline && $directiveMode === 'deadline'): ?>
        <div class="alert alert-info bg-dark-panel border-orange mb-4">
            <div class="d-flex align-items-center">
                <i class="bi bi-calendar-event text-orange me-3 fs-3"></i>
                <div>
                    <h5 class="text-white mb-1">Prazo de Envio</h5>
                    <p class="text-light-gray mb-0">
                        <?= htmlspecialchars($deadline['description'] ?? 'Envio de diretrizes') ?> - 
                        <strong>Ate <?= htmlspecialchars($deadlineDisplay ?? date('d/m/Y', strtotime($deadline['deadline_date'] ?? 'now'))) ?> (Horario de Brasilia)</strong>
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">
                <form id="form-diretrizes">
                    <input type="hidden" id="deadline-id" value="<?= ($directiveMode === 'deadline' && $deadline) ? $deadline['id'] : '' ?>">

                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-transparent border-orange">
                            <div class="directive-step">
                                <h5 class="text-white mb-1"><i class="bi bi-trophy me-2"></i>Etapa 1: Quinteto Titular</h5>
                                <small class="text-light-gray">Escolha 5 jogadores (PG, SG, SF, PF, C)</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <?php 
                                $starterPositions = ['PG', 'SG', 'SF', 'PF', 'C'];
                                for ($i = 1; $i <= 5; $i++): 
                                    $posLabel = $starterPositions[$i - 1] ?? $i;
                                ?>
                                <div class="col-md-6">
                                    <label class="form-label text-white">Titular <?= htmlspecialchars($posLabel) ?></label>
                                    <select class="form-select bg-dark text-white border-orange" name="starter_<?= $i ?>_id" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($players as $p): ?>
                                        <option value="<?= $p['id'] ?>" data-ovr="<?= $p['ovr'] ?>">
                                            <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['ovr']) ?>/<?= htmlspecialchars($p['age'] ?? '?') ?>) - <?= $p['position'] ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>

                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-transparent border-orange">
                            <div class="directive-step">
                                <h5 class="text-white mb-1"><i class="bi bi-people me-2"></i>Etapa 2: Banco</h5>
                                <small class="text-light-gray">Selecione quantos quiser (minimo 5 minutos cada)</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-2" id="bench-players-container">
                                <?php foreach ($players as $p): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="form-check bg-dark rounded p-2 border border-secondary bench-player-item">
                                        <input class="form-check-input bench-player-checkbox" type="checkbox" 
                                               name="bench_players[]" value="<?= $p['id'] ?>" id="bench_<?= $p['id'] ?>">
                                        <label class="form-check-label text-white small" for="bench_<?= $p['id'] ?>">
                                            <?= htmlspecialchars($p['name']) ?> <span class="text-light-gray">(<?= $p['ovr'] ?>/<?= htmlspecialchars($p['age'] ?? '?') ?>) - <?= $p['position'] ?></span>
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

                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-transparent border-orange">
                            <div class="directive-step">
                                <h5 class="text-white mb-1"><i class="bi bi-gear me-2"></i>Etapa 3: Estilo de Jogo</h5>
                                <small class="text-light-gray">Ajuste estrategia geral do time</small>
                            </div>
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
                                        <option value="franchise_player">Melhor esquema pro Franchise Player</option>
                                        <option value="most_stars">Maior n de Estrelas</option>
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
                                    <label class="form-label text-white">Estilo de Rotacao</label>
                                    <select class="form-select bg-dark text-white border-orange" name="rotation_style">
                                        <option value="auto">Automatica</option>
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
                                <?php if (in_array(($team['league'] ?? ''), ['ELITE', 'NEXT'], true)): ?>
                                <div class="col-md-6">
                                    <label class="form-label text-white">Modelo tecnico</label>
                                    <select class="form-select bg-dark text-white border-orange" name="technical_model">
                                        <option value="">Selecione...</option>
                                        <option value="HC">HC</option>
                                        <option value="FBA 14">FBA 14</option>
                                        <option value="Michael Stauffer">Michael Stauffer</option>
                                        <option value="Joe Mazzulla">Joe Mazzulla</option>
                                        <option value="Mark Daigneault">Mark Daigneault</option>
                                        <option value="Greg Popovich">Greg Popovich</option>
                                        <option value="Phil Jackson">Phil Jackson</option>
                                        <option value="Steve Kerr">Steve Kerr</option>
                                        <option value="Rick Carlisle">Rick Carlisle</option>
                                        <option value="Erik Spoelstra">Erik Spoelstra</option>
                                        <option value="Mike D'Antoni">Mike D'Antoni</option>
                                        <option value="Nick Nurse">Nick Nurse</option>
                                    </select>
                                    <small class="text-light-gray d-block mt-1" id="technical-model-remaining"></small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (in_array(($team['league'] ?? ''), ['ELITE', 'NEXT'], true)): ?>
                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-transparent border-orange">
                            <div class="directive-step">
                                <h5 class="text-white mb-1"><i class="bi bi-journal-text me-2"></i>Playbook</h5>
                                <small class="text-light-gray">Descreva as jogadas principais</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <label class="form-label text-white">Playbook</label>
                            <textarea class="form-control bg-dark text-white border-orange" name="playbook" rows="4" placeholder="Descreva o playbook do time..."></textarea>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-transparent border-orange">
                            <div class="directive-step">
                                <h5 class="text-white mb-1"><i class="bi bi-shield me-2"></i>Etapa 4: Defesa e Rebotes</h5>
                                <small class="text-light-gray">Ajustes defensivos do time</small>
                            </div>
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

                    <div class="card bg-dark-panel border-orange mb-4" id="rotation-config-card">
                        <div class="card-header bg-transparent border-orange">
                            <div class="directive-step">
                                <h5 class="text-white mb-1"><i class="bi bi-sliders me-2"></i>Etapa 5: Rotacao e Foco</h5>
                                <small class="text-light-gray">Visivel apenas na rotacao automatica</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-4">
                                <div class="col-md-6" id="rotation-players-field">
                                    <label class="form-label text-white">Jogadores na Rotacao</label>
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

                    <div class="card bg-dark-panel border-orange mb-4" id="player-minutes-card">
                        <div class="card-header bg-transparent border-orange">
                            <div class="directive-step">
                                <h5 class="text-white mb-1"><i class="bi bi-clock me-2"></i>Etapa 6: Minutagem por Jogador</h5>
                                <small class="text-light-gray">Visivel apenas na rotacao manual</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning bg-dark border-warning mb-3">
                                <small><i class="bi bi-info-circle me-1"></i><strong>Atencao:</strong> 
                                Titulares precisam de <strong>minimo 25min</strong>. Reservas precisam de <strong>minimo 5min</strong>. 
                                Se voce nao tiver 3 jogadores 85+, os <strong>5 maiores OVRs devem ter 25+ minutos</strong>.</small>
                            </div>
                            <p class="text-light-gray mb-3">Configure os minutos por jogo para cada jogador (minimo 5; maximo definido pelo prazo: 40 na regular / 45 nos playoffs)</p>
                            <div id="player-minutes-container" class="row g-3"></div>
                        </div>
                    </div>

                    <?php if ($gleagueSlots > 0): ?>
                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-transparent border-orange">
                            <div class="directive-step">
                                <h5 class="text-white mb-1"><i class="bi bi-arrow-down-circle me-2"></i>Etapa 7: G-League (Opcional)</h5>
                                <small class="text-light-gray"><?= $playerCount ?> jogadores no elenco, ate <?= $gleagueSlots ?> vagas</small>
                            </div>
                        </div>
                        <div class="card-body">
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

                    <div class="card bg-dark-panel border-orange mb-4">
                        <div class="card-header bg-transparent border-orange">
                            <div class="directive-step">
                                <h5 class="text-white mb-1"><i class="bi bi-chat-text me-2"></i>Etapa 8: Observacoes</h5>
                                <small class="text-light-gray">Detalhes extras para o comite</small>
                            </div>
                        </div>
                        <div class="card-body">
                            <textarea class="form-control bg-dark text-white border-orange" name="notes" rows="4" 
                                      placeholder="Observacoes adicionais sobre sua estrategia..."></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-3 mb-4">
                        <button type="button" class="btn btn-outline-secondary" onclick="window.location.href='/dashboard.php'">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-orange btn-lg">
                            <?php if ($directiveMode === 'profile'): ?>
                                <i class="bi bi-save2 me-2"></i>Salvar Diretriz do Time
                            <?php elseif ($hasDirectiveSubmission): ?>
                                <i class="bi bi-arrow-repeat me-2"></i>Atualizar Diretrizes
                            <?php else: ?>
                                <i class="bi bi-send me-2"></i>Enviar Diretrizes
                            <?php endif; ?>
                        </button>
                    </div>
                </form>
            </div>

            <div class="col-lg-4">
                <div class="card bg-dark-panel border-orange sticky-summary mb-4">
                    <div class="card-header bg-transparent border-orange">
                        <h5 class="text-white mb-0"><i class="bi bi-info-circle me-2"></i>Resumo Rapido</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <span class="text-light-gray small">Modo</span>
                            <div class="text-white fw-semibold"><?= $directiveMode === 'profile' ? 'Diretriz Base' : 'Envio de Prazo' ?></div>
                        </div>
                        <div class="mb-3">
                            <span class="text-light-gray small">Prazo</span>
                            <div class="text-white fw-semibold">
                                <?= $deadline ? htmlspecialchars($deadlineDisplay ?? date('d/m/Y', strtotime($deadline['deadline_date'] ?? 'now'))) : 'Sem prazo ativo' ?>
                            </div>
                        </div>
                        <hr style="border-color: var(--fba-border);">
                        <div class="mb-2 text-white fw-semibold">Regras de Minutagem</div>
                        <ul class="text-light-gray small ps-3 mb-0">
                            <li>Titulares: minimo 25 minutos</li>
                            <li>Reservas: minimo 5 minutos</li>
                            <li>Top 5 OVRs: 25+ se nao tiver 3 jogadores 85+</li>
                            <li>Total: exatamente 240 minutos</li>
                            <li>Maximo: 40 regular / 45 playoffs</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.__DEADLINE_ID__ = <?= ($directiveMode === 'deadline' && $deadline) ? (int)$deadline['id'] : 'null' ?>;
        window.__SEASON_STATUS__ = <?= json_encode($currentSeason['status'] ?? null) ?>;
        window.__DEADLINE_PHASE__ = <?= json_encode($deadline['phase'] ?? null) ?>;
        window.__DEADLINE_ISO__ = <?= json_encode($deadlineIso) ?>;
        window.__DIRECTIVE_MODE__ = <?= json_encode($directiveMode) ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/js/sidebar.js"></script>
    <script src="/js/diretrizes.js?v=<?= time() ?>"></script>
    <script src="/js/pwa.js"></script>
</body>
</html>
