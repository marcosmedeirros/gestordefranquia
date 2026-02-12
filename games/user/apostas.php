<?php
session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    $stmt = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar usuário: " . $e->getMessage());
}

try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) as total_apostas,
            COALESCE(SUM(p.valor), 0) as total_apostado,
            COALESCE(SUM(
                CASE
                    WHEN e.status = 'encerrada' AND e.vencedor_opcao_id = p.opcao_id
                    THEN p.valor * p.odd_registrada
                    ELSE 0
                END
            ), 0) as total_ganhos
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        WHERE p.id_usuario = :uid
    ");
    $stmt->execute([':uid' => $user_id]);
    $resumo = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_apostas' => 0, 'total_apostado' => 0, 'total_ganhos' => 0];
} catch (PDOException $e) {
    $resumo = ['total_apostas' => 0, 'total_apostado' => 0, 'total_ganhos' => 0];
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
        LIMIT 100
    ");
    $stmt->execute([':uid' => $user_id]);
    $historico = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $historico = [];
}
?>

<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Apostas - FBA games</title>
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
            margin: 30px 0 20px 0;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
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

        .aposta-card {
            background: linear-gradient(135deg, #5a0a16, #9b0d24);
            border: 1px solid #FC082B;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
        }

        .aposta-label {
            color: #ffb3bf;
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .aposta-evento {
            font-weight: 700;
            font-size: 1.2rem;
            color: #fff;
            margin-bottom: 6px;
        }

        .aposta-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
        }

        .aposta-detail-item { display: flex; flex-direction: column; }

        .aposta-detail-label {
            color: #ffb3bf;
            font-size: 0.75rem;
            text-transform: uppercase;
        }

        .aposta-detail-value {
            font-weight: 800;
            font-size: 1.1rem;
            color: #fff;
            margin-top: 4px;
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
        <span class="saldo-badge"><i class="bi bi-coin me-1"></i><?= number_format($usuario['pontos'], 0, ',', '.') ?> pts</span>
        <a href="../auth/logout.php" class="btn btn-sm btn-outline-danger border-0">
            <i class="bi bi-box-arrow-right"></i>
        </a>
    </div>
</div>

<div class="container-main">
    <h6 class="section-title"><i class="bi bi-graph-up"></i>Resumo das Apostas</h6>
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-4">
            <div class="stat-card">
                <div class="stat-label">Total de apostas</div>
                <div class="stat-value"><?= (int)$resumo['total_apostas'] ?></div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="stat-card">
                <div class="stat-label">Total apostado</div>
                <div class="stat-value"><?= number_format((float)$resumo['total_apostado'], 0, ',', '.') ?> pts</div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="stat-card">
                <div class="stat-label">Total de ganhos</div>
                <div class="stat-value"><?= number_format((float)$resumo['total_ganhos'], 0, ',', '.') ?> pts</div>
            </div>
        </div>
    </div>

    <h6 class="section-title"><i class="bi bi-cash-stack"></i>Histórico</h6>
    <?php if(!empty($historico)): ?>
        <?php foreach($historico as $palpite): ?>
            <?php
                $resultado_palpite = null;
                if (($palpite['evento_status'] ?? '') === 'encerrada' && $palpite['vencedor_opcao_id']) {
                    $resultado_palpite = ((int)$palpite['vencedor_opcao_id'] === (int)$palpite['opcao_id']) ? 'Ganhou' : 'Perdeu';
                }
            ?>
            <div class="aposta-card">
                <div class="aposta-label">Evento</div>
                <div class="aposta-evento"><?= htmlspecialchars($palpite['evento_nome']) ?></div>
                <div class="text-light mb-2">Opção: <?= htmlspecialchars($palpite['opcao_descricao']) ?></div>
                <div class="aposta-details">
                    <div class="aposta-detail-item">
                        <span class="aposta-detail-label">Valor</span>
                        <span class="aposta-detail-value"><?= number_format($palpite['valor'], 0, ',', '.') ?> pts</span>
                    </div>
                    <div class="aposta-detail-item">
                        <span class="aposta-detail-label">Odd</span>
                        <span class="aposta-detail-value"><?= number_format($palpite['odd_registrada'], 2) ?>x</span>
                    </div>
                    <div class="aposta-detail-item">
                        <span class="aposta-detail-label">Status</span>
                        <span class="aposta-detail-value">
                            <?php if($resultado_palpite): ?>
                                <?= $resultado_palpite ?>
                            <?php else: ?>
                                <?= ($palpite['evento_status'] ?? '') === 'aberta' ? 'Aberta' : 'Encerrada' ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="aposta-detail-item">
                        <span class="aposta-detail-label">Data</span>
                        <span class="aposta-detail-value"><?= date('d/m/Y H:i', strtotime($palpite['data_palpite'])) ?></span>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="bi bi-inbox"></i></div>
            <div class="empty-text">Você ainda não fez apostas.</div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
