<?php
/**
 * GAMES/APOSTAS.PHP - APOSTAS DISPONÍVEIS + HISTÓRICO
 * 
 * - POST: Processar nova aposta
 * - GET: Listar eventos disponíveis + histórico do usuário
 */

// session_start já foi chamado em games/index.php
require '../core/conexao.php';
require '../core/funcoes.php';

// Segurança
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// 1. Dados do Usuário
try {
    $stmt = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar usuário: " . $e->getMessage());
}

// --- PROCESSAR APOSTA (POST) ---
$erro_aposta = null;
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['opcao_id'])) {
    try {
        $opcao_id = isset($_POST['opcao_id']) ? (int)$_POST['opcao_id'] : 0;
        $valor_aposta = isset($_POST['valor']) ? floatval($_POST['valor']) : 0;
        
        if ($opcao_id <= 0 || $valor_aposta <= 0) {
            throw new Exception("Dados inválidos fornecidos");
        }

        $pdo->beginTransaction();

        // 1. Verifica saldo (PONTOS)
        $stmtUser = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
        $stmtUser->execute([':id' => $user_id]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['pontos'] < $valor_aposta) {
            throw new Exception("Saldo insuficiente! Seus pontos: " . ($user['pontos'] ?? 0));
        }

        // 2. Verifica se a aposta está aberta E PEGA A ODD ATUAL
        $stmtCheck = $pdo->prepare("
            SELECT e.id as evento_id, e.status, e.data_limite, o.odd
            FROM opcoes o 
            JOIN eventos e ON o.evento_id = e.id 
            WHERE o.id = :oid
        ");
        $stmtCheck->execute([':oid' => $opcao_id]);
        $dados_aposta = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$dados_aposta) {
            throw new Exception("Opção de aposta inválida.");
        }

        if ($dados_aposta['status'] != 'aberta' || strtotime($dados_aposta['data_limite']) < time()) {
            throw new Exception("Este evento já encerrou ou foi cancelado!");
        }

        // 3. Desconta os pontos
        $stmtDebit = $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :id");
        $stmtDebit->execute([':val' => $valor_aposta, ':id' => $user_id]);

        // 4. Registra o palpite COM A ODD CONGELADA
        $stmtInsert = $pdo->prepare("
            INSERT INTO palpites (id_usuario, opcao_id, valor, odd_registrada, data_palpite) 
            VALUES (:uid, :oid, :val, :odd_fixa, NOW())
        ");
        $stmtInsert->execute([
            ':uid' => $user_id, 
            ':oid' => $opcao_id, 
            ':val' => $valor_aposta,
            ':odd_fixa' => $dados_aposta['odd']
        ]);

        // 5. Recalcula as odds para o PRÓXIMO apostador
        recalcularOdds($pdo, $dados_aposta['evento_id']);

        $pdo->commit();

        // Recarrega a página com mensagem
        header("Location: index.php?game=apostas&msg=Aposta realizada com sucesso!");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $erro_aposta = $e->getMessage();
    }
}

// 2. Busca Eventos Disponíveis
try {
    $stmtEventos = $pdo->query("SELECT id, nome, data_limite FROM eventos WHERE status = 'aberta' AND data_limite > NOW() ORDER BY data_limite ASC LIMIT 50");
    $eventos_disponiveis = $stmtEventos->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Anexa opções para cada evento
    foreach ($eventos_disponiveis as &$ev) {
        $stmtOps = $pdo->prepare("SELECT id, descricao, odd FROM opcoes WHERE evento_id = :eid ORDER BY id ASC");
        $stmtOps->execute([':eid' => $ev['id']]);
        $ev['opcoes'] = $stmtOps->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    unset($ev);
} catch (PDOException $e) {
    $eventos_disponiveis = [];
}

// 3. Busca Histórico de Apostas do Usuário
try {
    $sql = "
        SELECT 
            p.id,
            p.valor,
            p.data_palpite,
            p.opcao_id, 
            p.odd_registrada,
            o.descricao as aposta_feita,
            e.nome as evento_nome,
            e.status as evento_status,
            e.vencedor_opcao_id
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        WHERE p.id_usuario = :uid
        ORDER BY p.data_palpite DESC
        LIMIT 50
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $user_id]);
    $historico_apostas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $historico_apostas = [];
}

$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : "";
?>

<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💰 Apostas - Pikafumo Games</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💰</text></svg>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        :root {
            --primary-dark: #121212;
            --secondary-dark: #1e1e1e;
            --border-dark: #333;
            --accent-green: #00e676;
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

        .saldo-badge {
            background-color: var(--accent-green);
            color: #000;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: 800;
            font-size: 1.1em;
            box-shadow: 0 0 15px rgba(0, 230, 118, 0.3);
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

        .section-title i {
            color: var(--accent-green);
            font-size: 1.2rem;
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
            box-shadow: 0 0 15px rgba(0, 230, 118, 0.1);
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
            text-shadow: 0 0 5px rgba(0, 230, 118, 0.2);
        }

        .form-aposta {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .input-group-sm input {
            background-color: #2b2b2b;
            border-color: #444;
            color: #fff;
        }

        .btn-apostar {
            width: 100%;
            padding: 8px;
            background: linear-gradient(135deg, var(--accent-green), #76ff03);
            color: #000;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-apostar:hover {
            transform: scale(1.05);
            box-shadow: 0 0 15px rgba(0, 230, 118, 0.3);
            color: #000;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background-color: var(--secondary-dark);
            border: 1px dashed var(--border-dark);
            border-radius: 12px;
            margin: 20px 0;
        }

        .empty-icon {
            font-size: 4rem;
            opacity: 0.2;
            margin-bottom: 10px;
        }

        .empty-text {
            color: #666;
            font-size: 1.1rem;
        }

        .table-custom {
            --bs-table-bg: #252525;
            --bs-table-color: #e0e0e0;
            --bs-table-border-color: #333;
        }

        .table-custom th {
            background-color: #1e1e1e;
            color: #fff;
            border-bottom: 2px solid #444;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        .table-custom tr:hover {
            background-color: #2b2b2b;
        }

        .badge-status {
            font-size: 0.85rem;
            padding: 8px 12px;
        }

        .status-aberta {
            background-color: #ffc107;
            color: #000;
        }

        .status-venceu {
            background-color: #198754;
            color: #fff;
        }

        .status-perdeu {
            background-color: #dc3545;
            color: #fff;
        }
    </style>
</head>
<body>

<!-- NAVBAR -->
<div class="navbar-custom d-flex justify-content-between align-items-center sticky-top">
    <div>
        <span style="font-size: 1.3rem; font-weight: 900;">💰 APOSTAS</span>
    </div>
    
    <div class="d-flex align-items-center gap-3">
        <div class="d-none d-md-flex align-items-center gap-2">
            <span style="color: #999; font-size: 0.9rem;">Bem-vindo(a),</span>
            <strong><?= htmlspecialchars($usuario['nome']) ?></strong>
        </div>
        
        <span class="saldo-badge">
            <i class="bi bi-coin me-1"></i><?= number_format($usuario['pontos'], 0, ',', '.') ?> pts
        </span>
        
        <a href="../index.php" class="btn btn-sm btn-outline-light border-0">
            <i class="bi bi-arrow-left"></i>
        </a>
    </div>
</div>

<!-- CONTAINER PRINCIPAL -->
<div class="container-main">

    <!-- MENSAGENS -->
    <?php if($msg): ?>
        <div class="alert alert-success border-0 bg-success bg-opacity-10 text-success mb-4 d-flex align-items-center">
            <i class="bi bi-check-circle-fill me-3"></i>
            <div><?= $msg ?></div>
        </div>
    <?php endif; ?>

    <?php if(isset($erro_aposta)): ?>
        <div class="alert alert-danger border-0 bg-danger bg-opacity-10 text-danger mb-4 d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill me-3"></i>
            <div><?= $erro_aposta ?></div>
        </div>
    <?php endif; ?>

    <!-- SEÇÃO: APOSTAS DISPONÍVEIS -->
    <h6 class="section-title"><i class="bi bi-lightning-charge-fill"></i>Apostas Disponíveis</h6>

    <?php if(empty($eventos_disponiveis)): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="bi bi-inbox"></i></div>
            <div class="empty-text">Nenhum evento disponível no momento</div>
            <p class="text-muted mt-2">Volte mais tarde para novas oportunidades.</p>
        </div>
    <?php else: ?>
        <?php foreach($eventos_disponiveis as $evento): ?>
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
                        <form method="POST" class="card-opcao">
                            <input type="hidden" name="opcao_id" value="<?= (int)$opcao['id'] ?>">
                            
                            <span class="opcao-nome"><?= htmlspecialchars($opcao['descricao']) ?></span>
                            <span class="opcao-odd"><?= number_format($opcao['odd'], 2) ?>x</span>
                            
                            <div class="form-aposta">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-dark border-secondary text-secondary">pts</span>
                                    <input type="number" name="valor" class="form-control" placeholder="Valor" min="1" required step="1">
                                </div>
                                <button type="submit" class="btn-apostar">APOSTAR</button>
                            </div>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- SEÇÃO: HISTÓRICO -->
    <h6 class="section-title mt-5"><i class="bi bi-clock-history"></i>Meu Histórico</h6>

    <?php if(empty($historico_apostas)): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="bi bi-ticket-perforated"></i></div>
            <div class="empty-text">Você ainda não fez nenhuma aposta</div>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-custom table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th class="ps-4">Data</th>
                        <th>Evento / Palpite</th>
                        <th>Valor</th>
                        <th>Odd</th>
                        <th>Possível Ganho</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($historico_apostas as $aposta): ?>
                        <?php
                            $odd_final = $aposta['odd_registrada'];
                            $status_badge = "status-aberta";
                            $status_texto = "<i class='bi bi-hourglass-split me-1'></i>Aberta";
                            $linha_style = "";

                            $status_normalizado = strtolower(trim($aposta['evento_status'] ?? ''));
                            
                            // Verifica se o evento está encerrado/finalizado
                            if (in_array($status_normalizado, ['encerrada', 'finalizada', 'fechada', 'encerrado', 'finalizado', 'fechado'])) {
                                if ($aposta['vencedor_opcao_id'] === null) {
                                    $status_badge = "status-cancelada";
                                    $status_texto = "Cancelado";
                                } elseif ($aposta['vencedor_opcao_id'] == $aposta['opcao_id']) {
                                    $status_badge = "status-venceu";
                                    $status_texto = "<i class='bi bi-trophy-fill me-1'></i>Venceu";
                                    $linha_style = "background-color: rgba(25, 135, 84, 0.15) !important;";
                                } else {
                                    $status_badge = "status-perdeu";
                                    $status_texto = "<i class='bi bi-x-circle-fill me-1'></i>Perdeu";
                                    $linha_style = "background-color: rgba(220, 53, 69, 0.1) !important;";
                                }
                            } elseif (in_array($status_normalizado, ['cancelada', 'cancelado', 'canceled', 'cancelled'])) {
                                $status_badge = "status-cancelada";
                                $status_texto = "Cancelado";
                            }
                        ?>
                        <tr style="<?= $linha_style ?>">
                            <td class="ps-4 text-secondary small">
                                <?= date('d/m/Y H:i', strtotime($aposta['data_palpite'])) ?>
                            </td>
                            <td>
                                <strong class="text-white"><?= htmlspecialchars($aposta['evento_nome']) ?></strong><br>
                                <small class="text-info"><?= htmlspecialchars($aposta['aposta_feita']) ?></small>
                            </td>
                            <td class="fw-bold text-success">
                                <?= number_format($aposta['valor'], 0, ',', '.') ?> pts
                            </td>
                            <td class="text-info">
                                <?= number_format($odd_final, 2) ?>x
                            </td>
                            <td class="fw-bold">
                                <?= number_format($aposta['valor'] * $odd_final, 0, ',', '.') ?> pts
                            </td>
                            <td>
                                <span class="badge badge-status <?= $status_badge ?>">
                                    <?= $status_texto ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
