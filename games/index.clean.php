<?php
/**
 * INDEX.PHP - DASHBOARD PRINCIPAL 🚀
 */

session_start();
require 'core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : "";
$erro = isset($_GET['erro']) ? htmlspecialchars($_GET['erro']) : "";

try {
    $stmt = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar usuário: " . $e->getMessage());
}

try {
    $stmt = $pdo->query("
        SELECT id, nome, pontos, league
        FROM usuarios
        ORDER BY pontos DESC
        LIMIT 5
    ");
    $top_5_ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $top_5_ranking = [];
}

// Top 5 por número de acertos em apostas (eventos encerrados)
try {
    $stmt = $pdo->query("
        SELECT
            u.id,
            u.nome,
            u.league,
            COUNT(*) AS acertos,
            COUNT(p.id) AS total_apostas
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        JOIN usuarios u ON p.id_usuario = u.id
        WHERE e.status = 'encerrada'
          AND e.vencedor_opcao_id IS NOT NULL
          AND e.vencedor_opcao_id = p.opcao_id
        GROUP BY u.id, u.nome, u.league
        ORDER BY acertos DESC, total_apostas DESC, u.nome ASC
        LIMIT 5
    ");
    $top_5_acertos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $top_5_acertos = [];
}

$ranking_por_liga = [
    'ELITE' => [],
    'RISE' => [],
    'NEXT' => [],
    'ROOKIE' => []
];

try {
    $stmtLiga = $pdo->prepare("
        SELECT id, nome, pontos
        FROM usuarios
        WHERE league = :league
        ORDER BY pontos DESC
        LIMIT 5
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

try {
    try {
        $stmt = $pdo->query("
            SELECT e.id, e.nome, e.data_limite, e.league
            FROM eventos e
            WHERE e.status = 'aberta' AND e.data_limite > NOW()
            ORDER BY e.data_limite ASC
        ");
        $eventos_abertos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        $stmt = $pdo->query("
            SELECT e.id, e.nome, e.data_limite
            FROM eventos e
            WHERE e.status = 'aberta' AND e.data_limite > NOW()
            ORDER BY e.data_limite ASC
        ");
        $eventos_abertos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($eventos_abertos as &$evento) {
            $evento['league'] = 'GERAL';
        }
        unset($evento);
    }

    $eventos_por_liga = [
        'ELITE' => [],
        'RISE' => [],
        'NEXT' => [],
        'ROOKIE' => [],
        'GERAL' => []
    ];

    foreach ($eventos_abertos as $evento) {
        $liga = strtoupper(trim($evento['league'] ?? 'GERAL'));
        if (!isset($eventos_por_liga[$liga])) {
            $eventos_por_liga[$liga] = [];
        }
        $eventos_por_liga[$liga][] = $evento;
    }

    $ultimos_eventos_abertos = $eventos_abertos; // exibir todas as apostas ativas
    foreach ($ultimos_eventos_abertos as &$evento) {
        $stmtOpcoes = $pdo->prepare("SELECT id, descricao, odd FROM opcoes WHERE evento_id = :eid ORDER BY id ASC");
        $stmtOpcoes->execute([':eid' => $evento['id']]);
        $evento['opcoes'] = $stmtOpcoes->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    unset($evento);
} catch (PDOException $e) {
    $ultimos_eventos_abertos = [];
    $eventos_por_liga = [
        'ELITE' => [],
        'RISE' => [],
        'NEXT' => [],
        'ROOKIE' => [],
        'GERAL' => []
    ];
}

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM palpites p
        JOIN eventos e ON (SELECT evento_id FROM opcoes WHERE id = p.opcao_id) = e.id
        WHERE p.id_usuario = :uid AND e.status = 'aberta'
    ");
    $stmt->execute([':uid' => $user_id]);
    $minhas_apostas_abertas = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) {
    $minhas_apostas_abertas = 0;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM palpites WHERE id_usuario = :uid");
    $stmt->execute([':uid' => $user_id]);
    $total_apostas_usuario = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) {
    $total_apostas_usuario = 0;
}

try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT o.evento_id
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        WHERE p.id_usuario = :uid
    ");
    $stmt->execute([':uid' => $user_id]);
    $eventos_apostados = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $eventos_apostados = array_map('intval', $eventos_apostados);
} catch (PDOException $e) {
    $eventos_apostados = [];
}

try {
    $stmt = $pdo->prepare("
        SELECT p.valor, p.odd_registrada, p.data_palpite, p.opcao_id,
               o.descricao as opcao_descricao,
               e.nome as evento_nome,
               e.status as evento_status,
               e.vencedor_opcao_id
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        WHERE p.id_usuario = :uid
        ORDER BY p.data_palpite DESC
        LIMIT 3
    ");
    $stmt->execute([':uid' => $user_id]);
    $ultimos_palpites = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $ultimos_palpites = [];
}

try {
    $stmt = $pdo->prepare("
        SELECT p.valor, p.odd_registrada, p.data_palpite, p.opcao_id,
               o.descricao as opcao_descricao,
               e.nome as evento_nome,
               e.status as evento_status,
               e.vencedor_opcao_id
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        WHERE p.id_usuario = :uid
        ORDER BY p.data_palpite DESC
        LIMIT 1
    ");
    $stmt->execute([':uid' => $user_id]);
    $ultima_aposta = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    $ultima_aposta = null;
}
?>

<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Apostas</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎮</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-dark: #121212;
            --secondary-dark: #1e1e1e;
            --border-dark: #333;
            --accent-green: #FC082B;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

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
            font-size: 1.5rem;
            font-weight: 900;
            background: linear-gradient(135deg, var(--accent-green), #ff5a6e);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-decoration: none;
        }

        .saldo-badge {
            background-color: var(--accent-green);
            color: #000;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: 800;
            font-size: 1.1em;
            box-shadow: 0 0 15px rgba(252, 8, 43, 0.3);
        }

        .admin-btn {
            background-color: #ff6d00;
            color: white;
            padding: 8px 18px;
            border-radius: 20px;
            text-decoration: none;
            font-weight: bold;
            font-size: 0.9em;
            transition: all 0.3s;
            border: none;
        }

        .admin-btn:hover {
            background-color: #e65100;
            box-shadow: 0 0 12px #ff6d00;
            color: white;
        }

        .container-main {
            padding: 40px 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .section-title {
            color: #999;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 40px 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i { color: var(--accent-green); font-size: 1.2rem; }

        .stat-card {
            background: linear-gradient(135deg, var(--secondary-dark), #2a2a2a);
            border: 1px solid var(--border-dark);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }

        .stat-card:hover {
            border-color: var(--accent-green);
            box-shadow: 0 0 15px rgba(252, 8, 43, 0.1);
        }

        .stat-label {
            color: #999;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--accent-green);
        }

        .game-card {
            background-color: var(--secondary-dark);
            border: 1px solid var(--border-dark);
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 180px;
        }

        .game-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent, rgba(252, 8, 43, 0.1));
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }

        .game-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 30px rgba(252, 8, 43, 0.15);
            border-color: var(--accent-green);
        }

        .game-card:hover::before { opacity: 1; }

        .game-icon { font-size: 3rem; margin-bottom: 12px; display: block; }
        .game-title { font-weight: 700; font-size: 1.1rem; margin-bottom: 5px; }
        .game-subtitle { font-size: 0.85rem; color: #888; }

        .aposta-card {
            background: linear-gradient(135deg, #5a0a16, #9b0d24);
            border: 1px solid #FC082B;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
        }

        .aposta-label {
            color: #ffb3bf;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .aposta-evento {
            font-weight: 700;
            font-size: 1.3rem;
            color: #fff;
            margin-bottom: 10px;
        }

        .aposta-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }

        .aposta-detail-item { display: flex; flex-direction: column; }

        .aposta-detail-label {
            color: #ffb3bf;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .aposta-detail-value {
            font-weight: 800;
            font-size: 1.3rem;
            color: #fff;
            margin-top: 5px;
        }

        .card-evento {
            background-color: var(--secondary-dark);
            border: 1px solid var(--border-dark);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            transition: all 0.3s;
        }

        .card-evento:hover {
            border-color: var(--accent-green);
            box-shadow: 0 0 15px rgba(252, 8, 43, 0.1);
        }

        .evento-titulo {
            font-weight: 700;
            font-size: 1.3rem;
            color: #fff;
            margin-bottom: 5px;
        }

        .evento-data {
            font-size: 0.85rem;
            color: #aaa;
        }

        .opcoes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .card-opcao {
            background: #252525;
            border: 1px solid #444;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            transition: all 0.2s;
        }

        .card-opcao:hover {
            transform: translateY(-3px);
            border-color: var(--accent-green);
            background: #2b2b2b;
        }

        .opcao-nome {
            font-weight: 600;
            color: #eee;
            display: block;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .opcao-odd {
            color: var(--accent-green);
            font-weight: 800;
            font-size: 1.3em;
            display: block;
            margin-bottom: 8px;
            text-shadow: 0 0 5px rgba(252, 8, 43, 0.2);
        }

        .bet-inline {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            align-items: center;
        }

        .ranking-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .ranking-card {
            background-color: var(--secondary-dark);
            border: 1px solid var(--border-dark);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s;
        }

        .ranking-card:hover {
            border-color: var(--accent-green);
            box-shadow: 0 0 15px rgba(252, 8, 43, 0.1);
        }

        .ranking-title {
            font-weight: 700;
            margin-bottom: 15px;
            color: var(--accent-green);
            font-size: 1.1rem;
        }

        .ranking-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 0.95rem;
            gap: 10px;
        }

        .ranking-item:last-child { border-bottom: none; }

        .ranking-position {
            font-weight: 800;
            color: var(--accent-green);
            display: inline-block;
        }

        .ranking-name {
            flex: 1;
            margin: 0 10px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ranking-value { font-weight: 700; color: #fff; text-align: right; }

        .medal-1::before { content: '🥇'; margin-right: 5px; }
        .medal-2::before { content: '🥈'; margin-right: 5px; }
        .medal-3::before { content: '🥉'; margin-right: 5px; }
        .medal-4::before { content: '🏅'; margin-right: 5px; }
        .medal-5::before { content: '🏅'; margin-right: 5px; }

        @media (max-width: 768px) {
            .container-main { padding: 20px 15px; }
            .section-title { font-size: 0.8rem; }
            .stat-card { flex-direction: column; text-align: center; gap: 10px; }
            .game-card { height: 150px; }
            .game-icon { font-size: 2.5rem; }
            .ranking-position { min-width: 25px; }
        }
    </style>
</head>
<body>

<div class="navbar-custom d-flex justify-content-between align-items-center sticky-top">
    <a href="#" class="brand-name">🎮 FBA games</a>
    <div class="d-flex align-items-center gap-3">
        <div class="d-none d-md-flex align-items-center gap-2">
            <div>
                <span style="color: #999; font-size: 0.9rem;">Bem-vindo(a),</span>
                <strong><?= htmlspecialchars($usuario['nome']) ?></strong>
            </div>
            <a href="/user/apostas.php" class="btn btn-sm btn-outline-light">Minhas apostas</a>
        </div>
        <?php if (!empty($usuario['is_admin']) && $usuario['is_admin'] == 1): ?>
            <a href="admin/dashboard.php" class="admin-btn"><i class="bi bi-gear-fill me-1"></i> Admin</a>
        <?php endif; ?>
        <span class="saldo-badge">
            <i class="bi bi-coin me-1"></i><?= number_format($usuario['pontos'], 0, ',', '.') ?> pts
        </span>
        <a href="auth/logout.php" class="btn btn-sm btn-outline-danger border-0">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</div>

<div class="container-main">
    <?php if($msg): ?>
        <div class="alert alert-success border-0 bg-success bg-opacity-10 text-success mb-4 d-flex align-items-center">
            <i class="bi bi-check-circle-fill me-3" style="font-size: 1.3rem;"></i>
            <div><?= $msg ?></div>
        </div>
    <?php endif; ?>

    <?php if($erro): ?>
        <div class="alert alert-danger border-0 bg-danger bg-opacity-10 text-danger mb-4 d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill me-3" style="font-size: 1.3rem;"></i>
            <div><?= $erro ?></div>
        </div>
    <?php endif; ?>

    <h6 class="section-title"><i class="bi bi-person-circle"></i>Minhas Estatísticas</h6>
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="stat-card">
                <div class="stat-label"><i class="bi bi-coin me-2"></i>Saldo Atual</div>
                <div class="stat-value"><?= number_format($usuario['pontos'], 0, ',', '.') ?> pts</div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="stat-card">
                <div class="stat-label"><i class="bi bi-activity me-2"></i>Apostas Ativas</div>
                <div class="stat-value"><?= $minhas_apostas_abertas ?></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="stat-card">
                <div class="stat-label"><i class="bi bi-receipt me-2"></i>Total de Apostas</div>
                <div class="stat-value"><?= $total_apostas_usuario ?></div>
            </div>
        </div>
    </div>

    <?php if(!empty($ultimos_eventos_abertos)): ?>
        <h6 class="section-title"><i class="bi bi-lightning-fill"></i>Apostas Gerais</h6>
        <div class="accordion" id="accordion-apostas">
            <?php foreach($ultimos_eventos_abertos as $evento): ?>
                <?php $evento_id = (int)$evento['id']; ?>
                <div class="accordion-item bg-transparent border-0 mb-2">
                    <h2 class="accordion-header" id="heading-<?= $evento_id ?>">
                        <button class="accordion-button collapsed bg-dark text-white" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?= $evento_id ?>" aria-expanded="false" aria-controls="collapse-<?= $evento_id ?>">
                            <div>
                                <div class="evento-titulo mb-1"><?= htmlspecialchars($evento['nome']) ?></div>
                                <small class="evento-data">
                                    <i class="bi bi-clock-history me-1 text-warning"></i>
                                    Encerra em: <?= date('d/m/Y às H:i', strtotime($evento['data_limite'])) ?>
                                </small>
                            </div>
                        </button>
                    </h2>
                    <div id="collapse-<?= $evento_id ?>" class="accordion-collapse collapse" aria-labelledby="heading-<?= $evento_id ?>" data-bs-parent="#accordion-apostas">
                        <div class="accordion-body card-evento">
                            <div class="opcoes-grid">
                                <?php $evento_bloqueado = in_array($evento_id, $eventos_apostados, true); ?>
                                <?php foreach($evento['opcoes'] as $opcao): ?>
                                    <div class="card-opcao">
                                        <span class="opcao-nome"><?= htmlspecialchars($opcao['descricao']) ?></span>
                                        <span class="opcao-odd"><?= number_format($opcao['odd'], 2) ?>x</span>
                                        <?php if ($evento_bloqueado): ?>
                                            <div class="text-secondary" style="font-size: 0.8rem;">Você já apostou</div>
                                            <button type="button" class="btn btn-sm btn-outline-secondary w-100" style="font-size: 0.8rem;" disabled>Apostado</button>
                                        <?php else: ?>
                                            <form method="POST" action="games/apostas.php" class="bet-inline">
                                                <input type="hidden" name="opcao_id" value="<?= (int)$opcao['id'] ?>">
                                                <input type="number" name="valor" class="form-control form-control-sm" placeholder="Valor" min="1" step="1" required>
                                                <button type="submit" class="btn btn-sm btn-outline-success" style="font-size: 0.8rem;">Apostar</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <h6 class="section-title"><i class="bi bi-lightning-fill"></i>Apostas Gerais</h6>
        <div class="empty-state">
            <div class="empty-icon"><i class="bi bi-inbox"></i></div>
            <div class="empty-text">Nenhum evento disponível no momento</div>
        </div>
    <?php endif; ?>

    <h6 class="section-title"><i class="bi bi-joystick"></i>Escolha um Jogo</h6>
    <div class="row g-3 mb-5">
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/index.php?game=flappy" class="game-card" style="--accent: #ff9800;">
                <span class="game-icon">🐦</span>
                <div class="game-title">Flappy Bird</div>
                <div class="game-subtitle">Desvie dos canos</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/index.php?game=pinguim" class="game-card" style="--accent: #29b6f6;">
                <span class="game-icon">🐧</span>
                <div class="game-title">Pinguim Run</div>
                <div class="game-subtitle">Corra e ganhe</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/index.php?game=xadrez" class="game-card" style="--accent: #9c27b0;">
                <span class="game-icon">♛</span>
                <div class="game-title">Xadrez PvP</div>
                <div class="game-subtitle">Desafie e aposte</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/index.php?game=memoria" class="game-card" style="--accent: #00bcd4;">
                <span class="game-icon">🧠</span>
                <div class="game-title">Memória</div>
                <div class="game-subtitle">Desafio mental</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/index.php?game=termo" class="game-card" style="--accent: #4caf50;">
                <span class="game-icon">📝</span>
                <div class="game-title">Termo</div>
                <div class="game-subtitle">Adivinhe a palavra</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/roleta.php" class="game-card" style="--accent: #d32f2f;">
                <span class="game-icon">🎡</span>
                <div class="game-title">Roleta</div>
                <div class="game-subtitle">Cassino Europeu</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/blackjack.php" class="game-card" style="--accent: #d32f2f;">
                <span class="game-icon">🃏</span>
                <div class="game-title">Blackjack</div>
                <div class="game-subtitle">Chegue a 21</div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/batalhanaval.php" class="game-card" style="--accent: #00bcd4;">
                <span class="game-icon">⚔️</span>
                <div class="game-title">Batalha Naval</div>
                <div class="game-subtitle">Desafio multiplayer</div>
            </a>
        </div>
    </div>

    <h6 class="section-title"><i class="bi bi-trophy"></i>Rankings Gerais</h6>
    <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
            <div class="ranking-card">
                <div class="ranking-title"><i class="bi bi-fire me-2"></i>Top 5 Geral (Pontos)</div>
                <?php if(empty($top_5_ranking)): ?>
                    <div class="text-center py-3">
                        <small class="text-secondary">Sem dados ainda</small>
                    </div>
                <?php else: ?>
                    <?php foreach($top_5_ranking as $idx => $jogador): ?>
                        <div class="ranking-item medal-<?= $idx+1 ?>">
                            <span class="ranking-position" aria-label="Posição <?= $idx+1 ?>"></span>
                            <div style="display: flex; flex-direction: column; flex: 1; margin: 0 10px;">
                                <span class="ranking-name">
                                    <?= htmlspecialchars($jogador['nome']) ?>
                                    <?php if (!empty($jogador['league'])): ?>
                                        <small class="text-secondary">(<?= htmlspecialchars($jogador['league']) ?>)</small>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <span class="ranking-value">
                                <?= number_format($jogador['pontos'], 0, ',', '.') ?> pts
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="ranking-card">
                <div class="ranking-title"><i class="bi bi-bullseye me-2"></i>Top 5 Geral (Acertos de Apostas)</div>
                <?php if(empty($top_5_acertos)): ?>
                    <div class="text-center py-3">
                        <small class="text-secondary">Sem dados ainda</small>
                    </div>
                <?php else: ?>
                    <?php foreach($top_5_acertos as $idx => $jogador): ?>
                        <div class="ranking-item medal-<?= $idx+1 ?>">
                            <span class="ranking-position" aria-label="Posição <?= $idx+1 ?>"></span>
                            <div style="display: flex; flex-direction: column; flex: 1; margin: 0 10px;">
                                <span class="ranking-name">
                                    <?= htmlspecialchars($jogador['nome']) ?>
                                    <?php if (!empty($jogador['league'])): ?>
                                        <small class="text-secondary">(<?= htmlspecialchars($jogador['league']) ?>)</small>
                                    <?php endif; ?>
                                </span>
                                <small class="text-secondary">Acertos: <?= (int)$jogador['acertos'] ?></small>
                            </div>
                            <span class="ranking-value">
                                <?= (int)$jogador['acertos'] ?> acertos
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <h6 class="section-title"><i class="bi bi-collection"></i>Rankings por Liga</h6>
    <?php $ranking_labels = ['ELITE' => 'Elite', 'RISE' => 'Rise', 'NEXT' => 'Next', 'ROOKIE' => 'Rookie']; ?>
    <div class="row g-3 mb-3">
        <?php foreach (array_slice($ranking_labels, 0, 2, true) as $liga => $label): ?>
            <div class="col-12 col-md-6">
                <div class="ranking-card">
                    <div class="ranking-title"><i class="bi bi-trophy-fill me-2"></i>Top 5 <?= $label ?></div>
                    <?php if(empty($ranking_por_liga[$liga])): ?>
                        <div class="text-center py-3">
                            <small class="text-secondary">Sem dados ainda</small>
                        </div>
                    <?php else: ?>
                        <?php foreach($ranking_por_liga[$liga] as $idx => $jogador): ?>
                            <div class="ranking-item medal-<?= $idx+1 ?>">
                                <span class="ranking-position" aria-label="Posição <?= $idx+1 ?>"></span>
                                <div style="display: flex; flex-direction: column; flex: 1; margin: 0 10px;">
                                    <span class="ranking-name"><?= htmlspecialchars($jogador['nome']) ?></span>
                                </div>
                                <span class="ranking-value">
                                    <?= number_format($jogador['pontos'], 0, ',', '.') ?> pts
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach (array_slice($ranking_labels, 2, 2, true) as $liga => $label): ?>
            <div class="col-12 col-md-6">
                <div class="ranking-card">
                    <div class="ranking-title"><i class="bi bi-trophy-fill me-2"></i>Top 5 <?= $label ?></div>
                    <?php if(empty($ranking_por_liga[$liga])): ?>
                        <div class="text-center py-3">
                            <small class="text-secondary">Sem dados ainda</small>
                        </div>
                    <?php else: ?>
                        <?php foreach($ranking_por_liga[$liga] as $idx => $jogador): ?>
                            <div class="ranking-item medal-<?= $idx+1 ?>">
                                <span class="ranking-position" aria-label="Posição <?= $idx+1 ?>"></span>
                                <div style="display: flex; flex-direction: column; flex: 1; margin: 0 10px;">
                                    <span class="ranking-name"><?= htmlspecialchars($jogador['nome']) ?></span>
                                </div>
                                <span class="ranking-value">
                                    <?= number_format($jogador['pontos'], 0, ',', '.') ?> pts
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="text-center mb-4">
        <a href="user/ranking-geral.php" class="btn btn-outline-light">Ver ranking geral</a>
    </div>
</div>

<div style="background-color: var(--secondary-dark); border-top: 1px solid var(--border-dark); padding: 20px; text-align: center; color: #666; margin-top: 60px;">
    <small><i class="bi bi-heart-fill" style="color: #ff6b6b;"></i> FBA games © 2025 | Jogue Responsavelmente</small>
</div>

</body>
</html>
