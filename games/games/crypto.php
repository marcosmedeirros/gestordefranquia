<?php
// crypto.php - CRYPTO CRASH (O Foguetinho)
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require '../core/conexao.php';
require '../core/avatar.php';

// Segurança
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];

// Dados do Usuário
try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);

    // Criar tabelas se não existirem
    $pdo->exec("CREATE TABLE IF NOT EXISTS crypto_historico (id INT AUTO_INCREMENT PRIMARY KEY, id_usuario INT NOT NULL, aposta INT NOT NULL, ganho INT NOT NULL, multiplicador DECIMAL(5,2), resultado VARCHAR(20), data_jogo DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS crypto_sessoes (id INT AUTO_INCREMENT PRIMARY KEY, id_usuario INT NOT NULL, crash_point DECIMAL(5,2), hash VARCHAR(64), data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP)");

    // Pegar estatísticas
    $stmtStats = $pdo->prepare("SELECT COUNT(*) as total_rodadas, SUM(ganho) as ganho_total, AVG(multiplicador) as multiplicador_medio FROM crypto_historico WHERE id_usuario = :id");
    $stmtStats->execute([':id' => $user_id]);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro DB: " . $e->getMessage());
}

// AJAX - Gerar nova rodada
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');

    if ($_POST['acao'] == 'nova_rodada') {
        $aposta = intval($_POST['aposta']);
        
        // Validar aposta
        if ($aposta < 1 || $aposta > $meu_perfil['pontos']) {
            echo json_encode(['status' => 'erro', 'msg' => 'Aposta inválida']);
            exit;
        }

        // Gerar crash point (0.5x a 5.0x com distribuição exponencial)
        $crash_point = round(pow(2, rand(1, 25) / 10), 2);
        
        // Gerar hash para verificação (segurança)
        $hash = hash('sha256', $crash_point . $user_id . time());
        
        try {
            // Limpar rodadas antigas desta sessão
            $pdo->prepare("DELETE FROM crypto_sessoes WHERE id_usuario = :id AND DATE_ADD(data_criacao, INTERVAL 1 HOUR) < NOW()")->execute([':id' => $user_id]);
            
            // Criar nova sessão
            $stmt = $pdo->prepare("INSERT INTO crypto_sessoes (id_usuario, crash_point, hash) VALUES (:id, :crash, :hash)");
            $stmt->execute([':id' => $user_id, ':crash' => $crash_point, ':hash' => $hash]);
            $sessao_id = $pdo->lastInsertId();

            echo json_encode([
                'status' => 'ok',
                'sessao_id' => $sessao_id,
                'hash' => $hash,
                'crash_point' => $crash_point,
                'aposta' => $aposta
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'erro', 'msg' => 'Erro ao criar rodada']);
        }
        exit;
    }

    // AJAX - Cash Out
    if ($_POST['acao'] == 'cash_out') {
        $sessao_id = intval($_POST['sessao_id']);
        $multiplicador = round(floatval($_POST['multiplicador']), 2);
        $aposta = intval($_POST['aposta']);

        try {
            // Validar sessão
            $stmt = $pdo->prepare("SELECT crash_point FROM crypto_sessoes WHERE id = :id AND id_usuario = :user");
            $stmt->execute([':id' => $sessao_id, ':user' => $user_id]);
            $sessao = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$sessao) {
                echo json_encode(['status' => 'erro', 'msg' => 'Sessão inválida']);
                exit;
            }

            // Verificar se não passou do crash point
            if ($multiplicador >= $sessao['crash_point']) {
                echo json_encode(['status' => 'perdeu', 'msg' => 'Crashed!']);
                
                // Registrar perda
                $stmtPerdeu = $pdo->prepare("INSERT INTO crypto_historico (id_usuario, aposta, ganho, multiplicador, resultado) VALUES (:id, :aposta, :ganho, :mult, 'CRASH')");
                $stmtPerdeu->execute([':id' => $user_id, ':aposta' => $aposta, ':ganho' => 0, ':mult' => $multiplicador]);
                
                exit;
            }

            // Calcular ganho
            $ganho = intval($aposta * $multiplicador);
            
            // Atualizar pontos do usuário
            $stmtUpdate = $pdo->prepare("UPDATE usuarios SET pontos = pontos - :aposta + :ganho WHERE id = :id");
            $stmtUpdate->execute([':aposta' => $aposta, ':ganho' => $ganho, ':id' => $user_id]);

            // Registrar vitória
            $stmtGanhou = $pdo->prepare("INSERT INTO crypto_historico (id_usuario, aposta, ganho, multiplicador, resultado) VALUES (:id, :aposta, :ganho, :mult, 'WIN')");
            $stmtGanhou->execute([':id' => $user_id, ':aposta' => $aposta, ':ganho' => $ganho, ':mult' => $multiplicador]);

            // Buscar novos pontos
            $stmtPontos = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id");
            $stmtPontos->execute([':id' => $user_id]);
            $novo_saldo = $stmtPontos->fetchColumn();

            echo json_encode([
                'status' => 'ok',
                'ganho' => $ganho,
                'lucro' => $ganho - $aposta,
                'novo_saldo' => $novo_saldo
            ]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'erro', 'msg' => 'Erro ao processar']);
        }
        exit;
    }

    // AJAX - Obter histórico de crashes
    if ($_POST['acao'] == 'historico_crashes') {
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT multiplicador 
                FROM crypto_historico 
                WHERE id_usuario = :id 
                ORDER BY data_jogo DESC 
                LIMIT 5
            ");
            $stmt->execute([':id' => $user_id]);
            $crashes = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Formatar para mostrar apenas o número com uma casa decimal
            $crashesFormatados = array_map(function($crash) {
                return number_format($crash, 1, '.', '');
            }, $crashes);

            echo json_encode(['crashes' => $crashesFormatados]);
        } catch (PDOException $e) {
            echo json_encode(['crashes' => []]);
        }
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🚀 Crypto Crash - Pikafumo Games</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/avatar.css">
    <style>
        :root { --accent: #8b1528; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; background-color: #000; color: #fff; }
        h1, h2, h3, h4, h5, h6 { color: #fff; }
        .navbar-custom { background: #000; border-bottom: 1px solid #333; padding: 15px 25px; }
        .navbar-custom .brand-name { font-size: 1.8em; font-weight: bold; color: #fff; text-decoration: none; text-shadow: none; }
        .saldo-badge { background: rgba(46, 213, 115, 0.15); border: 1px solid #2ed573; padding: 8px 15px; border-radius: 6px; color: #2ed573; font-weight: bold; }
        .container-main { max-width: 1000px; margin: 30px auto; padding: 0 20px; }
        .section-title { color: #fff; font-size: 1.3em; font-weight: bold; margin-bottom: 20px; text-shadow: none; }
        .game-container { background: linear-gradient(135deg, rgba(139, 21, 40, 0.05) 0%, rgba(139, 21, 40, 0.02) 100%); border: 2px solid var(--accent); border-radius: 12px; padding: 25px; margin-bottom: 30px; display: flex; flex-direction: column; gap: 20px; }
        .game-title { color: var(--accent); font-size: 1.8em; margin-bottom: 0; text-align: center; font-weight: bold; text-shadow: 0 0 15px rgba(139, 21, 40, 0.4); width: 100%; }
        .game-columns { display: grid; grid-template-columns: 2fr 1fr; gap: 25px; align-items: start; }
        .game-left { display: flex; flex-direction: column; gap: 15px; }
        .game-right { display: flex; flex-direction: column; gap: 15px; }
        .canvas-wrapper { background: #000; border: 1px solid rgba(139, 21, 40, 0.3); border-radius: 8px; padding: 15px; margin-bottom: 0; overflow: hidden; max-width: 100%; }
        canvas { display: block; width: 100%; height: auto; background: #000; max-height: 600px; aspect-ratio: 800 / 400; }
        .stats-grid { display: grid; grid-template-columns: 1fr; gap: 10px; margin-bottom: 0; }
        .stat-card { background: rgba(139, 21, 40, 0.1); border: 1px solid var(--accent); padding: 12px; border-radius: 8px; text-align: center; }
        .stat-label { color: #fff; font-size: 0.8em; margin-bottom: 5px; }
        .stat-value { color: #fff; font-size: 1.4em; font-weight: bold; }
        .control-section { background: rgba(139, 21, 40, 0.1); border: 1px solid var(--accent); border-radius: 8px; padding: 15px; margin-bottom: 0; }
        .control-section label { color: #fff; font-weight: bold; margin-bottom: 8px; display: block; font-size: 0.9em; }
        .form-control { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(139, 21, 40, 0.5); color: #fff; padding: 10px; border-radius: 6px; margin-bottom: 10px; font-size: 0.95em; }
        .form-control:focus { background: rgba(255, 255, 255, 0.08); border-color: var(--accent); color: #fff; box-shadow: 0 0 10px rgba(139, 21, 40, 0.3); }
        .btn-custom { background: var(--accent); color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: all 0.3s; margin-right: 0; margin-bottom: 8px; font-size: 0.95em; }
        .btn-custom:hover { background: #6b0f20; box-shadow: 0 0 15px rgba(139, 21, 40, 0.6); transform: scale(1.05); }
        .btn-custom:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .btn-back { background: transparent; border: 1px solid var(--accent); color: var(--accent); padding: 8px 15px; border-radius: 6px; cursor: pointer; transition: all 0.3s; font-weight: bold; display: block; width: 100%; margin-top: 0; font-size: 0.9em; }
    .btn-back:hover { background: rgba(139, 21, 40, 0.18); }
        .message { padding: 15px; border-radius: 8px; margin-bottom: 20px; display: none; border: 1px solid; }
        .message.show { display: block; }
        .message.success { background: rgba(46, 213, 115, 0.1); border-color: #2ed573; color: #2ed573; }
        .message.error { background: rgba(255, 68, 68, 0.1); border-color: #ff4444; color: #ff4444; }
        .message.crash { background: rgba(139, 21, 40, 0.2); border-color: var(--accent); color: #fff; }
        .crash-history { background: rgba(139, 21, 40, 0.1); border: 1px solid var(--accent); border-radius: 8px; padding: 15px; margin-bottom: 0; }
        .crash-items { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
        .crash-item { background: rgba(139, 21, 40, 0.2); border: 1px solid var(--accent); padding: 8px 12px; border-radius: 6px; color: #fff; font-weight: bold; transition: all 0.3s; font-size: 0.9em; }
        .crash-item:hover { background: rgba(139, 21, 40, 0.4); box-shadow: 0 0 10px rgba(139, 21, 40, 0.5); }
        .history-table { width: 100%; background: rgba(139, 21, 40, 0.05); border: 1px solid var(--accent); border-radius: 8px; overflow: hidden; margin-top: 20px; }
        .history-table table { width: 100%; border-collapse: collapse; }
        .history-table th { background: rgba(139, 21, 40, 0.2); color: #fff; padding: 12px; text-align: left; border-bottom: 1px solid var(--accent); font-weight: bold; }
        .history-table td { padding: 10px 12px; border-bottom: 1px solid rgba(139, 21, 40, 0.2); color: #fff; }
        .history-table tr:hover { background: rgba(139, 21, 40, 0.1); }
        .control-buttons { display: flex; gap: 8px; flex-direction: column; align-items: stretch; }
        .control-buttons .btn-custom { flex: 1; min-width: auto; margin-right: 0; }
        @media (max-width: 1200px) { .game-columns { grid-template-columns: 1fr; } .game-left, .game-right { width: 100%; } canvas { max-height: 450px; } }
        @media (max-width: 768px) { .control-buttons { flex-direction: column; } .control-buttons .btn-custom { width: 100%; margin-right: 0; } .game-columns { grid-template-columns: 1fr; } canvas { max-height: 300px; } }
    </style>
</head>
<body>
<div class="navbar-custom d-flex justify-content-between align-items-center sticky-top">
    <a href="../index.php" class="brand-name">🎮 PIKAFUMO</a>
    <div class="d-flex align-items-center gap-3">
        <div class="d-none d-md-flex align-items-center gap-2">
            <div>
                <span style="color: #999; font-size: 0.9rem;">Bem-vindo(a),</span>
                <strong><?= htmlspecialchars($meu_perfil['nome']) ?></strong>
            </div>
            <div style="width: 36px; height: 51px; display: flex; align-items: center; justify-content: center; border: 1px solid #2ed573; border-radius: 4px;">
                <?php $avatar_user = obterCustomizacaoAvatar($pdo, $user_id); echo renderizarAvatarSVG($avatar_user, 24); ?>
            </div>
        </div>
        <span class="saldo-badge">
            <i class="bi bi-coin me-1"></i><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?> pts
        </span>
        <a href="../index.php" class="btn btn-sm btn-outline-secondary border-0" title="Voltar ao Menu">
            <i class="bi bi-house-fill"></i>
        </a>
    </div>
</div>
<div class="container-main">
    <div id="mensagem" class="message"></div>
    <h5 class="section-title"><i class="bi bi-rocket-fill"></i> 🚀 Crypto Crash</h5>
    <div class="game-container">
        <!-- canvas + stats side-by-side (controls and history moved to full-width rows below) -->
        <div class="game-columns">
            <div class="game-left">
                <div class="canvas-wrapper">
                    <canvas id="gameCanvas" width="1200" height="600"></canvas>
                </div>
            </div>
            <div class="game-right">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label">📊 Rodadas</div>
                        <div class="stat-value"><?= $stats['total_rodadas'] ?? 0 ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">💸 Ganho Total</div>
                        <div class="stat-value"><?= number_format($stats['ganho_total'] ?? 0, 0, ',', '.') ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">📈 Multiplicador</div>
                        <div class="stat-value" id="displayMultiplier">1.00x</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Full-width: Valor da Aposta (linha inteira) -->
        <div class="control-section" style="width:100%; margin-top:10px;">
            <label for="apostaInput">Valor da Aposta (1 - 10)</label>
            <input type="number" id="apostaInput" class="form-control" min="1" max="10" value="10" placeholder="Digite o valor">
            <div class="control-buttons">
                <button class="btn-custom" id="btnPlay" onclick="iniciarRodada()">▶️ JOGAR</button>
                <button class="btn-custom" id="btnCashout" onclick="cashOut()" style="display: none;">💰 CASH OUT</button>
            </div>
        </div>

        <!-- Full-width: Últimos Crashes (linha inteira) -->
        <div class="crash-history" style="width:100%; margin-top:10px;">
            <h6 style="color: var(--accent); margin-bottom: 15px;">🎲 Últimos Crashes</h6>
            <div class="crash-items" id="crashHistory">
                <div style="text-align: center; color: rgba(139, 21, 40, 0.6);">Carregando histórico...</div>
            </div>
        </div>

        <!-- Full-width: botão voltar -->
        <div style="width:100%; margin-top:12px;">
            <button class="btn-back" onclick="window.location.href='../index.php'">← Voltar ao Menu</button>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const canvas = document.getElementById('gameCanvas');
const ctx = canvas.getContext('2d');
let gameState = 'idle';
let currentRound = null;
let currentMultiplier = 1.0;
let animationFrame = 0;
let userSaldo = <?= $meu_perfil['pontos'] ?>;
const gameWidth = canvas.width;
const gameHeight = canvas.height;
const padding = 50;

function desenharTela() {
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, gameWidth, gameHeight);
    ctx.strokeStyle = 'rgba(139, 21, 40, 0.08)';
    ctx.lineWidth = 1;
    for (let i = 0; i < gameWidth; i += 50) {
        ctx.beginPath();
        ctx.moveTo(i, 0);
        ctx.lineTo(i, gameHeight);
        ctx.stroke();
    }
    for (let i = 0; i < gameHeight; i += 50) {
        ctx.beginPath();
        ctx.moveTo(0, i);
        ctx.lineTo(gameWidth, i);
        ctx.stroke();
    }
    ctx.strokeStyle = '#8b1528';
    ctx.lineWidth = 2;
    ctx.beginPath();
    ctx.moveTo(padding, gameHeight - padding);
    ctx.lineTo(gameWidth - padding, gameHeight - padding);
    ctx.stroke();
    ctx.beginPath();
    ctx.moveTo(padding, padding);
    ctx.lineTo(padding, gameHeight - padding);
    ctx.stroke();
    ctx.fillStyle = '#8b1528';
    ctx.font = '12px Courier New';
    ctx.fillText('TEMPO', gameWidth - 100, gameHeight - 20);
    ctx.fillText('MULTIPLICADOR', 10, 20);
    if (gameState === 'idle') {
        ctx.fillStyle = 'rgba(139, 21, 40, 0.55)';
        ctx.font = 'bold 24px Courier New';
        ctx.textAlign = 'center';
        ctx.fillText('Clique em JOGAR para começar', gameWidth / 2, gameHeight / 2);
        ctx.textAlign = 'left';
    }
}

function desenharGrafico() {
    if (!currentRound) return;
    const crashPoint = currentRound.crash_point;
    const maxX = gameWidth - padding;
    const maxY = gameHeight - padding;
    const startX = padding;
    const startY = maxY;
    ctx.strokeStyle = 'rgba(139, 21, 40, 0.35)';
    ctx.lineWidth = 2;
    ctx.setLineDash([5, 5]);
    ctx.beginPath();
    const crashY = maxY - (crashPoint / 5) * (maxY - padding);
    ctx.moveTo(startX, crashY);
    ctx.lineTo(maxX, crashY);
    ctx.stroke();
    ctx.setLineDash([]);
    ctx.fillStyle = 'rgba(139, 21, 40, 0.25)';
    ctx.font = 'bold 14px Courier New';
    ctx.fillText('💥 CRASH: ' + crashPoint.toFixed(2) + 'x', padding + 15, crashY - 15);
    ctx.shadowColor = gameState === 'crashed' ? 'rgba(139, 21, 40, 0.8)' : 'rgba(139, 21, 40, 0.6)';
    ctx.shadowBlur = 10;
    ctx.strokeStyle = gameState === 'crashed' ? '#fff' : '#8b1528';
    ctx.lineWidth = 4;
    ctx.beginPath();
    const steps = Math.min(animationFrame, 400);
    for (let i = 0; i <= steps; i++) {
        const t = i / 400;
        const x = startX + (maxX - startX) * t;
        const progress = Math.pow(t, 1.05);
        const y = startY - (currentMultiplier - 1) * (maxY - padding) * progress * 0.9;
        if (i === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
    }
    ctx.stroke();
    ctx.shadowColor = 'transparent';
    const t = Math.min(animationFrame / 400, 1);
    const rocketX = padding + (maxX - padding) * t;
    const progress = Math.pow(t, 1.2);
    const rocketY = startY - (currentMultiplier - 1) * (maxY - padding) * progress * 0.9;
    if (gameState === 'playing') {
        ctx.fillStyle = `rgba(139, 21, 40, 0.55)`;
        ctx.beginPath();
        ctx.moveTo(rocketX, rocketY + 15);
        ctx.lineTo(rocketX - 5 + Math.random() * 10, rocketY + 30 + Math.random() * 10);
        ctx.lineTo(rocketX + 5 + Math.random() * 10, rocketY + 30 + Math.random() * 10);
        ctx.fill();
    }
    ctx.font = 'bold 35px Arial';
    ctx.textAlign = 'center';
    ctx.shadowColor = 'rgba(139, 21, 40, 0.75)';
    ctx.shadowBlur = 15;
    ctx.fillText('🚀', rocketX, rocketY);
    ctx.shadowColor = 'transparent';
    ctx.textAlign = 'left';
    ctx.fillStyle = gameState === 'crashed' ? '#fff' : '#8b1528';
    ctx.font = 'bold 48px Courier New';
    ctx.fillText('🚀 ' + currentMultiplier.toFixed(2) + 'x', padding + 20, 80);
    if (gameState === 'crashed') {
        ctx.fillStyle = '#fff';
        ctx.font = 'bold 60px Courier New';
        ctx.textAlign = 'center';
        ctx.shadowColor = 'rgba(255, 68, 68, 0.9)';
        ctx.shadowBlur = 30;
        ctx.fillText('💥 CRASHED! 💥', gameWidth / 2, gameHeight / 2);
        ctx.font = 'bold 30px Courier New';
        ctx.fillText('Multiplicador: ' + currentMultiplier.toFixed(2) + 'x', gameWidth / 2, gameHeight / 2 + 50);
        ctx.shadowColor = 'transparent';
        ctx.textAlign = 'left';
    }
}

function animacaoJogo() {
    desenharTela();
    if (gameState !== 'idle') {
        desenharGrafico();
        if (gameState === 'playing') {
            animationFrame += 0.08;
            const t = Math.min(animationFrame / 400, 1);
            currentMultiplier = 1.0 + Math.pow(t, 1.05) * 2.5;
            document.getElementById('displayMultiplier').textContent = currentMultiplier.toFixed(2) + 'x';
            if (currentMultiplier >= currentRound.crash_point) {
                gameState = 'crashed';
                crasharJogo();
            } else {
                requestAnimationFrame(animacaoJogo);
            }
        } else if (gameState === 'crashed') {
            setTimeout(() => {
                resetarJogo();
                desenharTela();
            }, 2000);
        } else {
            requestAnimationFrame(animacaoJogo);
        }
    } else {
        requestAnimationFrame(animacaoJogo);
    }
}

function iniciarRodada() {
    const aposta = parseInt(document.getElementById('apostaInput').value);
    if (isNaN(aposta) || aposta < 1) {
        mostrarMensagem('Aposta inválida!', 'error');
        return;
    }
    if (aposta > userSaldo) {
        mostrarMensagem('Saldo insuficiente!', 'error');
        return;
    }
    document.getElementById('btnPlay').disabled = true;
    fetch('crypto.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'acao=nova_rodada&aposta=' + aposta
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'ok') {
            currentRound = data;
            gameState = 'playing';
            animationFrame = 0;
            currentMultiplier = 1.0;
            document.getElementById('btnCashout').style.display = 'block';
            animacaoJogo();
        } else {
            mostrarMensagem(data.msg || 'Erro ao iniciar rodada', 'error');
            document.getElementById('btnPlay').disabled = false;
        }
    });
}

function cashOut() {
    if (!currentRound || gameState !== 'playing') return;
    document.getElementById('btnCashout').disabled = true;
    gameState = 'win';
    fetch('crypto.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `acao=cash_out&sessao_id=${currentRound.sessao_id}&multiplicador=${currentMultiplier}&aposta=${currentRound.aposta}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'ok') {
            const lucro = data.lucro;
            const msg = lucro > 0 ? `✅ LUCRO! +${lucro}! (Ganho: ${data.ganho})` : `✅ Multiplicador: ${currentMultiplier.toFixed(2)}x | Ganho: ${data.ganho}`;
            mostrarMensagem(msg, 'success');
            userSaldo = data.novo_saldo;
        } else {
            mostrarMensagem(data.msg || 'Erro', 'crash');
            userSaldo -= currentRound.aposta;
        }
        setTimeout(resetarJogo, 3000);
    });
}

function crasharJogo() {
    mostrarMensagem('💥 VOCÊ PERDEU A APOSTA! 💥', 'crash');
    userSaldo -= currentRound.aposta;
    fetch('crypto.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `acao=cash_out&sessao_id=${currentRound.sessao_id}&multiplicador=${currentMultiplier}&aposta=${currentRound.aposta}`
    });
}

function resetarJogo() {
    gameState = 'idle';
    currentRound = null;
    animationFrame = 0;
    currentMultiplier = 1.0;
    document.getElementById('displayMultiplier').textContent = '1.00x';
    document.getElementById('btnPlay').disabled = false;
    document.getElementById('btnCashout').style.display = 'none';
    document.getElementById('btnCashout').disabled = false;
    desenharTela();
    carregarHistoricoCrashes();
}

function mostrarMensagem(texto, tipo) {
    const el = document.getElementById('mensagem');
    el.textContent = texto;
    el.className = 'message show ' + tipo;
    setTimeout(() => {
        el.classList.remove('show');
    }, 5000);
}

desenharTela();
animacaoJogo();

function carregarHistoricoCrashes() {
    fetch('crypto.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'acao=historico_crashes'
    })
    .then(r => r.json())
    .then(data => {
        const container = document.getElementById('crashHistory');
        if (data.crashes && data.crashes.length > 0) {
            const items = data.crashes.map(crash => `<div class="crash-item">${crash}x</div>`).join('');
            container.innerHTML = items;
        } else {
            container.innerHTML = '<div style="text-align: center; color: rgba(139, 21, 40, 0.6);">Nenhum crash registrado ainda</div>';
        }
    })
    .catch(e => console.error('Erro:', e));
}

carregarHistoricoCrashes();
setInterval(carregarHistoricoCrashes, 5000);
</script>
</body>
</html>
