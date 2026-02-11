<?php
/**
 * INDEX.PHP - DASHBOARD PRINCIPAL 🚀
 * 
 * Mostra:
 * - Cards dos Games disponíveis
 * - Top 5 do Ranking Geral
 * - Maiores Cafés feitos
 * - Última aposta realizada
 * - Atalhos rápidos
 */

session_start();
require 'core/conexao.php';
require 'core/sequencia_dias.php';

// Segurança
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : "";
$erro = isset($_GET['erro']) ? htmlspecialchars($_GET['erro']) : "";

// 1. Dados do Usuário
try {
    $stmt = $pdo->prepare("SELECT nome, pontos, is_admin, cafes_feitos FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar usuário: " . $e->getMessage());
}

// 2. Top 5 Ranking Geral
try {
    $stmt = $pdo->query("
        SELECT id, nome, pontos, (pontos - 50) as lucro_liquido 
        FROM usuarios 
        ORDER BY lucro_liquido DESC 
        LIMIT 5
    ");
    $top_5_ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $top_5_ranking = [];
}

// 3. Top 5 Maiores Cafés
try {
    $stmt = $pdo->query("
        SELECT id, nome, cafes_feitos 
        FROM usuarios 
        WHERE cafes_feitos > 0 
        ORDER BY cafes_feitos DESC 
        LIMIT 5
    ");
    $top_5_cafes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $top_5_cafes = [];
}

// 4. Obter sequências de Termo e Memória para TODOS os usuários
$sequencias_usuario = []; // user_id => ['termo' => x, 'memoria' => y]

try {
    // Buscar todas as sequências
    $stmt = $pdo->query("
        SELECT user_id, jogo, sequencia_atual 
        FROM usuario_sequencias_dias
        WHERE sequencia_atual > 0
    ");
    $todas_sequencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach($todas_sequencias as $seq) {
        $uid = $seq['user_id'];
        if(!isset($sequencias_usuario[$uid])) {
            $sequencias_usuario[$uid] = [];
        }
        $sequencias_usuario[$uid][$seq['jogo']] = $seq['sequencia_atual'];
    }
} catch (PDOException $e) {
    $sequencias_usuario = [];
}

// 5. Obter usuário com mais cafés feitos
$maior_cafe = null;

try {
    $stmt = $pdo->query("
        SELECT id, nome, cafes_feitos 
        FROM usuarios 
        WHERE cafes_feitos > 0 
        ORDER BY cafes_feitos DESC 
        LIMIT 1
    ");
    $maior_cafe = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $maior_cafe = null;
}

// 5.5. Buscar os "reis" dos jogos (vencedores)
$id_rei_xadrez = null;
$rei_xadrez = null;
$id_rei_pinguim = null;
$rei_pinguim = null;
$id_rei_flappy = null;
$rei_flappy = null;
$id_rei_pnip = null;
$rei_pnip = null;

try {
    // Rei do Xadrez: Quem tem mais vitórias
    $stmt = $pdo->query("
        SELECT vencedor as user_id, COUNT(*) as vitoria_count
        FROM xadrez_partidas 
        WHERE status = 'finalizada' 
        GROUP BY vencedor 
        ORDER BY vitoria_count DESC 
        LIMIT 1
    ");
    $xadrez_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if($xadrez_result) {
        $id_rei_xadrez = $xadrez_result['user_id'];
        $stmt2 = $pdo->prepare("SELECT id, nome FROM usuarios WHERE id = :id");
        $stmt2->execute([':id' => $id_rei_xadrez]);
        $rei_xadrez = $stmt2->fetch(PDO::FETCH_ASSOC);
    }

    // Rei do Pinguim: Quem tem o maior recorde
    $stmt = $pdo->query("
        SELECT id_usuario, MAX(pontuacao_final) as recorde
        FROM dino_historico 
        GROUP BY id_usuario 
        ORDER BY recorde DESC 
        LIMIT 1
    ");
    $pinguim_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if($pinguim_result) {
        $id_rei_pinguim = $pinguim_result['id_usuario'];
        $stmt2 = $pdo->prepare("SELECT id, nome FROM usuarios WHERE id = :id");
        $stmt2->execute([':id' => $id_rei_pinguim]);
        $rei_pinguim = $stmt2->fetch(PDO::FETCH_ASSOC);
    }

    // Rei do Flappy: Quem tem o maior recorde
    $stmt = $pdo->query("
        SELECT id_usuario, MAX(pontuacao) as recorde
        FROM flappy_historico 
        GROUP BY id_usuario 
        ORDER BY recorde DESC 
        LIMIT 1
    ");
    $flappy_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if($flappy_result) {
        $id_rei_flappy = $flappy_result['id_usuario'];
        $stmt2 = $pdo->prepare("SELECT id, nome FROM usuarios WHERE id = :id");
        $stmt2->execute([':id' => $id_rei_flappy]);
        $rei_flappy = $stmt2->fetch(PDO::FETCH_ASSOC);
    }

    // Rei do Batalha Naval: Quem tem mais vitórias em Batalha Naval
    $stmt = $pdo->query("
        SELECT vencedor_id, COUNT(*) as vitoria_count
        FROM naval_salas 
        WHERE status = 'fim' AND vencedor_id IS NOT NULL 
        GROUP BY vencedor_id 
        ORDER BY vitoria_count DESC 
        LIMIT 1
    ");
    $pnip_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if($pnip_result) {
        $id_rei_pnip = $pnip_result['vencedor_id'];
        $stmt2 = $pdo->prepare("SELECT id, nome FROM usuarios WHERE id = :id");
        $stmt2->execute([':id' => $id_rei_pnip]);
        $rei_pnip = $stmt2->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Silencia erros
}

// 6. Buscar sequências máximas dos vencedores
$seq_termo_vencedor = null;
$seq_memoria_vencedor = null;
$seq_cafe_vencedor = null;

try {
    // Maior sequência de Termo geral
    $stmt = $pdo->query("
        SELECT u.id, u.nome, usd.sequencia_atual 
        FROM usuario_sequencias_dias usd
        JOIN usuarios u ON usd.user_id = u.id
        WHERE usd.jogo = 'termo' AND usd.sequencia_atual > 0
        ORDER BY usd.sequencia_atual DESC 
        LIMIT 1
    ");
    $seq_termo_vencedor = $stmt->fetch(PDO::FETCH_ASSOC);

    // Maior sequência de Memória geral
    $stmt = $pdo->query("
        SELECT u.id, u.nome, usd.sequencia_atual 
        FROM usuario_sequencias_dias usd
        JOIN usuarios u ON usd.user_id = u.id
        WHERE usd.jogo = 'memoria' AND usd.sequencia_atual > 0
        ORDER BY usd.sequencia_atual DESC 
        LIMIT 1
    ");
    $seq_memoria_vencedor = $stmt->fetch(PDO::FETCH_ASSOC);

    // Maior sequência de Café (mais cafés feitos)
    $stmt = $pdo->query("
        SELECT id, nome, cafes_feitos as sequencia_atual
        FROM usuarios 
        WHERE cafes_feitos > 0 
        ORDER BY cafes_feitos DESC 
        LIMIT 1
    ");
    $seq_cafe_vencedor = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Silencia erros
}

// 7. 3 Últimos Eventos Abertos (para exibir no card e no painel)
try {
    $stmt = $pdo->query("
        SELECT e.id, e.nome, e.data_limite 
        FROM eventos e 
        WHERE e.status = 'aberta' AND e.data_limite > NOW() 
        ORDER BY e.data_limite ASC 
        LIMIT 3
    ");
    $ultimos_eventos_abertos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Busca opções para cada evento
    foreach ($ultimos_eventos_abertos as &$evento) {
        $stmtOpcoes = $pdo->prepare("SELECT id, descricao, odd FROM opcoes WHERE evento_id = :eid ORDER BY id ASC");
        $stmtOpcoes->execute([':eid' => $evento['id']]);
        $evento['opcoes'] = $stmtOpcoes->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    unset($evento);
} catch (PDOException $e) {
    $ultimos_eventos_abertos = [];
}

// 8. Eventos Abertos (count)
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM eventos WHERE status = 'aberta'");
    $total_eventos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (PDOException $e) {
    $total_eventos = 0;
}

// 9. Minhas Apostas Abertas (count)
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM palpites p
        JOIN eventos e ON (SELECT evento_id FROM opcoes WHERE id = p.opcao_id) = e.id
        WHERE p.id_usuario = :uid AND e.status = 'aberta'
    ");
    $stmt->execute([':uid' => $user_id]);
    $minhas_apostas_abertas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (PDOException $e) {
    $minhas_apostas_abertas = 0;
}

// 10. Total de apostas feitas
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM palpites WHERE id_usuario = :uid");
    $stmt->execute([':uid' => $user_id]);
    $total_apostas_usuario = (int)($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
} catch (PDOException $e) {
    $total_apostas_usuario = 0;
}

// 11. Última aposta do usuário
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-color: var(--primary-dark);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #e0e0e0;
        }

        /* ===== NAVBAR ===== */
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


        /* ===== CONTAINER ===== */
        .container-main {
            padding: 40px 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* ===== CARDS DE GAMES ===== */
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

        .game-card:hover::before {
            opacity: 1;
        }

        .game-icon {
            font-size: 3rem;
            margin-bottom: 12px;
            display: block;
        }

        .game-title {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }

        .game-subtitle {
            font-size: 0.85rem;
            color: #888;
        }

        /* ===== DASHBOARD STATS ===== */
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

        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.2;
        }

        /* ===== SEÇÕES ===== */
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

        .section-title i {
            color: var(--accent-green);
            font-size: 1.2rem;
        }

        /* ===== RANKING TABLES ===== */
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

        .ranking-item:last-child {
            border-bottom: none;
        }


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

        .ranking-value {
            font-weight: 700;
            color: #fff;
            text-align: right;
        }

        /* Medal Icons (sem repetir número) */
        .medal-1::before { content: '🥇'; margin-right: 5px; }
        .medal-2::before { content: '🥈'; margin-right: 5px; }
        .medal-3::before { content: '🥉'; margin-right: 5px; }
        .medal-4::before { content: '🏅'; margin-right: 5px; }
        .medal-5::before { content: '🏅'; margin-right: 5px; }

        /* ===== ÚLTIMA APOSTA ===== */
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

        .aposta-detail-item {
            display: flex;
            flex-direction: column;
        }

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

        /* ===== CARD EVENTO (APOSTAS) ===== */
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
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .card-opcao {
            background: #252525;
            border: 1px solid #444;
            border-radius: 8px;
            padding: 15px;
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
            font-size: 1.5em;
            display: block;
            margin-bottom: 12px;
            text-shadow: 0 0 5px rgba(252, 8, 43, 0.2);
        }

        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 700;
        }

        .status-aberta {
            background-color: #ffd600;
            color: #000;
        }

        .status-finalizada {
            background-color: #4caf50;
            color: #fff;
        }

        /* ===== BUTTONS ===== */
        .btn-play {
            width: 100%;
            padding: 12px 20px;
            margin-top: 15px;
            background: linear-gradient(135deg, var(--accent-green), #ff5a6e);
            color: #000;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-play:hover {
            transform: scale(1.05);
            box-shadow: 0 0 20px rgba(252, 8, 43, 0.4);
            color: #000;
            text-decoration: none;
        }

        .btn-play-secondary {
            width: 100%;
            padding: 12px 20px;
            margin-top: 15px;
            background-color: transparent;
            color: var(--accent-green);
            border: 2px solid var(--accent-green);
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-play-secondary:hover {
            background-color: var(--accent-green);
            color: #000;
            text-decoration: none;
        }

        /* ===== EMPTY STATE ===== */
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

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .container-main {
                padding: 20px 15px;
            }

            .section-title {
                font-size: 0.8rem;
            }

            .stat-card {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }

            .game-card {
                height: 150px;
            }

            .game-icon {
                font-size: 2.5rem;
            }

            .ranking-position {
                min-width: 25px;
            }
        }

    </style>
</head>
<body>

<!-- NAVBAR -->
<div class="navbar-custom d-flex justify-content-between align-items-center sticky-top">
            <a href="#" class="brand-name">🎮 FBA games</a>
    
    <div class="d-flex align-items-center gap-3">
        <div class="d-none d-md-flex align-items-center gap-2">
            <div>
                <span style="color: #999; font-size: 0.9rem;">Bem-vindo(a),</span>
                <strong><?= htmlspecialchars($usuario['nome']) ?></strong>
            </div>
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

<!-- CONTAINER PRINCIPAL -->
<div class="container-main">

    <!-- MENSAGENS -->
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

    <!-- SEÇÃO: MINHAS STATS (CARDS NO TOPO) -->
    <h6 class="section-title"><i class="bi bi-person-circle"></i>Minhas Estatísticas</h6>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="stat-label"><i class="bi bi-coin me-2"></i>Saldo Atual</div>
                <div class="stat-value"><?= number_format($usuario['pontos'], 0, ',', '.') ?> pts</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="stat-label"><i class="bi bi-cup-hot me-2"></i>Cafés Feitos</div>
                <div class="stat-value"><?= $usuario['cafes_feitos'] ?? 0 ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="stat-label"><i class="bi bi-activity me-2"></i>Apostas Ativas</div>
                <div class="stat-value"><?= $minhas_apostas_abertas ?></div>
            </div>
        </div>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="stat-label"><i class="bi bi-megaphone me-2"></i>Eventos Abertos</div>
                <div class="stat-value"><?= $total_eventos ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="stat-card">
                <div class="stat-label"><i class="bi bi-receipt me-2"></i>Total de Apostas</div>
                <div class="stat-value"><?= $total_apostas_usuario ?></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="stat-card">
                <div class="stat-label"><i class="bi bi-clock-history me-2"></i>Última Aposta</div>
                <?php if($ultima_aposta): ?>
                    <div class="stat-value" style="font-size: 1.3rem;">
                        <?= number_format($ultima_aposta['valor'], 0, ',', '.') ?> pts
                    </div>
                    <small class="text-secondary">
                        <?= htmlspecialchars($ultima_aposta['evento_nome']) ?> • <?= htmlspecialchars($ultima_aposta['opcao_descricao']) ?>
                    </small>
                <?php else: ?>
                    <div class="stat-value" style="font-size: 1.1rem;">Sem apostas</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if($ultima_aposta): ?>
        <?php
            $resultado_aposta = null;
            if (($ultima_aposta['evento_status'] ?? '') === 'encerrada' && $ultima_aposta['vencedor_opcao_id']) {
                $resultado_aposta = ((int)$ultima_aposta['vencedor_opcao_id'] === (int)$ultima_aposta['opcao_id']) ? 'Ganhou' : 'Perdeu';
            }
        ?>
        <h6 class="section-title"><i class="bi bi-cash-stack"></i>Minha Última Aposta</h6>
        <div class="aposta-card">
            <div class="aposta-label mb-1">Evento</div>
            <div class="aposta-evento"><?= htmlspecialchars($ultima_aposta['evento_nome']) ?></div>
            <div class="text-light mb-3">Opção: <?= htmlspecialchars($ultima_aposta['opcao_descricao']) ?></div>
            <div class="aposta-details">
                <div class="aposta-detail-item">
                    <span class="aposta-detail-label">Valor</span>
                    <span class="aposta-detail-value"><?= number_format($ultima_aposta['valor'], 0, ',', '.') ?> pts</span>
                </div>
                <div class="aposta-detail-item">
                    <span class="aposta-detail-label">Odd</span>
                    <span class="aposta-detail-value"><?= number_format($ultima_aposta['odd_registrada'], 2) ?>x</span>
                </div>
                <div class="aposta-detail-item">
                    <span class="aposta-detail-label">Status</span>
                    <span class="aposta-detail-value">
                        <?php if($resultado_aposta): ?>
                            <?= $resultado_aposta ?>
                        <?php else: ?>
                            <?= ($ultima_aposta['evento_status'] ?? '') === 'aberta' ? 'Aberta' : 'Encerrada' ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="aposta-detail-item">
                    <span class="aposta-detail-label">Data</span>
                    <span class="aposta-detail-value"><?= date('d/m/Y H:i', strtotime($ultima_aposta['data_palpite'])) ?></span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if(!empty($ultimos_eventos_abertos)): ?>
        <h6 class="section-title"><i class="bi bi-lightning-fill"></i>Últimas Apostas</h6>
        <?php foreach($ultimos_eventos_abertos as $evento): ?>
            <div class="card-evento">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <div class="evento-titulo"><?= htmlspecialchars($evento['nome']) ?></div>
                        <small class="evento-data">
                            <i class="bi bi-clock-history me-1 text-warning"></i>
                            Encerra em: <?= date('d/m/Y às H:i', strtotime($evento['data_limite'])) ?>
                        </small>
                    </div>
                </div>

                <div class="opcoes-grid">
                    <?php foreach($evento['opcoes'] as $opcao): ?>
                        <div class="card-opcao">
                            <span class="opcao-nome"><?= htmlspecialchars($opcao['descricao']) ?></span>
                            <span class="opcao-odd"><?= number_format($opcao['odd'], 2) ?>x</span>
                            <a href="games/index.php?game=apostas" class="btn btn-sm btn-outline-success w-100" style="font-size: 0.85rem;">Apostar</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <h6 class="section-title"><i class="bi bi-lightning-fill"></i>3 Últimas Apostas Disponíveis</h6>
        <div class="empty-state">
            <div class="empty-icon"><i class="bi bi-inbox"></i></div>
            <div class="empty-text">Nenhum evento disponível no momento</div>
        </div>
    <?php endif; ?>

    <!-- SEÇÃO: GAMES -->
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
            <a href="games/index.php?game=apostas" class="game-card" style="--accent: #e91e63;">
                <span class="game-icon">💰</span>
                <div class="game-title">Apostas</div>
                <div class="game-subtitle">Faça suas apostas agora</div>
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

        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/index.php?game=corrida" class="game-card" style="--accent: #ff5722;">
                <span class="game-icon">🏎️</span>
                <div class="game-title">Corrida Neon</div>
                <div class="game-subtitle">Desvie do trânsito</div>
            </a>
        </div>

        <div class="col-6 col-md-4 col-lg-3">
            <a href="games/crypto.php" class="game-card" style="--accent: #8b1528;">
                <span class="game-icon">🚀</span>
                <div class="game-title">Crypto Crash</div>
                <div class="game-subtitle">Pare antes do crash</div>
            </a>
        </div>
    </div>

    <!-- SEÇÃO: RANKINGS -->
    <h6 class="section-title"><i class="bi bi-trophy"></i>Rankings</h6>

    <!-- TOP 5 RANKING GERAL E TOP 5 CAFÉS LADO A LADO -->
    <div class="ranking-container" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 30px;">
        <!-- TOP 5 RANKING GERAL -->
        <div class="ranking-card">
            <div class="ranking-title"><i class="bi bi-fire me-2"></i>Top 5 Geral</div>
            <?php if(empty($top_5_ranking)): ?>
                <div class="text-center py-3">
                    <small class="text-secondary">Sem dados ainda</small>
                </div>
            <?php else: ?>
                <?php foreach($top_5_ranking as $idx => $jogador): ?>
                    <div class="ranking-item medal-<?= $idx+1 ?>">
                        <span class="ranking-position" aria-label="Posição <?= $idx+1 ?>"></span>
                        <div style="display: flex; flex-direction: column; flex: 1; margin: 0 10px;">
                            <span class="ranking-name"><?= htmlspecialchars($jogador['nome']) ?></span>
                        </div>
                        <span class="ranking-value">
                            <?= number_format($jogador['lucro_liquido'], 0, ',', '.') ?> pts
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- TOP 5 CAFÉS -->
        <div class="ranking-card">
            <div class="ranking-title"><i class="bi bi-cup-hot-fill me-2"></i>Top Cafés</div>
            <?php if(empty($top_5_cafes)): ?>
                <div class="text-center py-3">
                    <small class="text-secondary">Sem dados ainda</small>
                </div>
            <?php else: ?>
                <?php foreach($top_5_cafes as $idx => $jogador): ?>
                    <div class="ranking-item medal-<?= $idx+1 ?>">
                        <span class="ranking-position" aria-label="Posição <?= $idx+1 ?>"></span>
                        <span class="ranking-name"><?= htmlspecialchars($jogador['nome']) ?></span>
                        <span class="ranking-value">
                            <i class="bi bi-cup-hot"></i> <?= $jogador['cafes_feitos'] ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- SEÇÃO: CAMPEÕES (4 POR LINHA) -->
    <h6 class="section-title" style="margin-top: 30px;"><i class="bi bi-crown-fill"></i>Campeões & Recordistas</h6>
    
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
        <!-- Campeão Xadrez -->
        <?php if($rei_xadrez): ?>
            <div class="ranking-card" style="text-align: center; padding: 15px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <div style="font-size: 2.5em; margin-bottom: 10px;">♟️</div>
                <div style="font-size: 0.85rem; color: #999; margin-bottom: 10px;">Rei do Xadrez</div>
                <div style="font-weight: bold; margin: 10px 0;"><?= htmlspecialchars($rei_xadrez['nome']) ?></div>
            </div>
        <?php endif; ?>

        <!-- Campeão Pinguim -->
        <?php if($rei_pinguim): ?>
            <div class="ranking-card" style="text-align: center; padding: 15px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <div style="font-size: 2.5em; margin-bottom: 10px;">🐧</div>
                <div style="font-size: 0.85rem; color: #999; margin-bottom: 10px;">Rei do Pinguim</div>
                <div style="font-weight: bold; margin: 10px 0;"><?= htmlspecialchars($rei_pinguim['nome']) ?></div>
            </div>
        <?php endif; ?>

        <!-- Campeão Flappy -->
        <?php if($rei_flappy): ?>
            <div class="ranking-card" style="text-align: center; padding: 15px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <div style="font-size: 2.5em; margin-bottom: 10px;">🐦</div>
                <div style="font-size: 0.85rem; color: #999; margin-bottom: 10px;">Rei do Flappy</div>
                <div style="font-weight: bold; margin: 10px 0;"><?= htmlspecialchars($rei_flappy['nome']) ?></div>
            </div>
        <?php endif; ?>

    <!-- Campeão Batalha Naval -->
        <?php if($rei_pnip): ?>
            <div class="ranking-card" style="text-align: center; padding: 15px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <div style="font-size: 2.5em; margin-bottom: 10px;">🚢</div>
                <div style="font-size: 0.85rem; color: #999; margin-bottom: 10px;">Almirante Naval</div>
                <div style="font-weight: bold; margin: 10px 0;"><?= htmlspecialchars($rei_pnip['nome']) ?></div>
            </div>
        <?php endif; ?>

        <!-- Maior Sequência Termo -->
        <?php if($seq_termo_vencedor): ?>
            <div class="ranking-card" style="text-align: center; padding: 15px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <div style="font-size: 2.5em; margin-bottom: 10px;">📝</div>
                <div style="font-size: 0.85rem; color: #999; margin-bottom: 10px;">Maior Sequência Termo</div>
                <div style="font-weight: bold; margin: 10px 0;"><?= htmlspecialchars($seq_termo_vencedor['nome']) ?></div>
                <div style="font-size: 0.9em; color: #ff006e; margin-top: 8px; font-weight: bold;">x<?= $seq_termo_vencedor['sequencia_atual'] ?></div>
            </div>
        <?php endif; ?>

        <!-- Maior Sequência Memória -->
        <?php if($seq_memoria_vencedor): ?>
            <div class="ranking-card" style="text-align: center; padding: 15px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <div style="font-size: 2.5em; margin-bottom: 10px;">🧠</div>
                <div style="font-size: 0.85rem; color: #999; margin-bottom: 10px;">Maior Sequência Memória</div>
                <div style="font-weight: bold; margin: 10px 0;"><?= htmlspecialchars($seq_memoria_vencedor['nome']) ?></div>
                <div style="font-size: 0.9em; color: #00d4ff; margin-top: 8px; font-weight: bold;">x<?= $seq_memoria_vencedor['sequencia_atual'] ?></div>
            </div>
        <?php endif; ?>

        <!-- Maior Sequência Café -->
        <?php if($seq_cafe_vencedor): ?>
            <div class="ranking-card" style="text-align: center; padding: 15px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
                <div style="font-size: 2.5em; margin-bottom: 10px;">☕</div>
                <div style="font-size: 0.85rem; color: #999; margin-bottom: 10px;">Maior Sequência Café</div>
                <div style="font-weight: bold; margin: 10px 0;"><?= htmlspecialchars($seq_cafe_vencedor['nome']) ?></div>
                <div style="font-size: 0.9em; color: #D2691E; margin-top: 8px; font-weight: bold;">x<?= $seq_cafe_vencedor['sequencia_atual'] ?></div>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Footer -->
<div style="background-color: var(--secondary-dark); border-top: 1px solid var(--border-dark); padding: 20px; text-align: center; color: #666; margin-top: 60px;">
    <small><i class="bi bi-heart-fill" style="color: #ff6b6b;"></i> FBA games © 2025 | Jogue Responsavelmente</small>
</div>

</body>

</body>
</html>
