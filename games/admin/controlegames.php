<?php
// controlegames.php - CONTROLE DE JOGOS (DOBRO DE MOEDAS)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

$stmt = $pdo->prepare("SELECT is_admin, nome, pontos, fba_points FROM usuarios WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['is_admin'] != 1) {
    die("Acesso negado: Area restrita a administradores.");
}

$mensagem = '';
$gameKeys = ['memoria', 'termo', 'flappy', 'pinguim', 'ai'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        $stmtUp = $pdo->prepare("INSERT INTO fba_game_controls (game_key, is_double) VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE is_double = VALUES(is_double)");

        foreach ($gameKeys as $key) {
            $val = isset($_POST['double'][$key]) ? (int)$_POST['double'][$key] : 0;
            $stmtUp->execute([':k' => $key, ':v' => ($val === 1 ? 1 : 0)]);
        }

        $pdo->commit();
        $mensagem = "<div class='alert alert-success bg-success bg-opacity-10 border-success text-success'><i class='bi bi-check-circle me-2'></i>Configuracoes salvas.</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $mensagem = "<div class='alert alert-danger bg-danger bg-opacity-10 border-danger text-danger'>Erro ao salvar: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

$config = array_fill_keys($gameKeys, 0);
try {
    $stmtCfg = $pdo->query("SELECT game_key, is_double FROM fba_game_controls");
    while ($row = $stmtCfg->fetch(PDO::FETCH_ASSOC)) {
        $config[$row['game_key']] = (int)$row['is_double'];
    }
} catch (Exception $e) {
}

$labelMap = [
    'memoria' => 'Memoria (vitoria = 200)',
    'termo' => 'Termo (vitoria = 200)',
    'flappy' => 'Flappy (dobrar moedas)',
    'pinguim' => 'Pinguim (dobrar moedas)',
    'ai' => 'AI (select Duplo/Normal)'
];
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Controle de Jogos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; }
        .navbar-custom { background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%); border-bottom: 1px solid #333; padding: 15px; }
        .saldo-badge { background-color: #FC082B; color: #000; padding: 8px 15px; border-radius: 20px; font-weight: 800; font-size: 1.05em; }
        .card-admin { background: #1a1a1a; border: 1px solid #333; }
        .form-select, .form-check-input { background-color: #111; border-color: #444; color: #fff; }
        .form-select:focus, .form-check-input:focus { border-color: #FC082B; box-shadow: 0 0 0 .2rem rgba(252,8,43,.2); }
        .form-check-input:checked { background-color: #FC082B; border-color: #FC082B; }
    </style>
</head>
<body>
<div class="navbar-custom d-flex justify-content-between align-items-center shadow-lg sticky-top">
    <div class="d-flex align-items-center gap-3">
        <span class="fs-5 text-white">Controle de Jogos</span>
    </div>
    <div class="d-flex align-items-center gap-3">
        <a href="/games/admin/dashboard.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar</a>
        <span class="saldo-badge"><i class="bi bi-coin me-1"></i><?= number_format($user['pontos'] ?? 0, 0, ',', '.') ?> moedas</span>
    </div>
</div>

<div class="container py-4">
    <?= $mensagem ?>

    <div class="card card-admin">
        <div class="card-header fw-bold">
            <i class="bi bi-gear-fill me-2"></i>Dobro de moedas por jogo
        </div>
        <div class="card-body">
            <form method="POST">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><?= htmlspecialchars($labelMap['memoria']) ?></div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="memoriaDouble" name="double[memoria]" value="1" <?= $config['memoria'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="memoriaDouble">Duplo</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><?= htmlspecialchars($labelMap['termo']) ?></div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="termoDouble" name="double[termo]" value="1" <?= $config['termo'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="termoDouble">Duplo</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><?= htmlspecialchars($labelMap['flappy']) ?></div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="flappyDouble" name="double[flappy]" value="1" <?= $config['flappy'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="flappyDouble">Duplo</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center">
                            <div><?= htmlspecialchars($labelMap['pinguim']) ?></div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="pinguimDouble" name="double[pinguim]" value="1" <?= $config['pinguim'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pinguimDouble">Duplo</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="aiDoubleSelect"><?= htmlspecialchars($labelMap['ai']) ?></label>
                        <select class="form-select" id="aiDoubleSelect" name="double[ai]">
                            <option value="0" <?= $config['ai'] ? '' : 'selected' ?>>Normal</option>
                            <option value="1" <?= $config['ai'] ? 'selected' : '' ?>>Duplo</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-success"><i class="bi bi-save me-2"></i>Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
