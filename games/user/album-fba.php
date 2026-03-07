<?php
session_start();
require '../core/conexao.php';
require '../core/avatar.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = (int)$_SESSION['user_id'];

try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
    if (!$meu_perfil) {
        throw new Exception('Usuário não encontrado.');
    }
} catch (Exception $e) {
    die("Erro ao carregar perfil: " . $e->getMessage());
}

$open_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['abrir_pacote'])) {
    $tipo_pacote = $_POST['tipo_pacote'] ?? '';
    if (isset($LOOT_BOXES[$tipo_pacote])) {
        $open_result = abrirLootBox($pdo, $user_id, $tipo_pacote);
        if (!empty($open_result['sucesso']) && isset($open_result['pontos_restantes'])) {
            $meu_perfil['pontos'] = (int)$open_result['pontos_restantes'];
        } else {
            $stmtPts = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id");
            $stmtPts->execute([':id' => $user_id]);
            $meu_perfil['pontos'] = (int)$stmtPts->fetchColumn();
        }
    } else {
        $open_result = [
            'sucesso' => false,
            'mensagem' => 'Pacote inválido.'
        ];
    }
}

$inventario = obterInventario($pdo, $user_id);
$itens_possuidos = [];

foreach ($inventario as $item) {
    $chave = $item['categoria'] . '::' . $item['item_id'];
    $itens_possuidos[$chave] = $item;
}

$categoria_nome = [
    'colors' => 'Cores',
    'hardware' => 'Hardware',
    'clothing' => 'Uniforme',
    'footwear' => 'Calçados',
    'elite' => 'Elite'
];

$raridade_nome = [
    'common' => 'Comum',
    'rare' => 'Rara',
    'epic' => 'Épica',
    'legendary' => 'Lendária',
    'mythic' => 'Mítica'
];

$cards_album = [];
$total_cards = 0;
$total_obtidas = 0;
$raridade_stats = [
    'common' => ['total' => 0, 'owned' => 0],
    'rare' => ['total' => 0, 'owned' => 0],
    'epic' => ['total' => 0, 'owned' => 0],
    'legendary' => ['total' => 0, 'owned' => 0],
    'mythic' => ['total' => 0, 'owned' => 0]
];

foreach ($AVATAR_COMPONENTES as $categoria => $items) {
    if (!isset($categoria_nome[$categoria])) {
        continue;
    }

    foreach ($items as $item_id => $item_data) {
        $preco = (int)($item_data['preco'] ?? 0);
        if ($preco <= 0) {
            continue;
        }

        $raridade = $item_data['rarity'] ?? 'common';
        $chave = $categoria . '::' . $item_id;
        $owned = isset($itens_possuidos[$chave]);

        $cards_album[] = [
            'categoria' => $categoria,
            'categoria_nome' => $categoria_nome[$categoria],
            'item_id' => $item_id,
            'nome' => $item_data['nome'] ?? 'Item',
            'raridade' => $raridade,
            'raridade_nome' => $raridade_nome[$raridade] ?? 'Comum',
            'owned' => $owned,
            'obtido_em' => $owned ? ($itens_possuidos[$chave]['data_obtencao'] ?? null) : null
        ];

        $total_cards++;
        if (isset($raridade_stats[$raridade])) {
            $raridade_stats[$raridade]['total']++;
        }

        if ($owned) {
            $total_obtidas++;
            if (isset($raridade_stats[$raridade])) {
                $raridade_stats[$raridade]['owned']++;
            }
        }
    }
}

$percentual = $total_cards > 0 ? round(($total_obtidas / $total_cards) * 100) : 0;

usort($cards_album, function ($a, $b) {
    $peso = ['mythic' => 1, 'legendary' => 2, 'epic' => 3, 'rare' => 4, 'common' => 5];
    $wA = $peso[$a['raridade']] ?? 99;
    $wB = $peso[$b['raridade']] ?? 99;

    if ($wA !== $wB) {
        return $wA <=> $wB;
    }
    if ($a['owned'] !== $b['owned']) {
        return $a['owned'] ? -1 : 1;
    }
    return strcmp($a['nome'], $b['nome']);
});
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Álbum FBA - Cartas e Pacotes</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&family=Bebas+Neue&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

    <style>
        :root {
            --bg-0: #090b10;
            --bg-1: #101421;
            --panel: rgba(17, 24, 39, 0.78);
            --panel-border: rgba(148, 163, 184, 0.2);
            --text-1: #f8fafc;
            --text-2: #94a3b8;
            --accent: #22d3ee;
            --accent-2: #f97316;
            --ok: #22c55e;
            --danger: #ef4444;
            --common: #94a3b8;
            --rare: #38bdf8;
            --epic: #a78bfa;
            --legendary: #f59e0b;
            --mythic: #f43f5e;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Manrope', sans-serif;
            color: var(--text-1);
            background:
                radial-gradient(1200px 500px at -10% -20%, rgba(34, 211, 238, 0.13), transparent 70%),
                radial-gradient(900px 420px at 110% 0%, rgba(249, 115, 22, 0.12), transparent 65%),
                linear-gradient(145deg, var(--bg-0), var(--bg-1));
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.035) 1px, transparent 1px);
            background-size: 24px 24px;
            pointer-events: none;
            mask-image: radial-gradient(ellipse at center, black 45%, transparent 100%);
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(12px);
            background: rgba(7, 10, 16, 0.74);
            border-bottom: 1px solid var(--panel-border);
        }

        .topbar-wrap {
            max-width: 1240px;
            margin: 0 auto;
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: inherit;
        }

        .brand-mark {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            background: linear-gradient(135deg, rgba(34, 211, 238, 0.3), rgba(249, 115, 22, 0.35));
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .brand-title {
            font-family: 'Bebas Neue', sans-serif;
            letter-spacing: 1px;
            font-size: 1.7rem;
            line-height: 1;
        }

        .saldo-chip {
            border-radius: 999px;
            padding: 8px 14px;
            background: rgba(34, 211, 238, 0.12);
            border: 1px solid rgba(34, 211, 238, 0.4);
            font-weight: 800;
            white-space: nowrap;
        }

        .page {
            max-width: 1240px;
            margin: 0 auto;
            padding: 28px 18px 44px;
        }

        .glass {
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: 22px;
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.34);
        }

        .hero {
            padding: 26px;
            display: grid;
            grid-template-columns: 1.25fr 1fr;
            gap: 18px;
            margin-bottom: 22px;
        }

        .hero h1 {
            margin: 0;
            font-family: 'Bebas Neue', sans-serif;
            letter-spacing: 1px;
            font-size: clamp(2rem, 5vw, 3.3rem);
            line-height: 0.95;
        }

        .hero p {
            margin: 10px 0 0;
            color: var(--text-2);
            max-width: 65ch;
        }

        .progress-box {
            display: grid;
            gap: 10px;
        }

        .progress-line {
            height: 14px;
            border-radius: 999px;
            overflow: hidden;
            background: rgba(148, 163, 184, 0.18);
            border: 1px solid rgba(148, 163, 184, 0.3);
        }

        .progress-line > span {
            display: block;
            height: 100%;
            width: <?= (int)$percentual ?>%;
            background: linear-gradient(90deg, var(--accent), var(--accent-2));
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .meta-item {
            border-radius: 14px;
            border: 1px solid var(--panel-border);
            background: rgba(9, 14, 23, 0.7);
            padding: 12px;
        }

        .meta-label {
            color: var(--text-2);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: .6px;
        }

        .meta-value {
            font-size: 1.4rem;
            font-weight: 800;
            margin-top: 4px;
        }

        .section-title {
            margin: 0 0 14px;
            font-family: 'Bebas Neue', sans-serif;
            letter-spacing: .8px;
            font-size: 2rem;
        }

        .packs {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 22px;
        }

        .pack-card {
            padding: 16px;
            border-radius: 18px;
            border: 1px solid var(--panel-border);
            background: linear-gradient(170deg, rgba(10, 18, 31, 0.85), rgba(12, 13, 20, 0.92));
            position: relative;
            overflow: hidden;
        }

        .pack-card::after {
            content: "";
            position: absolute;
            right: -50px;
            top: -40px;
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: radial-gradient(circle, var(--pack-color, rgba(255,255,255,.2)), transparent 70%);
        }

        .pack-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .pack-name {
            font-weight: 800;
            font-size: 1.1rem;
            margin: 0;
        }

        .pack-price {
            font-weight: 800;
            color: #fde68a;
        }

        .chance-list {
            margin: 10px 0 14px;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 6px;
            font-size: .82rem;
        }

        .chance-pill {
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, .25);
            background: rgba(15, 23, 42, .75);
            padding: 4px 8px;
            white-space: nowrap;
        }

        .btn-open {
            width: 100%;
            border: none;
            border-radius: 12px;
            padding: 11px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            color: #0b1020;
        }

        .btn-open:disabled {
            opacity: .55;
            filter: grayscale(.4);
        }

        .album-shell {
            padding: 18px;
        }

        .album-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .filter-wrap {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-btn {
            border: 1px solid var(--panel-border);
            background: rgba(15, 23, 42, .62);
            color: var(--text-1);
            border-radius: 999px;
            font-size: .82rem;
            padding: 6px 12px;
            font-weight: 700;
        }

        .filter-btn.active {
            border-color: rgba(34, 211, 238, 0.65);
            color: #67e8f9;
        }

        .album-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
            gap: 12px;
        }

        .album-card {
            border-radius: 16px;
            border: 1px solid var(--panel-border);
            overflow: hidden;
            background: rgba(10, 14, 23, .8);
            transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
        }

        .album-card:hover {
            transform: translateY(-3px);
            border-color: rgba(148, 163, 184, .45);
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.32);
        }

        .card-top {
            padding: 12px;
            border-bottom: 1px solid rgba(148, 163, 184, .16);
            min-height: 112px;
            display: grid;
            align-content: space-between;
            background: linear-gradient(160deg, rgba(9, 16, 30, 0.95), rgba(7, 11, 20, 0.72));
        }

        .rarity-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            display: inline-block;
            margin-right: 6px;
            box-shadow: 0 0 8px currentColor;
        }

        .album-card h6 {
            margin: 0;
            font-size: 1rem;
            font-weight: 800;
            line-height: 1.25;
        }

        .card-body-info {
            padding: 11px 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }

        .card-tag {
            font-size: 0.72rem;
            border-radius: 999px;
            padding: 4px 8px;
            font-weight: 700;
            background: rgba(51, 65, 85, 0.38);
            border: 1px solid rgba(148, 163, 184, 0.2);
        }

        .owned {
            color: var(--ok);
            font-weight: 800;
            font-size: .78rem;
            white-space: nowrap;
        }

        .locked {
            opacity: .48;
            filter: saturate(.45);
        }

        .locked .owned {
            color: #fca5a5;
        }

        .rarity-common { color: var(--common); }
        .rarity-rare { color: var(--rare); }
        .rarity-epic { color: var(--epic); }
        .rarity-legendary { color: var(--legendary); }
        .rarity-mythic { color: var(--mythic); }

        .result-card {
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, .35);
            background: linear-gradient(160deg, rgba(8, 12, 22, .92), rgba(20, 17, 32, .95));
            padding: 18px;
        }

        .result-rarity {
            display: inline-flex;
            border-radius: 999px;
            padding: 6px 12px;
            border: 1px solid currentColor;
            font-size: .82rem;
            font-weight: 800;
        }

        @media (max-width: 992px) {
            .hero {
                grid-template-columns: 1fr;
            }
            .packs {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 576px) {
            .meta-grid {
                grid-template-columns: 1fr;
            }
            .brand-title {
                font-size: 1.45rem;
            }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-wrap">
            <a class="brand" href="../index.php">
                <span class="brand-mark"><i class="bi bi-collection-fill"></i></span>
                <span class="brand-title">Album FBA</span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <a href="../index.php" class="btn btn-outline-light btn-sm">Painel</a>
                <span class="saldo-chip"><i class="bi bi-coin me-1"></i><?= number_format((int)$meu_perfil['pontos'], 0, ',', '.') ?> pts</span>
            </div>
        </div>
    </header>

    <main class="page">
        <section class="hero glass">
            <div>
                <h1>Colecione, Abra Pacotes e Complete o Album</h1>
                <p>
                    Sistema profissional de cartas do FBA games: abra pacotes, desbloqueie itens por raridade e acompanhe sua coleção em tempo real.
                </p>
            </div>
            <div class="progress-box">
                <div class="meta-grid">
                    <div class="meta-item">
                        <div class="meta-label">Jogador</div>
                        <div class="meta-value"><?= htmlspecialchars($meu_perfil['nome']) ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Obtidas</div>
                        <div class="meta-value"><?= (int)$total_obtidas ?>/<?= (int)$total_cards ?></div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Progresso</div>
                        <div class="meta-value"><?= (int)$percentual ?>%</div>
                    </div>
                </div>
                <div class="progress-line" aria-label="Progresso do álbum">
                    <span></span>
                </div>
            </div>
        </section>

        <h2 class="section-title">Pacotes</h2>
        <section class="packs">
            <?php foreach ($LOOT_BOXES as $box_key => $box): ?>
                <article class="pack-card glass" style="--pack-color: <?= htmlspecialchars($box['cor']) ?>;">
                    <div class="pack-head">
                        <div>
                            <p class="pack-name mb-0"><?= htmlspecialchars($box['nome']) ?></p>
                            <small class="text-secondary"><?= htmlspecialchars($box['descricao']) ?></small>
                        </div>
                        <div class="fs-4"><?= htmlspecialchars($box['icon']) ?></div>
                    </div>
                    <div class="pack-price"><i class="bi bi-coin me-1"></i><?= (int)$box['preco'] ?> pts</div>
                    <div class="chance-list">
                        <span class="chance-pill">Comum <?= (int)$box['chance_comum'] ?>%</span>
                        <span class="chance-pill">Rara <?= (int)$box['chance_rara'] ?>%</span>
                        <span class="chance-pill">Épica <?= (int)$box['chance_epica'] ?>%</span>
                        <span class="chance-pill">Lend/Mítica <?= (int)$box['chance_lendaria'] + (int)$box['chance_mitica'] ?>%</span>
                    </div>
                    <form method="post">
                        <input type="hidden" name="tipo_pacote" value="<?= htmlspecialchars($box_key) ?>">
                        <button class="btn-open" type="submit" name="abrir_pacote" value="1" <?= ((int)$meu_perfil['pontos'] < (int)$box['preco']) ? 'disabled' : '' ?>>
                            Abrir pacote
                        </button>
                    </form>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="album-shell glass">
            <div class="album-head">
                <h2 class="section-title mb-0">Album de Cartas</h2>
                <div class="filter-wrap">
                    <button class="filter-btn active" data-filter="all">Todas</button>
                    <button class="filter-btn" data-filter="owned">Obtidas</button>
                    <button class="filter-btn" data-filter="locked">Não obtidas</button>
                    <button class="filter-btn" data-rarity="mythic">Míticas</button>
                </div>
            </div>

            <div class="album-grid" id="albumGrid">
                <?php foreach ($cards_album as $card): ?>
                    <?php
                        $is_owned = !empty($card['owned']);
                        $raridade_class = 'rarity-' . $card['raridade'];
                    ?>
                    <article
                        class="album-card <?= $is_owned ? '' : 'locked' ?>"
                        data-status="<?= $is_owned ? 'owned' : 'locked' ?>"
                        data-rarity="<?= htmlspecialchars($card['raridade']) ?>"
                    >
                        <div class="card-top">
                            <div class="<?= $raridade_class ?>">
                                <span class="rarity-dot"></span>
                                <small class="fw-bold"><?= htmlspecialchars($card['raridade_nome']) ?></small>
                            </div>
                            <h6><?= htmlspecialchars($card['nome']) ?></h6>
                        </div>
                        <div class="card-body-info">
                            <span class="card-tag"><?= htmlspecialchars($card['categoria_nome']) ?></span>
                            <span class="owned"><?= $is_owned ? 'Desbloqueada' : 'Bloqueada' ?></span>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </main>

    <div class="modal fade" id="resultadoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 bg-transparent">
                <div class="result-card">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h5 class="m-0 fw-bold">Resultado do Pacote</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div id="resultContent"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const grid = document.getElementById('albumGrid');
        const filterButtons = document.querySelectorAll('.filter-btn');

        const applyFilter = (mode = 'all', rarity = null) => {
            const cards = grid.querySelectorAll('.album-card');
            cards.forEach((card) => {
                const status = card.dataset.status;
                const cardRarity = card.dataset.rarity;
                let show = true;

                if (mode === 'owned') show = status === 'owned';
                if (mode === 'locked') show = status === 'locked';
                if (rarity) show = cardRarity === rarity;

                card.style.display = show ? '' : 'none';
            });
        };

        filterButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                filterButtons.forEach((x) => x.classList.remove('active'));
                btn.classList.add('active');
                applyFilter(btn.dataset.filter || 'all', btn.dataset.rarity || null);
            });
        });

        const openButtons = document.querySelectorAll('.btn-open');
        openButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                btn.dataset.originalText = btn.textContent;
                btn.textContent = 'Abrindo...';
            });
        });

        <?php if (is_array($open_result)): ?>
            (() => {
                const modalEl = document.getElementById('resultadoModal');
                const resultContent = document.getElementById('resultContent');
                const modal = new bootstrap.Modal(modalEl);
                const result = <?= json_encode($open_result, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

                if (result.sucesso) {
                    const rarityClass = `rarity-${result.raridade || 'common'}`;
                    const categoria = (result.categoria || '').replace('colors', 'Cores').replace('hardware', 'Hardware').replace('clothing', 'Uniforme').replace('footwear', 'Calçados').replace('elite', 'Elite');
                    const extraMsg = result.duplicado ? '<div class="text-warning mt-2 fw-bold">Item duplicado detectado: +10 pts bônus</div>' : '';

                    resultContent.innerHTML = `
                        <div class="d-grid gap-2">
                            <span class="result-rarity ${rarityClass}">${(result.raridade || '').toUpperCase()}</span>
                            <h3 class="fw-bold m-0">${result.item_nome || 'Item obtido'}</h3>
                            <div class="text-secondary">Categoria: ${categoria}</div>
                            <div class="text-success fw-bold">Saldo atual: ${(result.pontos_restantes || 0).toLocaleString('pt-BR')} pts</div>
                            ${extraMsg}
                        </div>
                    `;
                } else {
                    resultContent.innerHTML = `<div class="text-danger fw-bold">${result.mensagem || 'Não foi possível abrir o pacote.'}</div>`;
                }

                modal.show();
            })();
        <?php endif; ?>
    </script>
</body>
</html>
