<?php
// cafe.php - CLUBE DO CAFÉ (DARK MODE ☕🌙)
// VERSÃO: SEM TRAVAS DE SEGURANÇA
// session_start já foi chamado em games/index.php
require '../core/conexao.php';

// 1. Segurança de Sessão
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$msg = "";

// 2. Processar Ações (Fazer Café ou Comprar Pó)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['acao'])) {
        try {
            // --- AÇÕES DE FAZER CAFÉ ---
            if ($_POST['acao'] == 'add_cafe') {
                $pdo->prepare("UPDATE usuarios SET cafes_feitos = cafes_feitos + 1 WHERE id = :id")
                    ->execute([':id' => $user_id]);
                $msg = "<div class='alert alert-success bg-success bg-opacity-25 text-success border-success'><i class='bi bi-check-circle-fill me-2'></i>☕ Café registrado!</div>";
            
            } elseif ($_POST['acao'] == 'remove_cafe') {
                $pdo->prepare("UPDATE usuarios SET cafes_feitos = cafes_feitos - 1 WHERE id = :id AND cafes_feitos > 0")
                    ->execute([':id' => $user_id]);
                $msg = "<div class='alert alert-warning bg-warning bg-opacity-10 text-warning border-warning'><i class='bi bi-dash-circle me-2'></i>Café removido.</div>";
            
            // --- AÃ‡Ã•ES DE COMPRAR PÃ“ ---
            } elseif ($_POST['acao'] == 'add_compra') {
                $pdo->prepare("UPDATE usuarios SET cafes_comprados = cafes_comprados + 1 WHERE id = :id")
                    ->execute([':id' => $user_id]);
                $msg = "<div class='alert alert-info bg-info bg-opacity-25 text-info border-info'><i class='bi bi-bag-heart-fill me-2'></i>ðŸ›ï¸ Compra registrada! (+15 pts pendentes).</div>";

            } elseif ($_POST['acao'] == 'remove_compra') {
                $pdo->prepare("UPDATE usuarios SET cafes_comprados = cafes_comprados - 1 WHERE id = :id AND cafes_comprados > 0")
                    ->execute([':id' => $user_id]);
                $msg = "<div class='alert alert-secondary bg-secondary bg-opacity-10 text-secondary border-secondary'><i class='bi bi-trash me-2'></i>Registro de compra removido.</div>";
            }

        } catch (PDOException $e) {
            $msg = "<div class='alert alert-danger'>Erro: " . $e->getMessage() . "</div>";
        }
    }
}

// 3. Buscar Dados do Usuário
$stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin, cafes_feitos, cafes_comprados FROM usuarios WHERE id = :id");
$stmtMe->execute([':id' => $user_id]);
$usuario = $stmtMe->fetch(PDO::FETCH_ASSOC);

// 4. Rankings
$stmtRankFeitos = $pdo->query("SELECT nome, cafes_feitos FROM usuarios WHERE cafes_feitos > 0 ORDER BY cafes_feitos DESC LIMIT 10");
$rankingFeitos = $stmtRankFeitos->fetchAll(PDO::FETCH_ASSOC);

$stmtRankComprou = $pdo->query("SELECT nome, cafes_comprados FROM usuarios WHERE cafes_comprados > 0 ORDER BY cafes_comprados DESC LIMIT 10");
$rankingComprou = $stmtRankComprou->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clube do Café</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>☕</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        body { background-color: #121212; font-family: 'Segoe UI', sans-serif; color: #e0e0e0; }
        .navbar-custom { background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%); border-bottom: 1px solid #333; padding: 15px; }
        .saldo-badge { background-color: #00e676; color: #000; padding: 8px 15px; border-radius: 20px; font-weight: 800; box-shadow: 0 0 10px rgba(0, 230, 118, 0.3); }
        .admin-btn { background-color: #ff6d00; color: white; padding: 5px 15px; border-radius: 20px; text-decoration: none; font-weight: bold; font-size: 0.9em; transition: 0.3s; }
        .admin-btn:hover { background-color: #e65100; color: white; }

        /* Cards */
        .card-coffee { background-color: #1e1e1e; border: 1px solid #6f4e37; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.3); }
        .card-patron { background-color: #1e1e1e; border: 1px solid #FFD700; border-radius: 15px; box-shadow: 0 4px 15px rgba(255, 215, 0, 0.1); } 
        
        .btn-coffee-add { background-color: #6f4e37; color: white; border: none; font-weight: bold; transition: 0.2s; }
        .btn-coffee-add:hover { background-color: #5d4037; color: white; transform: scale(1.02); }

        .btn-buy-add { background-color: #FFD700; color: #000; border: none; font-weight: bold; transition: 0.2s; }
        .btn-buy-add:hover { background-color: #ffca28; transform: scale(1.02); box-shadow: 0 0 15px rgba(255, 215, 0, 0.4); }

        .big-number { font-size: 3.5rem; font-weight: 800; line-height: 1; }
        
        .table-coffee th { background-color: #252525; color: #d7ccc8; border-color: #444; }
        .table-coffee td { border-color: #333; }
        .table-patron th { background-color: #252525; color: #ffecb3; border-color: #444; }
    </style>
</head>
<body>

    <!-- Navbar -->
    <div class="navbar-custom d-flex justify-content-between align-items-center shadow-lg sticky-top">
        <div class="d-flex align-items-center gap-3">
            <span class="fs-5">Olá, <strong><?= htmlspecialchars($usuario['nome']) ?></strong></span>
            <?php if (!empty($usuario['is_admin']) && $usuario['is_admin'] == 1): ?>
                <a href="admin_cafe.php" class="admin-btn"><i class="bi bi-gear-fill me-1"></i> Admin</a>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-3">
            <a href="../index.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar</a>
            <span class="saldo-badge me-2"><?= number_format($usuario['pontos'], 0, ',', '.') ?> pts</span>
        </div>
    </div>

    <div class="container mt-5 mb-5">
        <?= $msg ?>

        <div class="row g-4">
            <!-- COLUNA ESQUERDA: Ações -->
            <div class="col-md-4">
                
                <!-- 1. Card FAZER CAFÉ -->
                <div class="card card-coffee text-center p-4 mb-4">
                    <h5 class="text-white-50 mb-2"><i class="bi bi-cup-hot-fill me-2"></i>Cafés Passados</h5>
                    <div class="big-number text-coffee" style="color: #ffcc80;">
                        <?= $usuario['cafes_feitos'] ?>
                    </div>
                    <hr class="border-secondary my-3">
                    
                    <button type="button" class="btn btn-coffee-add w-100 py-3 mb-2 rounded-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalCafe" onclick="prepararModal('cafe')">
                        <i class="bi bi-plus-circle-fill me-2"></i>FIZ CAFÉ (+3 pts)
                    </button>

                    <?php if($usuario['cafes_feitos'] > 0): ?>
                        <form method="POST" onsubmit="return confirm('Remover 1 café?');">
                            <input type="hidden" name="acao" value="remove_cafe">
                            <button class="btn btn-outline-danger btn-sm w-100 border-0"><i class="bi bi-dash-circle"></i> Diminuir</button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- 2. Card COMPRAR PÃ“ (NOVO) -->
                <div class="card card-patron text-center p-4">
                    <h5 class="text-white-50 mb-2"><i class="bi bi-bag-check-fill me-2"></i>Pacotes Comprados</h5>
                    <div class="big-number" style="color: #FFD700;">
                        <?= $usuario['cafes_comprados'] ?>
                    </div>
                    <hr class="border-secondary my-3">
                    
                    <!-- Botão Modal Comprar (Texto Atualizado) -->
                    <button type="button" class="btn btn-buy-add w-100 py-3 mb-2 rounded-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalCafe" onclick="prepararModal('compra')">
                        <i class="bi bi-cart-plus-fill me-2"></i>COMPREI PÓ (+15 pts)
                    </button>

                    <?php if($usuario['cafes_comprados'] > 0): ?>
                        <form method="POST" onsubmit="return confirm('Remover 1 compra?');">
                            <input type="hidden" name="acao" value="remove_compra">
                            <button class="btn btn-outline-danger btn-sm w-100 border-0"><i class="bi bi-dash-circle"></i> Diminuir</button>
                        </form>
                    <?php endif; ?>
                </div>

            </div>

            <!-- COLUNA DIREITA: Rankings -->
            <div class="col-md-8">
                
                <!-- Ranking 1: MESTRES DO CAFÉ -->
                <div class="card card-coffee mb-4">
                    <div class="card-header bg-transparent border-0 pt-4 px-4">
                        <h3 class="fw-bold text-white"><i class="bi bi-trophy-fill me-2 text-warning"></i>Mestres do Café</h3>
                        <p class="text-secondary small">Quem coloca a mão na massa (ou na água quente).</p>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-coffee table-dark table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">#</th>
                                    <th>Nome</th>
                                    <th class="text-end pe-4">Feitos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $pos=1; foreach($rankingFeitos as $r): 
                                    $isMe = ($r['nome'] == $usuario['nome']);
                                ?>
                                <tr style="<?= $isMe ? 'background: rgba(111,78,55,0.2)' : '' ?>">
                                    <td class="ps-4 text-secondary fw-bold"><?= $pos++ ?>º</td>
                                    <td><?= htmlspecialchars($r['nome']) ?> <?= $isMe ? '<span class="badge bg-success ms-1">Eu</span>' : '' ?></td>
                                    <td class="text-end pe-4 fw-bold text-warning fs-5"><?= $r['cafes_feitos'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Ranking 2: MECENAS DO CAFÉ -->
                <div class="card card-patron">
                    <div class="card-header bg-transparent border-0 pt-4 px-4">
                        <h3 class="fw-bold text-white"><i class="bi bi-gem me-2" style="color: #FFD700;"></i>Mecenas do Café</h3>
                        <p class="text-secondary small">Quem abre a carteira para manter o estoque em dia.</p>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-patron table-dark table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">#</th>
                                    <th>Nome</th>
                                    <th class="text-end pe-4">Pacotes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($rankingComprou) == 0): ?>
                                    <tr><td colspan="3" class="text-center py-3 text-muted">Ainda ninguém registrou compras. Seja o primeiro!</td></tr>
                                <?php else: ?>
                                    <?php $pos=1; foreach($rankingComprou as $r): 
                                        $isMe = ($r['nome'] == $usuario['nome']);
                                    ?>
                                    <tr style="<?= $isMe ? 'background: rgba(255, 215, 0, 0.1)' : '' ?>">
                                        <td class="ps-4 text-secondary fw-bold"><?= $pos++ ?>º</td>
                                        <td><?= htmlspecialchars($r['nome']) ?> <?= $isMe ? '<span class="badge bg-success ms-1">Eu</span>' : '' ?></td>
                                        <td class="text-end pe-4 fw-bold fs-5" style="color: #FFD700;"><?= $r['cafes_comprados'] ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- MODAL ÃšNICO -->
    <div class="modal fade" id="modalCafe" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Confirmar Ação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <p class="mb-3 text-secondary" id="modalDesc">Digite a palavra chave:</p>
                    <input type="text" id="inputPalavraChave" class="form-control form-control-lg text-center fw-bold text-uppercase" autocomplete="off">
                </div>
                <div class="modal-footer justify-content-center border-0 pb-4">
                    <form method="POST" id="formAction">
                        <input type="hidden" name="acao" id="inputAcao" value="">
                        <button type="button" id="btnConfirmar" class="btn btn-success px-4" disabled>Confirmar</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const input = document.getElementById('inputPalavraChave');
        const btn = document.getElementById('btnConfirmar');
        const form = document.getElementById('formAction');
        const inputAcao = document.getElementById('inputAcao');
        const modalTitle = document.getElementById('modalTitle');
        const modalDesc = document.getElementById('modalDesc');

        let palavraCorreta = "";

        function prepararModal(tipo) {
            input.value = '';
            btn.disabled = true;
            input.classList.remove('is-valid');

            if (tipo === 'cafe') {
                modalTitle.innerHTML = '<i class="bi bi-cup-hot-fill me-2"></i>Fiz Café';
                modalDesc.innerHTML = 'Para confirmar, digite <strong>CAFE</strong>:';
                inputAcao.value = 'add_cafe';
                input.placeholder = 'CAFE';
                palavraCorreta = 'CAFE';
            } else {
                modalTitle.innerHTML = '<i class="bi bi-cart-plus-fill me-2 text-warning"></i>Comprei PÃ³';
                modalDesc.innerHTML = 'Para confirmar, digite <strong>COMPRA</strong>:';
                inputAcao.value = 'add_compra';
                input.placeholder = 'COMPRA';
                palavraCorreta = 'COMPRA';
            }
        }

        input.addEventListener('input', function() {
            if (this.value.toUpperCase() === palavraCorreta) {
                btn.disabled = false;
                this.classList.add('is-valid');
            } else {
                btn.disabled = true;
                this.classList.remove('is-valid');
            }
        });

        btn.addEventListener('click', () => form.submit());
    </script>
</body>
</html>
