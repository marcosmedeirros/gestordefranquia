<?php
// admin_cafe.php - SISTEMA DE PAGAMENTO DE PONTOS (DARK MODE 🌑)
// VERSÃO: SEM TRAVAS DE SEGURANÇA
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require '../core/conexao.php';

// 1. Segurança Admin
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$stmt = $pdo->prepare("SELECT is_admin, nome, pontos FROM usuarios WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch();
if (!$user || $user['is_admin'] != 1) { die("Acesso negado."); }

$mensagem = "";

// 2. PROCESSAR CONVERSÃO (PAGAMENTO)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'converter') {
    $id_usuario = $_POST['id_usuario'];
    
    try {
        $pdo->beginTransaction();

        $stmtUser = $pdo->prepare("SELECT cafes_feitos, cafes_pagos, cafes_comprados, cafes_comprados_pagos FROM usuarios WHERE id = :id FOR UPDATE");
        $stmtUser->execute([':id' => $id_usuario]);
        $dados = $stmtUser->fetch(PDO::FETCH_ASSOC);

        $pendentes_cafe = $dados['cafes_feitos'] - $dados['cafes_pagos'];
        $pendentes_compra = $dados['cafes_comprados'] - $dados['cafes_comprados_pagos'];

        $total_pontos = 0;
        $detalhes = [];

        if ($pendentes_cafe > 0) {
            $total_pontos += ($pendentes_cafe * 3);
            $detalhes[] = "$pendentes_cafe cafés";
        }

        if ($pendentes_compra > 0) {
            $total_pontos += ($pendentes_compra * 15);
            $detalhes[] = "$pendentes_compra compras";
        }

        if ($total_pontos > 0) {
            $stmtUpd = $pdo->prepare("
                UPDATE usuarios 
                SET pontos = pontos + :pts, 
                    cafes_pagos = cafes_feitos,
                    cafes_comprados_pagos = cafes_comprados
                WHERE id = :id
            ");
            $stmtUpd->execute([':pts' => $total_pontos, ':id' => $id_usuario]);

            $pdo->commit();
            $str_detalhes = implode(" e ", $detalhes);
            $mensagem = "<div class='alert alert-success'>✅ Pago! <strong>$total_pontos pontos</strong> gerados ($str_detalhes).</div>";
        } else {
            $pdo->rollBack();
            $mensagem = "<div class='alert alert-warning'>⚠️ Nada pendente para este usuário.</div>";
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        $mensagem = "<div class='alert alert-danger'>Erro: " . $e->getMessage() . "</div>";
    }
}

// 3. BUSCAR LISTA
$sql = "SELECT id, nome, cafes_feitos, cafes_pagos, cafes_comprados, cafes_comprados_pagos,
        (cafes_feitos - cafes_pagos) as pendentes_cafe,
        (cafes_comprados - cafes_comprados_pagos) as pendentes_compra
        FROM usuarios 
        WHERE cafes_feitos > cafes_pagos OR cafes_comprados > cafes_comprados_pagos
        ORDER BY (cafes_comprados - cafes_comprados_pagos) DESC, (cafes_feitos - cafes_pagos) DESC";
$lista = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <title>Banco do Café</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; }
        .navbar-custom { background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%); padding: 15px; border-bottom: 1px solid #333; }
        .table-dark-custom { --bs-table-bg: #1e1e1e; --bs-table-border-color: #333; }
        .table-dark-custom th { background-color: #252525; color: #fff; }
        .badge-compra { background-color: #FFD700; color: #000; }
        .badge-cafe { background-color: #6f4e37; color: #fff; }
    </style>
</head>
<body>

<div class="navbar-custom d-flex justify-content-between align-items-center shadow-lg sticky-top mb-4">
    <div class="d-flex align-items-center gap-3">
        <span class="fs-5 text-white">Admin: <strong><?= htmlspecialchars($user['nome']) ?></strong></span>
        <a href="dashboard.php" class="btn btn-outline-light btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar</a>
    </div>
</div>

<div class="container mt-5">
    <h2 class="text-white fw-bold mb-4"><i class="bi bi-bank me-2 text-warning"></i>Caixa Central</h2>
    <?= $mensagem ?>

    <div class="card bg-dark border-secondary shadow-lg">
        <div class="card-header border-bottom border-secondary">
            <h5 class="mb-0 text-white"><i class="bi bi-cash-stack me-2"></i>Pagamentos Pendentes</h5>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-dark-custom table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Colaborador</th>
                        <th class="text-center">Pendências</th>
                        <th class="text-center">Total Pontos</th>
                        <th class="text-end pe-3">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($lista)): ?>
                        <tr><td colspan="4" class="text-center py-5 text-muted">Tudo pago! Ninguém deve nada ao sistema.</td></tr>
                    <?php else: ?>
                        <?php foreach($lista as $u): 
                            $pts_cafe = $u['pendentes_cafe'] * 3;
                            $pts_compra = $u['pendentes_compra'] * 15; 
                            $total = $pts_cafe + $pts_compra;
                        ?>
                        <tr>
                            <td class="ps-3 fw-bold"><?= htmlspecialchars($u['nome']) ?></td>
                            <td class="text-center">
                                <?php if($u['pendentes_compra'] > 0): ?>
                                    <span class="badge badge-compra me-1"><?= $u['pendentes_compra'] ?>x Compras</span>
                                <?php endif; ?>
                                <?php if($u['pendentes_cafe'] > 0): ?>
                                    <span class="badge badge-cafe"><?= $u['pendentes_cafe'] ?>x Cafés</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center text-success fw-bold fs-5">+<?= $total ?></td>
                            <td class="text-end pe-3">
                                <form method="POST">
                                    <input type="hidden" name="acao" value="converter">
                                    <input type="hidden" name="id_usuario" value="<?= $u['id'] ?>">
                                    <button class="btn btn-success btn-sm fw-bold shadow-sm">
                                        <i class="bi bi-check-lg me-1"></i>Pagar
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
