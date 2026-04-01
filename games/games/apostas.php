<?php
/**
 * GAMES/APOSTAS.PHP - APOSTAS DISPONÍVEIS + HISTÓRICO
 * 
 * - POST: Processar nova aposta
 * - GET: Listar eventos disponíveis + histórico do usuário
 */

session_start();
require '../core/conexao.php';
require '../core/funcoes.php';

// Horário atual de Brasília para validar prazos corretamente
$nowBrt = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$nowBrtStr = $nowBrt->format('Y-m-d H:i:s');

// Segurança
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// 1. Dados do Usuário
try {
    $stmt = $pdo->prepare("SELECT nome, pontos, is_admin, fba_points, tapas_disponiveis FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro ao carregar usuário: " . $e->getMessage());
}

$loja_msg = null;
$loja_erro = null;

$tapas_limite_mes = 2;
$tapas_disponiveis = (int)($usuario['tapas_disponiveis'] ?? $tapas_limite_mes);
$tapas_disponiveis = max(0, min($tapas_limite_mes, $tapas_disponiveis));
$tapas_compradas_mes = max(0, $tapas_limite_mes - $tapas_disponiveis);
$tapas_restantes = max(0, $tapas_disponiveis);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao_loja'])) {
    $acao_loja = $_POST['acao_loja'];
    try {
        if ($acao_loja === 'trocar_moedas') {
            $custo_moedas = 1000;
            $ganho_fba = 100;

            $pdo->beginTransaction();
            $stmtSaldo = $pdo->prepare("SELECT pontos, fba_points FROM usuarios WHERE id = :id FOR UPDATE");
            $stmtSaldo->execute([':id' => $user_id]);
            $saldo = $stmtSaldo->fetch(PDO::FETCH_ASSOC);
            if (!$saldo || (int)$saldo['pontos'] < $custo_moedas) {
                throw new Exception('Moedas insuficientes para a troca.');
            }

            $pdo->prepare("UPDATE usuarios SET pontos = pontos - :cost, fba_points = fba_points + :gain WHERE id = :id")
                ->execute([':cost' => $custo_moedas, ':gain' => $ganho_fba, ':id' => $user_id]);
            $pdo->prepare("INSERT INTO fba_shop_purchases (user_id, item, qty) VALUES (:uid, 'moedas_to_fba', 1)")
                ->execute([':uid' => $user_id]);

            $pdo->commit();
            $usuario['pontos'] = (int)$saldo['pontos'] - $custo_moedas;
            $usuario['fba_points'] = (int)$saldo['fba_points'] + $ganho_fba;
            $loja_msg = 'Troca realizada: 1000 moedas por 100 FBA Points.';
        }

        if ($acao_loja === 'comprar_tapa') {
            $custo_fba = 3500;
            if ($tapas_disponiveis <= 0) {
                throw new Exception('Limite mensal de tapas atingido.');
            }

            $pdo->beginTransaction();
            $stmtSaldo = $pdo->prepare("SELECT fba_points FROM usuarios WHERE id = :id FOR UPDATE");
            $stmtSaldo->execute([':id' => $user_id]);
            $saldo = $stmtSaldo->fetch(PDO::FETCH_ASSOC);
            if (!$saldo || (int)$saldo['fba_points'] < $custo_fba) {
                throw new Exception('FBA Points insuficientes para comprar o tapa.');
            }

            $pdo->prepare("UPDATE usuarios SET fba_points = fba_points - :cost, tapas_disponiveis = GREATEST(COALESCE(tapas_disponiveis, 0) - 1, 0) WHERE id = :id")
                ->execute([':cost' => $custo_fba, ':id' => $user_id]);
            $pdo->prepare("INSERT INTO fba_shop_purchases (user_id, item, qty) VALUES (:uid, 'tapa', 1)")
                ->execute([':uid' => $user_id]);

            $pdo->commit();
            $usuario['fba_points'] = (int)$saldo['fba_points'] - $custo_fba;
            $tapas_disponiveis = max(0, $tapas_disponiveis - 1);
            $tapas_compradas_mes = min($tapas_limite_mes, $tapas_compradas_mes + 1);
            $tapas_restantes = max(0, $tapas_disponiveis);
            $loja_msg = 'Tapa comprado com sucesso.';
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $loja_erro = $e->getMessage();
    }
}

// --- PROCESSAR APOSTA (POST) ---
$erro_aposta = null;
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['opcao_id'])) {
    try {
        $opcao_id = isset($_POST['opcao_id']) ? (int)$_POST['opcao_id'] : 0;
        $valor_aposta = 1;
        
        if ($opcao_id <= 0) {
            throw new Exception("Dados inválidos fornecidos");
        }

        $pdo->beginTransaction();

        // 1. Verifica se a aposta está aberta
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

        if ($dados_aposta['status'] != 'aberta') {
            throw new Exception("Este evento já encerrou ou foi cancelado!");
        }
        // Comparar usando horário de Brasília para evitar encerramento adiantado
        $deadline = new DateTime($dados_aposta['data_limite'], new DateTimeZone('America/Sao_Paulo'));
        if ($deadline < $nowBrt) {
            throw new Exception("Este evento já encerrou ou foi cancelado!");
        }

        // 2. Verifica se já existe palpite do usuário para este evento
        $stmtDup = $pdo->prepare("
            SELECT p.id
            FROM palpites p
            JOIN opcoes o ON p.opcao_id = o.id
            WHERE p.id_usuario = :uid AND o.evento_id = :eid
            LIMIT 1
        ");
        $stmtDup->execute([':uid' => $user_id, ':eid' => $dados_aposta['evento_id']]);
        $palpiteExistente = $stmtDup->fetch(PDO::FETCH_ASSOC);

        if ($palpiteExistente) {
            $stmtUpdate = $pdo->prepare("
                UPDATE palpites
                SET opcao_id = :oid, valor = :val, odd_registrada = :odd_fixa, data_palpite = NOW()
                WHERE id = :pid
            ");
            $stmtUpdate->execute([
                ':oid' => $opcao_id,
                ':val' => $valor_aposta,
                ':odd_fixa' => 1,
                ':pid' => $palpiteExistente['id']
            ]);
        } else {
            $stmtInsert = $pdo->prepare("
                INSERT INTO palpites (id_usuario, opcao_id, valor, odd_registrada, data_palpite) 
                VALUES (:uid, :oid, :val, :odd_fixa, NOW())
            ");
            $stmtInsert->execute([
                ':uid' => $user_id, 
                ':oid' => $opcao_id, 
                ':val' => $valor_aposta,
                ':odd_fixa' => 1
            ]);
        }

        $pdo->commit();

        // Volta para o painel com mensagem
    header("Location: ../index.php?msg=" . urlencode($palpiteExistente ? "Palpite atualizado com sucesso!" : "Palpite realizado com sucesso!"));
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        header("Location: ../index.php?erro=" . urlencode($e->getMessage()));
        exit;
    }
}

// 2. Busca Eventos Disponíveis
try {
    $stmtEventos = $pdo->prepare("SELECT id, nome, data_limite FROM eventos WHERE status = 'aberta' AND data_limite > :now_brt ORDER BY data_limite ASC LIMIT 50");
    $stmtEventos->execute([':now_brt' => $nowBrtStr]);
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
    <title>💰 Apostas - FBA games</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💰</text></svg>">

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

        .saldo-badge {
            background-color: var(--accent-green);
            color: #000;
            padding: 10px 20px;
            border-radius: 25px;
            font-weight: 800;
            font-size: 1.1em;
            box-shadow: 0 0 15px rgba(252, 8, 43, 0.3);
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

        .form-aposta {
            display: flex;
            flex-direction: column;
            gap: 8px;
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
            box-shadow: 0 0 15px rgba(252, 8, 43, 0.3);
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

        .bet-win {
            background-color: rgba(25, 135, 84, 0.15) !important;
        }

        .bet-lose {
            background-color: rgba(220, 53, 69, 0.12) !important;
        }

        .bet-win .bet-status,
        .bet-win .bet-amount {
            color: #22c55e !important;
        }

        .bet-lose .bet-status,
        .bet-lose .bet-amount {
            color: #ef4444 !important;
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
            <i class="bi bi-coin me-1"></i><?= number_format($usuario['pontos'], 0, ',', '.') ?> moedas
        </span>
        <span class="saldo-badge">
            <i class="bi bi-gem me-1"></i><?= number_format($usuario['fba_points'] ?? 0, 0, ',', '.') ?> FBA POINTS
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

    <?php if($loja_msg): ?>
        <div class="alert alert-success border-0 bg-success bg-opacity-10 text-success mb-4 d-flex align-items-center">
            <i class="bi bi-check-circle-fill me-3"></i>
            <div><?= htmlspecialchars($loja_msg) ?></div>
        </div>
    <?php endif; ?>

    <?php if($loja_erro): ?>
        <div class="alert alert-danger border-0 bg-danger bg-opacity-10 text-danger mb-4 d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill me-3"></i>
            <div><?= htmlspecialchars($loja_erro) ?></div>
        </div>
    <?php endif; ?>

    <?php if(isset($erro_aposta)): ?>
        <div class="alert alert-danger border-0 bg-danger bg-opacity-10 text-danger mb-4 d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill me-3"></i>
            <div><?= $erro_aposta ?></div>
        </div>
    <?php endif; ?>

    <!-- SEÇÃO: LOJA -->
    <h6 class="section-title"><i class="bi bi-shop"></i>Loja</h6>
    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6">
            <div class="card-evento">
                <div class="evento-titulo">Trocar moedas por FBA Points</div>
                <div class="text-secondary mb-3">1000 moedas por 100 FBA Points.</div>
                <form method="POST">
                    <input type="hidden" name="acao_loja" value="trocar_moedas">
                    <button type="submit" class="btn btn-success w-100" <?= ((int)($usuario['pontos'] ?? 0) < 1000) ? 'disabled' : '' ?>>
                        Trocar 1000 moedas
                    </button>
                </form>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card-evento">
                <div class="evento-titulo">Badges / Tapas</div>
                <div class="text-secondary mb-2">1 tapa custa 3500 FBA Points.</div>
                <div class="text-secondary mb-3">Comprados no mes: <?= $tapas_compradas_mes ?>/<?= $tapas_limite_mes ?>.</div>
                <form method="POST">
                    <input type="hidden" name="acao_loja" value="comprar_tapa">
                    <button type="submit" class="btn btn-warning w-100" <?= ($tapas_restantes <= 0 || (int)($usuario['fba_points'] ?? 0) < 3500) ? 'disabled' : '' ?>>
                        Comprar 1 tapa
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- SEÇÃO: APOSTAS DISPONÍVEIS -->
    <h6 class="section-title"><i class="bi bi-lightning-charge-fill"></i>Apostas Disponíveis</h6>
    <p class="text-secondary mb-4">Selecione o vencedor. Se acertar, você ganha <strong>75 FBA Points</strong>.</p>

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
                            
                            <div class="form-aposta">
                                <button type="submit" class="btn-apostar">SELECIONAR VENCEDOR</button>
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
                        <th>Recompensa</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($historico_apostas as $aposta): ?>
                        <?php
                            $status_badge = "status-aberta";
                            $status_texto = "<i class='bi bi-hourglass-split me-1'></i>Aberta";
                            $linha_class = "";

                            $status_normalizado = strtolower(trim($aposta['evento_status'] ?? ''));
                            
                            // Verifica se o evento está encerrado/finalizado
                            if (in_array($status_normalizado, ['encerrada', 'finalizada', 'fechada', 'encerrado', 'finalizado', 'fechado'])) {
                                if ($aposta['vencedor_opcao_id'] === null) {
                                    $status_badge = "status-cancelada";
                                    $status_texto = "Cancelado";
                                } elseif ($aposta['vencedor_opcao_id'] == $aposta['opcao_id']) {
                                    $status_badge = "status-venceu";
                                    $status_texto = "<i class='bi bi-trophy-fill me-1'></i>Venceu";
                                    $linha_class = "bet-win";
                                } else {
                                    $status_badge = "status-perdeu";
                                    $status_texto = "<i class='bi bi-x-circle-fill me-1'></i>Perdeu";
                                    $linha_class = "bet-lose";
                                }
                            } elseif (in_array($status_normalizado, ['cancelada', 'cancelado', 'canceled', 'cancelled'])) {
                                $status_badge = "status-cancelada";
                                $status_texto = "Cancelado";
                            }
                        ?>
                        <tr class="<?= $linha_class ?>">
                            <td class="ps-4 text-secondary small">
                                <?= date('d/m/Y H:i', strtotime($aposta['data_palpite'])) ?>
                            </td>
                            <td>
                                <strong class="text-white"><?= htmlspecialchars($aposta['evento_nome']) ?></strong><br>
                                <small class="text-info"><?= htmlspecialchars($aposta['aposta_feita']) ?></small>
                            </td>
                            <td class="fw-bold">
                                75 FBA Points
                            </td>
                            <td>
                                <span class="badge badge-status <?= $status_badge ?> bet-status">
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
