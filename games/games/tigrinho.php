<?php
// tigrinho.php - Fortune Tiger (FBA games)
ini_set('display_errors', 1);
error_reporting(E_ALL);
// session_start já foi chamado em games/index.php
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tigrinho_historico (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        aposta INT NOT NULL,
        premio INT NOT NULL,
        simbolos VARCHAR(255) NOT NULL,
        data_jogo DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro perfil: " . $e->getMessage());
}

$symbols = [
    ['id' => 'tiger', 'label' => '🐯', 'weight' => 5, 'mult' => 5],
    ['id' => 'coin', 'label' => '🪙', 'weight' => 6, 'mult' => 4],
    ['id' => 'lotus', 'label' => '🌸', 'weight' => 7, 'mult' => 3],
    ['id' => 'bamboo', 'label' => '🎋', 'weight' => 8, 'mult' => 2],
    ['id' => 'cherry', 'label' => '🍒', 'weight' => 10, 'mult' => 1]
];

$house_edge = 0.65; // 65% das vezes a casa leva

function spinSymbol($symbols) {
    $total = array_sum(array_column($symbols, 'weight'));
    $rand = mt_rand(1, $total);
    foreach ($symbols as $s) {
        $rand -= $s['weight'];
        if ($rand <= 0) return $s;
    }
    return $symbols[0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'girar') {
    header('Content-Type: application/json');

    $aposta = isset($_POST['aposta']) ? (int)$_POST['aposta'] : 0;
    if ($aposta < 1 || $aposta > 5) {
        echo json_encode(['erro' => 'A aposta deve ser entre 1 e 5 pontos.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $stmtSaldo = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
        $stmtSaldo->execute([':id' => $user_id]);
        $saldo = (int)$stmtSaldo->fetchColumn();

        if ($saldo < $aposta) {
            $pdo->rollBack();
            echo json_encode(['erro' => 'Saldo insuficiente.']);
            exit;
        }

        $s1 = spinSymbol($symbols);
        $s2 = spinSymbol($symbols);
        $s3 = spinSymbol($symbols);

        $premio = 0;
        $is_win = ($s1['id'] === $s2['id'] && $s2['id'] === $s3['id'])
            || ($s1['id'] === $s2['id'] || $s1['id'] === $s3['id'] || $s2['id'] === $s3['id']);

        if ($is_win) {
            $rand_edge = mt_rand(1, 100) / 100;
            if ($rand_edge <= $house_edge) {
                // Força derrota: troca o terceiro símbolo para quebrar a combinação
                do {
                    $s3 = spinSymbol($symbols);
                } while ($s3['id'] === $s1['id'] || $s3['id'] === $s2['id']);
                $is_win = false;
            }
        }

        if ($is_win) {
            if ($s1['id'] === $s2['id'] && $s2['id'] === $s3['id']) {
                $premio = $aposta * $s1['mult'];
            } else {
                $premio = $aposta;
            }
        }

        $novo_saldo = $saldo - $aposta + $premio;

        $stmtUpd = $pdo->prepare("UPDATE usuarios SET pontos = :p WHERE id = :id");
        $stmtUpd->execute([':p' => $novo_saldo, ':id' => $user_id]);

        $stmtHist = $pdo->prepare("INSERT INTO tigrinho_historico (id_usuario, aposta, premio, simbolos) VALUES (:id, :aposta, :premio, :simbolos)");
        $stmtHist->execute([
            ':id' => $user_id,
            ':aposta' => $aposta,
            ':premio' => $premio,
            ':simbolos' => json_encode([$s1['id'], $s2['id'], $s3['id']])
        ]);

        $pdo->commit();

        echo json_encode([
            'sucesso' => true,
            'reels' => [$s1['label'], $s2['label'], $s3['label']],
            'premio' => $premio,
            'saldo' => $novo_saldo
        ]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['erro' => 'Erro ao girar.']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fortune Tiger - FBA games</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🐯</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; }
        .navbar-custom { background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%); border-bottom: 1px solid #333; padding: 15px; }
        .saldo-badge { background-color: #FC082B; color: #000; padding: 8px 15px; border-radius: 20px; font-weight: 800; font-size: 1.1em; box-shadow: 0 0 10px rgba(252, 8, 43, 0.3); }
        .admin-btn { background-color: #ff6d00; color: white; padding: 5px 15px; border-radius: 20px; text-decoration: none; font-weight: bold; font-size: 0.9em; transition: 0.3s; }
        .admin-btn:hover { background-color: #e65100; color: white; box-shadow: 0 0 8px #ff6d00; }
        .slot-card { background: #1e1e1e; border: 1px solid #333; border-radius: 16px; padding: 24px; box-shadow: 0 0 20px rgba(0,0,0,0.4); }
        .reels { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 20px 0; }
        .reel { background: #111; border: 2px solid #333; border-radius: 14px; height: 90px; display: flex; align-items: center; justify-content: center; font-size: 2.2rem; transition: transform 0.25s ease; }
        .reel.spinning { animation: reelSpin 0.28s linear infinite; border-color: #FC082B; box-shadow: 0 0 12px rgba(252, 8, 43, 0.4); }
        .reel.stop { animation: reelStop 0.4s ease; transform: translateY(0); }
        @keyframes reelSpin {
            0% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
            100% { transform: translateY(0); }
        }
        @keyframes reelStop {
            0% { transform: translateY(-10px); }
            70% { transform: translateY(4px); }
            100% { transform: translateY(0); }
        }
        .btn-spin { background: #FC082B; border: none; color: #000; font-weight: 800; }
        .btn-spin:disabled { opacity: 0.6; }
        .info-pill { background: #222; border: 1px solid #333; border-radius: 999px; padding: 6px 12px; font-size: 0.9rem; }
    </style>
</head>
<body>
    <div class="navbar-custom d-flex justify-content-between align-items-center shadow-lg sticky-top">
        <div class="d-flex align-items-center gap-3">
            <span class="fs-5">Olá, <strong><?= htmlspecialchars($meu_perfil['nome']) ?></strong></span>
            <?php if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1): ?>
                <a href="../admin/dashboard.php" class="admin-btn"><i class="bi bi-gear-fill me-1"></i> Admin</a>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-3">
            <a href="../index.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar ao Painel</a>
            <span class="saldo-badge me-2" id="saldoDisplay"><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?> pts</span>
        </div>
    </div>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-6">
                <div class="slot-card text-center">
                    <h3 class="fw-bold mb-2">🐯 Fortune Tiger</h3>
                    <p class="text-secondary mb-4">Aposte de 1 a 5 pontos por giro. Três iguais pagam mais!</p>

                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                        <span class="info-pill">3 iguais = 5x / 4x / 3x / 2x / 1x</span>
                        <span class="info-pill">2 iguais = 1x</span>
                    </div>

                    <div class="reels" id="reels">
                        <div class="reel" id="reel-1">🐯</div>
                        <div class="reel" id="reel-2">🌸</div>
                        <div class="reel" id="reel-3">🪙</div>
                    </div>

                    <div class="row g-2 align-items-center">
                        <div class="col-6">
                            <input type="number" min="1" max="5" value="1" class="form-control text-center" id="betInput">
                        </div>
                        <div class="col-6">
                            <button class="btn btn-spin w-100" id="spinBtn"><i class="bi bi-lightning-fill me-1"></i> Girar</button>
                        </div>
                    </div>

                    <div id="resultMsg" class="alert alert-dark border-secondary mt-3 d-none"></div>
                </div>
            </div>
        </div>
    </div>

<script>
    const spinBtn = document.getElementById('spinBtn');
    const betInput = document.getElementById('betInput');
    const resultMsg = document.getElementById('resultMsg');
    const saldoDisplay = document.getElementById('saldoDisplay');
    const reels = [
        document.getElementById('reel-1'),
        document.getElementById('reel-2'),
        document.getElementById('reel-3')
    ];
    const symbolsPool = ['🐯', '🪙', '🌸', '🎋', '🍒'];
    let spinTimer = null;

    function rollSymbols() {
        reels.forEach(reel => {
            reel.textContent = symbolsPool[Math.floor(Math.random() * symbolsPool.length)];
        });
    }

    function startSpinAnimation() {
        reels.forEach(reel => { reel.classList.add('spinning'); reel.classList.remove('stop'); });
        rollSymbols();
        spinTimer = setInterval(rollSymbols, 200);
    }

    function stopSpinAnimation(finalReels) {
        clearInterval(spinTimer);
        spinTimer = null;
        reels.forEach((reel, idx) => {
            setTimeout(() => {
                reel.classList.remove('spinning');
                reel.classList.add('stop');
                reel.textContent = finalReels[idx];
                setTimeout(() => reel.classList.remove('stop'), 500);
            }, 700 + (idx * 550));
        });
    }

    function setReels(reels) {
        document.getElementById('reel-1').textContent = reels[0];
        document.getElementById('reel-2').textContent = reels[1];
        document.getElementById('reel-3').textContent = reels[2];
    }

    function showMessage(text, type = 'info') {
        resultMsg.className = `alert alert-${type} border-secondary mt-3`;
        resultMsg.textContent = text;
        resultMsg.classList.remove('d-none');
        setTimeout(() => resultMsg.classList.add('d-none'), 3500);
    }

    spinBtn.addEventListener('click', () => {
        const bet = parseInt(betInput.value || '0', 10);
        if (Number.isNaN(bet) || bet < 1 || bet > 5) {
            showMessage('Aposta inválida. Use 1 a 5 pontos.', 'warning');
            return;
        }

        spinBtn.disabled = true;
        startSpinAnimation();
        fetch('index.php?game=tigrinho', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `acao=girar&aposta=${bet}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.erro) {
                stopSpinAnimation(['❌', '❌', '❌']);
                showMessage(data.erro, 'danger');
                return;
            }
            stopSpinAnimation(data.reels);
            saldoDisplay.textContent = `${data.saldo.toLocaleString('pt-BR')} pts`;
            if (data.premio > 0) {
                showMessage(`Você ganhou ${data.premio} pts!`, 'success');
            } else {
                showMessage('Não foi dessa vez. Tente novamente!', 'secondary');
            }
        })
        .catch(() => {
            stopSpinAnimation(['❌', '❌', '❌']);
            showMessage('Erro ao girar. Tente novamente.', 'danger');
        })
        .finally(() => { spinBtn.disabled = false; });
    });
</script>
</body>
</html>
