<?php
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';

$pdo = db();

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') {
    http_response_code(404);
    echo "Time não encontrado.";
    exit;
}

$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE public_slug = ? AND public_enabled = 1 LIMIT 1');
$stmtTeam->execute([$slug]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);

if (!$team) {
    http_response_code(404);
    echo "Time não encontrado ou página desativada.";
    exit;
}

$moduleKeys = ['roster','lineup','titles','picks','ranking','ai_avgs','trades'];
$modules = [];
if (!empty($team['public_modules'])) {
    $decoded = json_decode((string)$team['public_modules'], true);
    if (is_array($decoded)) {
        $modules = array_values(array_filter($decoded, fn($k) => is_string($k) && in_array($k, $moduleKeys, true)));
    }
}
if (!$modules) $modules = $moduleKeys;

$primary = $team['public_primary_color'] ?: '#fc0025';
$secondary = $team['public_secondary_color'] ?: '#ff2a44';

$teamId = (int)$team['id'];
$league = (string)($team['league'] ?? '');

$players = [];
$starters = [];
if (in_array('roster', $modules, true) || in_array('lineup', $modules, true) || in_array('ai_avgs', $modules, true)) {
    $stmtPlayers = $pdo->prepare('SELECT * FROM players WHERE team_id = ? ORDER BY ovr DESC, age ASC, name ASC');
    $stmtPlayers->execute([$teamId]);
    $players = $stmtPlayers->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $starters = array_values(array_filter($players, fn($p) => ($p['role'] ?? '') === 'Titular'));
}

$championships = 0;
if (in_array('titles', $modules, true)) {
    try {
        $stmtTitles = $pdo->prepare('SELECT COUNT(*) FROM season_history WHERE champion_team_id = ?');
        $stmtTitles->execute([$teamId]);
        $championships = (int)$stmtTitles->fetchColumn();
    } catch (Exception $e) {
        $championships = 0;
    }
}

$picks = [];
if (in_array('picks', $modules, true)) {
    $stmtPicks = $pdo->prepare('SELECT season_year, round, original_team_id, notes FROM picks WHERE team_id = ? ORDER BY season_year ASC, round ASC, id ASC');
    $stmtPicks->execute([$teamId]);
    $picks = $stmtPicks->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$rankingPosition = null;
$rankingPoints = null;
if (in_array('ranking', $modules, true) && $league !== '') {
    try {
        $stmtRank = $pdo->prepare("
            SELECT trp.team_id, COALESCE(SUM(trp.points), 0) AS total_points
            FROM team_ranking_points trp
            WHERE trp.league = ?
            GROUP BY trp.team_id
            ORDER BY total_points DESC, trp.team_id ASC
        ");
        $stmtRank->execute([$league]);
        $rows = $stmtRank->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $pos = 1;
        foreach ($rows as $r) {
            if ((int)$r['team_id'] === $teamId) {
                $rankingPosition = $pos;
                $rankingPoints = (int)$r['total_points'];
                break;
            }
            $pos++;
        }
    } catch (Exception $e) {
        $rankingPosition = null;
        $rankingPoints = null;
    }
}

$avgAge = null;
$avgOvr = null;
if (in_array('ai_avgs', $modules, true) && $players) {
    $sumAge = 0;
    $sumOvr = 0;
    $n = 0;
    foreach ($players as $p) {
        $sumAge += (int)($p['age'] ?? 0);
        $sumOvr += (int)($p['ovr'] ?? 0);
        $n++;
    }
    if ($n > 0) {
        $avgAge = $sumAge / $n;
        $avgOvr = $sumOvr / $n;
    }
}

$trades = [];
if (in_array('trades', $modules, true)) {
    try {
        $stmtTrades = $pdo->prepare("
            SELECT t.*, tf.name AS from_name, tt.name AS to_name
            FROM trades t
            LEFT JOIN teams tf ON tf.id = t.from_team_id
            LEFT JOIN teams tt ON tt.id = t.to_team_id
            WHERE t.status = 'accepted'
              AND (t.from_team_id = ? OR t.to_team_id = ?)
            ORDER BY t.updated_at DESC, t.id DESC
            LIMIT 15
        ");
        $stmtTrades->execute([$teamId, $teamId]);
        $trades = $stmtTrades->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) {
        $trades = [];
    }
}

$teamDisplayName = trim((string)($team['name'] ?? ''));
$teamLogo = $team['photo_url'] ?? '/img/default-team.png';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?= htmlspecialchars($teamDisplayName) ?> — FBA</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root{
            --primary: <?= htmlspecialchars($primary) ?>;
            --secondary: <?= htmlspecialchars($secondary) ?>;
            --bg: #07070a;
            --panel: rgba(16,16,19,.92);
            --border: rgba(255,255,255,.08);
            --text: #f0f0f3;
            --muted: rgba(240,240,243,.62);
            --radius: 18px;
            --font: 'Poppins', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
        }
        body{
            font-family: var(--font);
            background:
                radial-gradient(900px 420px at 20% 0%, color-mix(in srgb, var(--primary) 28%, transparent), transparent 55%),
                radial-gradient(900px 420px at 80% 0%, color-mix(in srgb, var(--secondary) 22%, transparent), transparent 55%),
                var(--bg);
            color: var(--text);
            min-height: 100vh;
        }
        .hero{
            border: 1px solid var(--border);
            background: linear-gradient(160deg, color-mix(in srgb, var(--primary) 12%, transparent), transparent 55%);
            border-radius: calc(var(--radius) + 6px);
            padding: 22px;
        }
        .cardx{
            border: 1px solid var(--border);
            background: color-mix(in srgb, var(--panel) 92%, transparent);
            border-radius: var(--radius);
            padding: 16px;
        }
        .badgex{
            background: color-mix(in srgb, var(--primary) 22%, transparent);
            border: 1px solid color-mix(in srgb, var(--primary) 35%, transparent);
            color: var(--text);
        }
        .table{ color: var(--text); }
        .table>:not(caption)>*>*{ background-color: transparent; border-color: var(--border); }
        .muted{ color: var(--muted); }
    </style>
</head>
<body>
    <div class="container py-4" style="max-width: 1100px;">
        <div class="hero mb-3 d-flex align-items-center gap-3">
            <img src="<?= htmlspecialchars($teamLogo) ?>" alt="<?= htmlspecialchars($teamDisplayName) ?>" style="width:86px;height:86px;border-radius:999px;object-fit:cover;border:2px solid var(--primary);" onerror="this.src='/img/default-team.png'">
            <div class="flex-grow-1">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <h1 class="m-0" style="font-weight:900;letter-spacing:-.02em;"><?= htmlspecialchars($teamDisplayName) ?></h1>
                    <?php if ($league !== ''): ?>
                        <span class="badge badgex"><?= htmlspecialchars($league) ?></span>
                    <?php endif; ?>
                </div>
                <div class="muted"><?= htmlspecialchars(trim((string)($team['city'] ?? ''))) ?></div>
            </div>
            <div class="text-end d-none d-md-block">
                <div class="muted" style="font-size:.9rem;">Página pública do time</div>
                <div style="font-weight:700;"><?= htmlspecialchars('/times/' . $slug) ?></div>
            </div>
        </div>

        <div class="row g-3">
            <?php foreach ($modules as $m): ?>
                <?php if ($m === 'ai_avgs'): ?>
                    <div class="col-12 col-md-6">
                        <div class="cardx">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div style="font-weight:800;">Médias IA</div>
                                <i class="bi bi-cpu muted"></i>
                            </div>
                            <div class="d-flex gap-3">
                                <div>
                                    <div class="muted" style="font-size:.85rem;">Idade</div>
                                    <div style="font-size:1.4rem;font-weight:900;"><?= $avgAge === null ? '—' : number_format($avgAge, 1, ',', '.') ?></div>
                                </div>
                                <div>
                                    <div class="muted" style="font-size:.85rem;">OVR</div>
                                    <div style="font-size:1.4rem;font-weight:900;"><?= $avgOvr === null ? '—' : number_format($avgOvr, 1, ',', '.') ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($m === 'titles'): ?>
                    <div class="col-12 col-md-6">
                        <div class="cardx">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div style="font-weight:800;">Títulos</div>
                                <i class="bi bi-trophy-fill muted"></i>
                            </div>
                            <div style="font-size:1.6rem;font-weight:900;"><?= (int)$championships ?></div>
                            <div class="muted" style="font-size:.9rem;">Campeonatos (season_history)</div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($m === 'ranking'): ?>
                    <div class="col-12">
                        <div class="cardx">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div style="font-weight:800;">Ranking</div>
                                <i class="bi bi-bar-chart-fill muted"></i>
                            </div>
                            <?php if ($rankingPosition === null): ?>
                                <div class="muted">Sem dados de ranking ainda.</div>
                            <?php else: ?>
                                <div class="d-flex flex-wrap gap-4">
                                    <div>
                                        <div class="muted" style="font-size:.85rem;">Posição</div>
                                        <div style="font-size:1.6rem;font-weight:900;">#<?= (int)$rankingPosition ?></div>
                                    </div>
                                    <div>
                                        <div class="muted" style="font-size:.85rem;">Pontos</div>
                                        <div style="font-size:1.6rem;font-weight:900;"><?= (int)$rankingPoints ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($m === 'lineup'): ?>
                    <div class="col-12">
                        <div class="cardx">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div style="font-weight:800;">Escalação</div>
                                <i class="bi bi-people-fill muted"></i>
                            </div>
                            <?php if (!$starters): ?>
                                <div class="muted">Sem titulares cadastrados.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle m-0">
                                        <thead>
                                            <tr>
                                                <th>Jogador</th>
                                                <th>Pos</th>
                                                <th class="text-end">Idade</th>
                                                <th class="text-end">OVR</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($starters as $p): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars((string)($p['name'] ?? '')) ?></td>
                                                    <td><?= htmlspecialchars((string)($p['position'] ?? '')) ?></td>
                                                    <td class="text-end"><?= (int)($p['age'] ?? 0) ?></td>
                                                    <td class="text-end"><?= (int)($p['ovr'] ?? 0) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($m === 'roster'): ?>
                    <div class="col-12">
                        <div class="cardx">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div style="font-weight:800;">Elenco</div>
                                <i class="bi bi-list-ul muted"></i>
                            </div>
                            <?php if (!$players): ?>
                                <div class="muted">Sem jogadores cadastrados.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle m-0">
                                        <thead>
                                            <tr>
                                                <th>Jogador</th>
                                                <th>Pos</th>
                                                <th>Role</th>
                                                <th class="text-end">Idade</th>
                                                <th class="text-end">OVR</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($players as $p): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars((string)($p['name'] ?? '')) ?></td>
                                                    <td><?= htmlspecialchars((string)($p['position'] ?? '')) ?></td>
                                                    <td><?= htmlspecialchars((string)($p['role'] ?? '')) ?></td>
                                                    <td class="text-end"><?= (int)($p['age'] ?? 0) ?></td>
                                                    <td class="text-end"><?= (int)($p['ovr'] ?? 0) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($m === 'picks'): ?>
                    <div class="col-12">
                        <div class="cardx">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div style="font-weight:800;">Picks</div>
                                <i class="bi bi-calendar-check-fill muted"></i>
                            </div>
                            <?php if (!$picks): ?>
                                <div class="muted">Sem picks cadastradas.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle m-0">
                                        <thead>
                                            <tr>
                                                <th>Ano</th>
                                                <th>Round</th>
                                                <th class="text-end">Original</th>
                                                <th>Obs.</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($picks as $pk): ?>
                                                <tr>
                                                    <td><?= (int)($pk['season_year'] ?? 0) ?></td>
                                                    <td><?= htmlspecialchars((string)($pk['round'] ?? '')) ?></td>
                                                    <td class="text-end">#<?= (int)($pk['original_team_id'] ?? 0) ?></td>
                                                    <td><?= htmlspecialchars((string)($pk['notes'] ?? '')) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($m === 'trades'): ?>
                    <div class="col-12">
                        <div class="cardx">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div style="font-weight:800;">Trades</div>
                                <i class="bi bi-arrow-left-right muted"></i>
                            </div>
                            <?php if (!$trades): ?>
                                <div class="muted">Sem trades aceitas ainda.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle m-0">
                                        <thead>
                                            <tr>
                                                <th>De</th>
                                                <th>Para</th>
                                                <th>Status</th>
                                                <th class="text-end">Atualizado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($trades as $t): ?>
                                                <tr>
                                                    <td><?= htmlspecialchars((string)($t['from_name'] ?? '')) ?></td>
                                                    <td><?= htmlspecialchars((string)($t['to_name'] ?? '')) ?></td>
                                                    <td><?= htmlspecialchars((string)($t['status'] ?? '')) ?></td>
                                                    <td class="text-end"><?= htmlspecialchars((string)($t['updated_at'] ?? $t['created_at'] ?? '')) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="text-center muted mt-4" style="font-size:.85rem;">
            FBA Brasil — Página pública do time
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

