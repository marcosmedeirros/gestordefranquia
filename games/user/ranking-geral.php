<?php
// ranking-geral.php - RANKING COMPLETO COM ABAS (FBA games)
session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$hiddenRankingEmailLower = 'medeirros99@gmail.com';

try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin, fba_points FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar perfil: " . $e->getMessage());
}

// =========================================================================
// ENDPOINTS AJAX MOVIDOS PARA O TOPO (Evita renderizar HTML na resposta)
// =========================================================================
if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1 && isset($_GET['ajax_tapas'])) {
    $stmtTapas = $pdo->query("SELECT id, nome, numero_tapas FROM usuarios WHERE numero_tapas > 0 ORDER BY numero_tapas DESC, nome ASC");
    $usuarios_tapas = $stmtTapas->fetchAll(PDO::FETCH_ASSOC);
    $stmtAllUsers = $pdo->query("SELECT id, nome FROM usuarios ORDER BY nome ASC");
    $todos_usuarios = $stmtAllUsers->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(['usuarios_tapas' => $usuarios_tapas, 'todos_usuarios' => $todos_usuarios]);
    exit;
}

if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1 && isset($_POST['admin_tapa_action']) && isset($_POST['ajax'])) {
    $msg = '';
    if ($_POST['admin_tapa_action'] === 'remover' && !empty($_POST['remover_id'])) {
        $id = (int)$_POST['remover_id'];
        $pdo->prepare("UPDATE usuarios SET numero_tapas = GREATEST(numero_tapas-1,0) WHERE id = ?")->execute([$id]);
        $msg = 'Tapa removido!';
    }
    if ($_POST['admin_tapa_action'] === 'adicionar' && !empty($_POST['adicionar_id'])) {
        $id = (int)$_POST['adicionar_id'];
        $pdo->prepare("UPDATE usuarios SET numero_tapas = COALESCE(numero_tapas,0)+1 WHERE id = ?")->execute([$id]);
        $msg = 'Tapa adicionado!';
    }
    header('Content-Type: application/json');
    echo json_encode(['msg' => $msg]);
    exit;
}
// =========================================================================

$ranking_geral = [];
$ranking_por_liga = [
    'ELITE' => [],
    'RISE' => [],
    'NEXT' => [],
    'ROOKIE' => []
];

$filterStart = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$filterEnd = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$filterActive = false;
$filterStartAt = null;
$filterEndAt = null;

if ($filterStart !== '' && $filterEnd !== '') {
    $startDate = DateTime::createFromFormat('d/m/Y', $filterStart);
    $endDate = DateTime::createFromFormat('d/m/Y', $filterEnd);
    if ($startDate && $endDate) {
        $filterActive = true;
        $filterStartAt = $startDate->format('Y-m-d') . ' 00:00:00';
        $filterEndAt = $endDate->format('Y-m-d') . ' 23:59:59';
    }
}

try {
    if ($filterActive) {
        $stmt = $pdo->prepare("        
            SELECT
                u.id,
                u.nome,
                u.league,
                u.pontos,
                COALESCE(u.fba_points, 0) AS fba_points,
                COALESCE(SUM(CASE
                    WHEN e.status = 'encerrada'
                     AND e.vencedor_opcao_id IS NOT NULL
                     AND e.vencedor_opcao_id = p.opcao_id THEN 1
                    ELSE 0
                END), 0) AS acertos
            FROM usuarios u
            LEFT JOIN palpites p
                ON p.id_usuario = u.id
               AND p.data_palpite BETWEEN :start_at AND :end_at
            LEFT JOIN opcoes o ON p.opcao_id = o.id
            LEFT JOIN eventos e ON o.evento_id = e.id
            WHERE LOWER(u.email) <> :hidden_email
            GROUP BY u.id, u.nome, u.league
            ORDER BY u.pontos DESC, acertos DESC, u.nome ASC
        ");
        $stmt->execute([
            ':start_at' => $filterStartAt,
            ':end_at' => $filterEndAt,
            ':hidden_email' => $hiddenRankingEmailLower
        ]);
        $ranking_geral = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        $stmt = $pdo->prepare("        
        SELECT u.id, u.nome, u.pontos, COALESCE(u.fba_points, 0) AS fba_points, u.league,
               COALESCE(u.acertos_eventos, 0) as acertos,
               COALESCE(u.numero_tapas, 0) as numero_tapas
            FROM usuarios u
            WHERE LOWER(u.email) <> :hidden_email
            ORDER BY u.pontos DESC
        ");
        $stmt->execute([':hidden_email' => $hiddenRankingEmailLower]);
        $ranking_geral = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (PDOException $e) {
    $ranking_geral = [];
}

try {
    if ($filterActive) {
        $stmtLiga = $pdo->prepare("        
            SELECT
                u.id,
                u.nome,
                u.league,
                u.pontos,
                COALESCE(u.fba_points, 0) AS fba_points,
                COALESCE(SUM(CASE
                    WHEN e.status = 'encerrada'
                     AND e.vencedor_opcao_id IS NOT NULL
                     AND e.vencedor_opcao_id = p.opcao_id THEN 1
                    ELSE 0
                END), 0) AS acertos
            FROM usuarios u
            LEFT JOIN palpites p
                ON p.id_usuario = u.id
               AND p.data_palpite BETWEEN :start_at AND :end_at
            LEFT JOIN opcoes o ON p.opcao_id = o.id
            LEFT JOIN eventos e ON o.evento_id = e.id
            WHERE u.league = :league
              AND LOWER(u.email) <> :hidden_email
            GROUP BY u.id, u.nome, u.league
            ORDER BY u.pontos DESC, acertos DESC, u.nome ASC
        ");
        foreach (array_keys($ranking_por_liga) as $liga) {
            $stmtLiga->execute([
                ':league' => $liga,
                ':start_at' => $filterStartAt,
                ':end_at' => $filterEndAt,
                ':hidden_email' => $hiddenRankingEmailLower
            ]);
            $ranking_por_liga[$liga] = $stmtLiga->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } else {
        $stmtLiga = $pdo->prepare("        
        SELECT u.id, u.nome, u.pontos, COALESCE(u.fba_points, 0) AS fba_points, u.league,
               COALESCE(u.acertos_eventos, 0) as acertos,
               COALESCE(u.numero_tapas, 0) as numero_tapas
            FROM usuarios u
            WHERE u.league = :league
              AND LOWER(u.email) <> :hidden_email
            ORDER BY u.pontos DESC
        ");
        foreach (array_keys($ranking_por_liga) as $liga) {
            $stmtLiga->execute([':league' => $liga, ':hidden_email' => $hiddenRankingEmailLower]);
            $ranking_por_liga[$liga] = $stmtLiga->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }
} catch (PDOException $e) {
    foreach (array_keys($ranking_por_liga) as $liga) {
        $ranking_por_liga[$liga] = [];
    }
}

$best_game_users = [];

$addBestGame = function (array &$bestGameUsers, int $userId, string $label): void {
    if ($userId <= 0) {
        return;
    }
    if (!isset($bestGameUsers[$userId])) {
        $bestGameUsers[$userId] = [];
    }
    if (!in_array($label, $bestGameUsers[$userId], true)) {
        $bestGameUsers[$userId][] = $label;
    }
};

$bestGameIcons = [
    'Flappy' => '🐦',
    'Xadrez' => '♟️',
    'Batalha Naval' => '⚓',
    'Pinguim' => '🐧'
];

try {
    $stmt = $pdo->query("SELECT id_usuario, MAX(pontuacao) AS recorde FROM flappy_historico GROUP BY id_usuario ORDER BY recorde DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['id_usuario'])) {
        $addBestGame($best_game_users, (int)$row['id_usuario'], 'Flappy');
    }
} catch (PDOException $e) {
}

try {
    $stmt = $pdo->query("SELECT id_usuario, MAX(pontuacao_final) AS recorde FROM dino_historico GROUP BY id_usuario ORDER BY recorde DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['id_usuario'])) {
        $addBestGame($best_game_users, (int)$row['id_usuario'], 'Pinguim');
    }
} catch (PDOException $e) {
}

try {
    $stmt = $pdo->query("SELECT vencedor_id, COUNT(*) AS vitorias FROM naval_salas WHERE status = 'fim' GROUP BY vencedor_id ORDER BY vitorias DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['vencedor_id'])) {
        $addBestGame($best_game_users, (int)$row['vencedor_id'], 'Batalha Naval');
    }
} catch (PDOException $e) {
}

try {
    $stmt = $pdo->query("SELECT vencedor, COUNT(*) AS vitorias FROM xadrez_partidas WHERE status = 'finalizada' GROUP BY vencedor ORDER BY vitorias DESC LIMIT 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!empty($row['vencedor'])) {
        $addBestGame($best_game_users, (int)$row['vencedor'], 'Xadrez');
    }
} catch (PDOException $e) {
}

$tab_labels = [
    'geral' => 'Geral',
    'ELITE' => 'Elite',
    'NEXT' => 'Next',
    'RISE' => 'Rise',
    'ROOKIE' => 'Rookie'
];
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ranking Geral - FBA games</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-dark: #121212;
            --secondary-dark: #1e1e1e;
            --border-dark: #333;
            --accent-green: #FC082B;
        }

        body {
            background-color: var(--primary-dark);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #e0e0e0;
        }

        .navbar-custom {
            background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%);
            border-bottom: 1px solid var(--border-dark);
            padding: 15px 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
        }

        .brand-name {
            font-size: 1.4rem;
            font-weight: 900;
            color: #fff;
            text-decoration: none;
        }

        .saldo-badge {
            background-color: var(--accent-green);
            color: #000;
            padding: 8px 16px;
            border-radius: 25px;
            font-weight: 800;
            font-size: 1em;
        }

        .container-main {
            padding: 40px 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .section-title {
            color: #999;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 20px 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i { color: var(--accent-green); font-size: 1.2rem; }

        .ranking-card {
            background-color: var(--secondary-dark);
            border: 1px solid var(--border-dark);
            border-radius: 12px;
            padding: 20px;
        }

        .ranking-item {
            display: grid;
            grid-template-columns: 40px 1fr 120px 120px 120px;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 0.95rem;
            gap: 10px;
        }

        .ranking-item.header-row {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #999;
            border-bottom: 1px solid var(--border-dark);
        }

        .ranking-item:last-child { border-bottom: none; }

        .ranking-position {
            font-weight: 800;
            color: var(--accent-green);
        }

        .ranking-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .best-game-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 2px 6px;
            border-radius: 999px;
            background: #ffd54f;
            color: #000;
            margin-left: 6px;
            white-space: nowrap;
        }
        .best-game-flappy { background: #d32f2f; color: #fff; }
        .best-game-xadrez { background: #fff; color: #000; }
        .best-game-batalha-naval { background: #1976d2; color: #fff; }
        .best-game-pinguim { background: #7b1fa2; color: #fff; }

        .ranking-value { font-weight: 700; color: #fff; text-align: right; }

        .nav-tabs .nav-link {
            color: #ccc;
            border: none;
            border-bottom: 2px solid transparent;
        }

        .nav-tabs .nav-link.active {
            color: #fff;
            background-color: transparent;
            border-bottom-color: var(--accent-green);
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            background-color: var(--secondary-dark);
            border: 1px dashed var(--border-dark);
            border-radius: 12px;
            margin: 20px 0;
        }

        .empty-icon {
            font-size: 3rem;
            opacity: 0.3;
            margin-bottom: 10px;
        }

        .empty-text {
            color: #666;
            font-size: 1.1rem;
        }
    </style>
</head>
<body>

<div class="navbar-custom d-flex justify-content-between align-items-center sticky-top">
    <a href="../index.php" class="brand-name">🎮 FBA games</a>
    <div class="d-flex align-items-center gap-3">
        <a href="../index.php" class="btn btn-sm btn-outline-light">Voltar</a>
        <span class="saldo-badge"><i class="bi bi-coin me-1"></i><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?> moedas</span>
        <span class="saldo-badge"><i class="bi bi-gem me-1"></i><?= number_format($meu_perfil['fba_points'] ?? 0, 0, ',', '.') ?> FBA POINTS</span>
        <a href="alterar-senha.php" class="btn btn-sm btn-outline-warning" title="Alterar senha">
            <i class="bi bi-shield-lock"></i>
        </a>
        <a href="../auth/logout.php" class="btn btn-sm btn-outline-danger border-0">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</div>

<div class="container-main">
    <h6 class="section-title"><i class="bi bi-trophy"></i>Ranking Geral</h6>

    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
        <form class="row g-2 align-items-end" method="get" id="dateFilterForm">
            <div class="col-auto">
                <label class="form-label small text-secondary mb-1" for="startDate">Dia inicial</label>
                <input type="date" class="form-control form-control-sm" id="startDate" name="start_date_ui">
            </div>
            <div class="col-auto">
                <label class="form-label small text-secondary mb-1" for="endDate">Dia final</label>
                <input type="date" class="form-control form-control-sm" id="endDate" name="end_date_ui">
            </div>
            <input type="hidden" name="start_date" id="startDateValue" value="<?= htmlspecialchars($filterStart) ?>">
            <input type="hidden" name="end_date" id="endDateValue" value="<?= htmlspecialchars($filterEnd) ?>">
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-warning">Filtrar</button>
                <a class="btn btn-sm btn-outline-light" href="ranking-geral.php">Limpar</a>
            </div>
        </form>

        <div class="d-flex align-items-center gap-2">
            <span class="text-secondary small">Ordenar por:</span>
            <select class="form-select form-select-sm w-auto" id="rankingSort">
                <option value="pontos">Moedas</option>
                <option value="gems">FBA Points</option>
                <option value="acertos">Acertos</option>
            </select>
        </div>
    </div>

    <ul class="nav nav-tabs mb-4" id="rankingTabs" role="tablist">
        <?php foreach ($tab_labels as $tabKey => $tabLabel): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $tabKey === 'geral' ? 'active' : '' ?>" id="tab-<?= $tabKey ?>" data-bs-toggle="tab" data-bs-target="#pane-<?= $tabKey ?>" type="button" role="tab">
                    <?= $tabLabel ?>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="tab-content" id="rankingTabsContent">
        <div class="tab-pane fade show active" id="pane-geral" role="tabpanel">
            <div class="ranking-card">
                <?php if (empty($ranking_geral)): ?>
                    <div class="empty-state">
                        <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                        <div class="empty-text">Sem dados ainda</div>
                    </div>
                <?php else: ?>
                    <div class="ranking-item header-row">
                        <span>#</span>
                        <span>Time</span>
                        <span class="text-end">Moedas</span>
                        <span class="text-end">FBA Points</span>
                        <span class="text-end">Acertos</span>
                    </div>
                    <?php foreach ($ranking_geral as $idx => $jogador): ?>
                        <div class="ranking-item" data-pontos="<?= (int)$jogador['pontos'] ?>" data-gems="<?= ((int)($jogador['acertos'] ?? 0)) * 100 ?>" data-acertos="<?= (int)($jogador['acertos'] ?? 0) ?>">
                            <span class="ranking-position"><?= $idx + 1 ?></span>
                            <div class="ranking-name">
                                <?= htmlspecialchars($jogador['nome']) ?>
                                <?php if (!empty($jogador['league'])): ?>
                                    <small class="text-secondary">(<?= htmlspecialchars($jogador['league']) ?>)</small>
                                <?php endif; ?>
                                <?php if (!empty($best_game_users[(int)($jogador['id'] ?? 0)])): ?>
                                    <?php foreach ($best_game_users[(int)$jogador['id']] as $gameLabel): 
                                        $cls = 'best-game-' . strtolower(str_replace(' ', '-', $gameLabel));
                                        $icon = $bestGameIcons[$gameLabel] ?? '⭐';
                                    ?>
                                        <span class="best-game-tag <?= $cls ?>"><?= $icon ?> Melhor em <?= htmlspecialchars($gameLabel) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="small text-info mt-1" style="font-size:0.85em;">
                                    <i class="bi bi-hand-index-thumb"></i> Tapas: <strong><?= (int)($jogador['numero_tapas'] ?? 0) ?></strong>
                                </div>
                            </div>
                            <span class="ranking-value"><?= number_format($jogador['pontos'], 0, ',', '.') ?></span>
                            <span class="ranking-value"><?= number_format((int)($jogador['fba_points'] ?? 0), 0, ',', '.') ?></span>
                            <span class="ranking-value"><?= (int)($jogador['acertos'] ?? 0) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php foreach (['ELITE', 'NEXT', 'RISE', 'ROOKIE'] as $liga): ?>
            <div class="tab-pane fade" id="pane-<?= $liga ?>" role="tabpanel">
                <div class="ranking-card">
                    <?php if (empty($ranking_por_liga[$liga])): ?>
                        <div class="empty-state">
                            <div class="empty-icon"><i class="bi bi-inbox"></i></div>
                            <div class="empty-text">Sem dados ainda</div>
                        </div>
                    <?php else: ?>
                        <div class="ranking-item header-row">
                            <span>#</span>
                            <span>Time</span>
                            <span class="text-end">Moedas</span>
                            <span class="text-end">FBA Points</span>
                            <span class="text-end">Acertos</span>
                        </div>
                        <?php foreach ($ranking_por_liga[$liga] as $idx => $jogador): ?>
                            <div class="ranking-item" data-pontos="<?= (int)$jogador['pontos'] ?>" data-gems="<?= ((int)($jogador['acertos'] ?? 0)) * 100 ?>" data-acertos="<?= (int)($jogador['acertos'] ?? 0) ?>">
                                <span class="ranking-position"><?= $idx + 1 ?></span>
                                <div class="ranking-name">
                                    <?= htmlspecialchars($jogador['nome']) ?>
                                    <?php if (!empty($best_game_users[(int)($jogador['id'] ?? 0)])): ?>
                                        <?php foreach ($best_game_users[(int)$jogador['id']] as $gameLabel): 
                                            $cls = 'best-game-' . strtolower(str_replace(' ', '-', $gameLabel));
                                            $icon = $bestGameIcons[$gameLabel] ?? '⭐';
                                        ?>
                                            <span class="best-game-tag <?= $cls ?>"><?= $icon ?> Melhor em <?= htmlspecialchars($gameLabel) ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <div class="small text-info mt-1" style="font-size:0.85em;">
                                        <i class="bi bi-hand-index-thumb"></i> Tapas: <strong><?= (int)($jogador['numero_tapas'] ?? 0) ?></strong>
                                    </div>
                                </div>
                                <span class="ranking-value"><?= number_format($jogador['pontos'], 0, ',', '.') ?></span>
                                <span class="ranking-value"><?= number_format((int)($jogador['fba_points'] ?? 0), 0, ',', '.') ?></span>
                                <span class="ranking-value"><?= (int)($jogador['acertos'] ?? 0) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function sortRankingTab(tabPane, field) {
            if (!tabPane) return;

            const list = tabPane.querySelector('.ranking-card');
            if (!list) return;

            const items = Array.from(list.querySelectorAll('.ranking-item')).filter(item => !item.classList.contains('header-row'));
            if (!items.length) return;

            items.sort((a, b) => {
                const av = parseInt(a.dataset[field] || '0', 10);
                const bv = parseInt(b.dataset[field] || '0', 10);
                return bv - av;
            });

            const header = list.querySelector('.header-row');
            if (header) {
                list.innerHTML = '';
                list.appendChild(header);
            } else {
                list.innerHTML = '';
            }
            items.forEach(item => list.appendChild(item));
        }

        const dateForm = document.getElementById('dateFilterForm');
        const startDateInput = document.getElementById('startDate');
        const endDateInput = document.getElementById('endDate');
        const startDateValue = document.getElementById('startDateValue');
        const endDateValue = document.getElementById('endDateValue');

        const toIsoDate = (value) => {
            if (!value) return '';
            const parts = value.split('/');
            if (parts.length !== 3) return '';
            const [day, month, year] = parts;
            if (!day || !month || !year) return '';
            return `${year}-${month}-${day}`;
        };

        const toBrDate = (value) => {
            if (!value) return '';
            const parts = value.split('-');
            if (parts.length !== 3) return '';
            const [year, month, day] = parts;
            if (!day || !month || !year) return '';
            return `${day}/${month}/${year}`;
        };

        if (startDateInput && startDateValue?.value) {
            startDateInput.value = toIsoDate(startDateValue.value);
        }
        if (endDateInput && endDateValue?.value) {
            endDateInput.value = toIsoDate(endDateValue.value);
        }

        if (dateForm) {
            dateForm.addEventListener('submit', () => {
                if (startDateValue && startDateInput) {
                    startDateValue.value = toBrDate(startDateInput.value);
                }
                if (endDateValue && endDateInput) {
                    endDateValue.value = toBrDate(endDateInput.value);
                }
            });
        }

        function applyRankingSort() {
            const sortValue = document.getElementById('rankingSort')?.value || 'pontos';
            const activeTab = document.querySelector('.tab-pane.active');
            sortRankingTab(activeTab, sortValue);
        }

        document.getElementById('rankingSort')?.addEventListener('change', applyRankingSort);
        document.getElementById('rankingTabs')?.addEventListener('shown.bs.tab', applyRankingSort);
        applyRankingSort();
    </script>
</body>

<?php if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1): ?>
<div class="container my-5">
    <div class="card border-danger shadow-lg" id="admin-tapas-card">
        <div class="card-header bg-danger text-white fw-bold"><i class="bi bi-hand-index-thumb"></i> Administração de Tapas</div>
        <div class="card-body">
            <div id="tapa-msg"></div>
            <h6 class="mb-3">Usuários com pelo menos 1 tapa:</h6>
            <ul class="list-group mb-4" id="lista-tapas"></ul>
            <h6 class="mb-2">Adicionar tapa para um usuário:</h6>
            <form id="form-add-tapa" class="row g-2 align-items-center">
                <div class="col-auto">
                    <select name="adicionar_id" id="adicionar_id" class="form-select" required></select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-success"><i class="bi bi-plus"></i> Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
async function fetchTapasAdmin() {
    const res = await fetch('ranking-geral.php?ajax_tapas=1');
    const data = await res.json();
    // Lista tapas
    const lista = document.getElementById('lista-tapas');
    lista.innerHTML = '';
    if (data.usuarios_tapas.length === 0) {
        lista.innerHTML = '<li class="list-group-item text-muted">Nenhum usuário com tapas.</li>';
    } else {
        data.usuarios_tapas.forEach(u => {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-center';
            li.innerHTML = `<span>${u.nome} <span class='badge bg-info text-dark ms-2'>Tapas: ${u.numero_tapas}</span></span>` +
                `<button class='btn btn-sm btn-danger' onclick='removerTapa(${u.id})'><i class="bi bi-dash"></i> Remover</button>`;
            lista.appendChild(li);
        });
    }
    // Dropdown
    const sel = document.getElementById('adicionar_id');
    sel.innerHTML = '<option value="">Selecione o usuário</option>';
    data.todos_usuarios.forEach(u => {
        sel.innerHTML += `<option value="${u.id}">${u.nome}</option>`;
    });
}
async function removerTapa(id) {
    const res = await fetch('ranking-geral.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `admin_tapa_action=remover&remover_id=${id}&ajax=1`
    });
    const data = await res.json();
    document.getElementById('tapa-msg').innerHTML = `<div class='alert alert-success py-2'>${data.msg}</div>`;
    fetchTapasAdmin();
}
document.getElementById('form-add-tapa').addEventListener('submit', async function(e) {
    e.preventDefault();
    const id = document.getElementById('adicionar_id').value;
    if (!id) return;
    const res = await fetch('ranking-geral.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `admin_tapa_action=adicionar&adicionar_id=${id}&ajax=1`
    });
    const data = await res.json();
    document.getElementById('tapa-msg').innerHTML = `<div class='alert alert-success py-2'>${data.msg}</div>`;
    fetchTapasAdmin();
});
fetchTapasAdmin();
</script>
<?php endif; ?>
</html>