<?php
// ranking-geral.php - RANKING COMPLETO COM ABAS (FBA games)
session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar perfil: " . $e->getMessage());
}

$ranking_geral = [];
$ranking_por_liga = [
    'ELITE' => [],
    'RISE' => [],
    'NEXT' => [],
    'ROOKIE' => []
];

try {
    $stmt = $pdo->query("
        SELECT u.nome, u.pontos, u.league,
            (
                SELECT COUNT(*)
                FROM palpites p
                JOIN opcoes o ON p.opcao_id = o.id
                JOIN eventos e ON o.evento_id = e.id
                WHERE p.id_usuario = u.id
                  AND e.status = 'encerrada'
                  AND e.vencedor_opcao_id IS NOT NULL
                  AND e.vencedor_opcao_id = p.opcao_id
            ) as acertos
        FROM usuarios u
        ORDER BY u.pontos DESC
    ");
    $ranking_geral = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $ranking_geral = [];
}

try {
    $stmtLiga = $pdo->prepare("
        SELECT u.nome, u.pontos, u.league,
            (
                SELECT COUNT(*)
                FROM palpites p
                JOIN opcoes o ON p.opcao_id = o.id
                JOIN eventos e ON o.evento_id = e.id
                WHERE p.id_usuario = u.id
                  AND e.status = 'encerrada'
                  AND e.vencedor_opcao_id IS NOT NULL
                  AND e.vencedor_opcao_id = p.opcao_id
            ) as acertos
        FROM usuarios u
        WHERE u.league = :league
        ORDER BY u.pontos DESC
    ");
    foreach (array_keys($ranking_por_liga) as $liga) {
        $stmtLiga->execute([':league' => $liga]);
        $ranking_por_liga[$liga] = $stmtLiga->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (PDOException $e) {
    foreach (array_keys($ranking_por_liga) as $liga) {
        $ranking_por_liga[$liga] = [];
    }
}

$tab_labels = [
    'geral' => 'Geral',
    'ELITE' => 'Elite',
    'RISE' => 'Rise',
    'NEXT' => 'Next',
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
            grid-template-columns: 40px 1fr 120px 120px;
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
        <span class="saldo-badge"><i class="bi bi-coin me-1"></i><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?> pts</span>
        <a href="../auth/logout.php" class="btn btn-sm btn-outline-danger border-0">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</div>

<div class="container-main">
    <h6 class="section-title"><i class="bi bi-trophy"></i>Ranking Geral</h6>

    <div class="d-flex justify-content-end mb-3">
        <div class="d-flex align-items-center gap-2">
            <span class="text-secondary small">Ordenar por:</span>
            <select class="form-select form-select-sm w-auto" id="rankingSort">
                <option value="pontos">Pontos</option>
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
                        <span class="text-end">Pontos</span>
                        <span class="text-end">Acertos</span>
                    </div>
                    <?php foreach ($ranking_geral as $idx => $jogador): ?>
                        <div class="ranking-item" data-pontos="<?= (int)$jogador['pontos'] ?>" data-acertos="<?= (int)($jogador['acertos'] ?? 0) ?>">
                            <span class="ranking-position"><?= $idx + 1 ?></span>
                            <div class="ranking-name">
                                <?= htmlspecialchars($jogador['nome']) ?>
                                <?php if (!empty($jogador['league'])): ?>
                                    <small class="text-secondary">(<?= htmlspecialchars($jogador['league']) ?>)</small>
                                <?php endif; ?>
                            </div>
                            <span class="ranking-value"><?= number_format($jogador['pontos'], 0, ',', '.') ?> pts</span>
                            <span class="ranking-value"><?= (int)($jogador['acertos'] ?? 0) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php foreach (['ELITE', 'RISE', 'NEXT', 'ROOKIE'] as $liga): ?>
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
                            <span class="text-end">Pontos</span>
                            <span class="text-end">Acertos</span>
                        </div>
                        <?php foreach ($ranking_por_liga[$liga] as $idx => $jogador): ?>
                            <div class="ranking-item" data-pontos="<?= (int)$jogador['pontos'] ?>" data-acertos="<?= (int)($jogador['acertos'] ?? 0) ?>">
                                <span class="ranking-position"><?= $idx + 1 ?></span>
                                <div class="ranking-name">
                                    <?= htmlspecialchars($jogador['nome']) ?>
                                </div>
                                <span class="ranking-value"><?= number_format($jogador['pontos'], 0, ',', '.') ?> pts</span>
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
</html>
