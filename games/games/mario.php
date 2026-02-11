<?php
// mario.php - MARIO JUMP (Jogo Completo com Vidas e Dificuldade)
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require '../core/conexao.php';

// Segurança
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];

// Dados do Usuário
try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);

    // Criar tabela de histórico se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS mario_historico (id INT AUTO_INCREMENT PRIMARY KEY, id_usuario INT NOT NULL, pontuacao INT NOT NULL, data_jogo DATETIME DEFAULT CURRENT_TIMESTAMP)");

    // Pegar recorde
    $stmtRecorde = $pdo->prepare("SELECT MAX(pontuacao) FROM mario_historico WHERE id_usuario = :id");
    $stmtRecorde->execute([':id' => $user_id]);
    $recorde = $stmtRecorde->fetchColumn() ?: 0;
} catch (PDOException $e) {
    die("Erro DB: " . $e->getMessage());
}

// AJAX - Salvar Score
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');

    if ($_POST['acao'] == 'salvar_score') {
        $score = intval($_POST['score']);
        try {
            $stmt = $pdo->prepare("INSERT INTO mario_historico (id_usuario, pontuacao) VALUES (:id, :score)");
            $stmt->execute([':id' => $user_id, ':score' => $score]);
            echo json_encode(['status' => 'ok', 'score' => $score]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'erro', 'msg' => $e->getMessage()]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🍄 Mario Jump - Pikafumo Games</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 400px;
        }

        .header {
            text-align: center;
            color: white;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }

        .stats {
            display: flex;
            justify-content: space-around;
            gap: 10px;
            margin-bottom: 20px;
        }

        .stat-box {
            background: rgba(255,255,255,0.2);
            padding: 15px;
            border-radius: 10px;
            color: white;
            text-align: center;
            flex: 1;
            backdrop-filter: blur(10px);
        }

        .stat-box h3 {
            font-size: 0.9em;
            opacity: 0.8;
            margin-bottom: 5px;
        }

        .stat-box .value {
            font-size: 1.8em;
            font-weight: bold;
        }

        .lives-display {
            font-size: 1.5em;
            margin-top: 5px;
        }

        .life-icon {
            display: inline-block;
            margin-right: 5px;
        }

        .level-display {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.5);
            color: #fff;
            padding: 10px 15px;
            border-radius: 5px;
            font-weight: bold;
            font-size: 1.1em;
        }

        .game-canvas-container {
            background: linear-gradient(180deg, #87ceeb 0%, #e0f6ff 100%);
            border: 4px solid #333;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 8px 20px rgba(0,0,0,0.3);
            margin-bottom: 20px;
        }

        canvas {
            display: block;
            width: 100%;
            height: auto;
        }

        .controls {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        button {
            flex: 1;
            padding: 15px;
            font-size: 1.1em;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-start {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }

        .btn-start:hover {
            transform: scale(1.05);
            box-shadow: 0 5px 15px rgba(245, 87, 108, 0.4);
        }

        .btn-start:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            backdrop-filter: blur(10px);
        }

        .btn-back:hover {
            background: rgba(255,255,255,0.3);
        }

        .info-box {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 8px;
            color: white;
            text-align: center;
            margin-bottom: 15px;
            backdrop-filter: blur(10px);
            font-size: 0.9em;
        }

        .info-box p {
            margin: 3px 0;
            font-size: 0.85em;
        }

        .powerup-info {
            display: flex;
            justify-content: space-around;
            gap: 5px;
            margin-top: 10px;
            font-size: 0.8em;
        }

        .powerup-item {
            flex: 1;
            background: rgba(0,0,0,0.3);
            padding: 5px;
            border-radius: 5px;
        }

        @media (max-width: 600px) {
            .header h1 {
                font-size: 2em;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🍄 Mario Jump</h1>
            <p style="font-size: 0.95em; opacity: 0.9;">Protótipo em Desenvolvimento</p>
        </div>

        <div class="stats">
            <div class="stat-box">
                <h3>Score Atual</h3>
                <div class="value" id="currentScore">0</div>
            </div>
            <div class="stat-box">
                <h3>Nível</h3>
                <div class="value" id="levelDisplay">1</div>
            </div>
            <div class="stat-box">
                <h3>Vidas</h3>
                <div class="value" id="livesDisplay">❤️ ❤️ ❤️</div>
            </div>
            <div class="stat-box">
                <h3>Recorde</h3>
                <div class="value" id="recordScore"><?php echo $recorde; ?></div>
            </div>
        </div>

        <div class="info-box">
            <p>⬅️ ➡️ Use as setas ou A/D para se mover</p>
            <p>🪙 Colete moedas para ganhar bônus!</p>
            <p>😈 Evite inimigos ou perca vidas!</p>
            <div class="powerup-info">
                <div class="powerup-item">⭐ Shield (+1 vida)</div>
                <div class="powerup-item">🪙 Moeda (+5 pts)</div>
                <div class="powerup-item">🔥 Speed (2x pontos)</div>
            </div>
        </div>

        <div class="game-canvas-container">
            <canvas id="gameCanvas" width="400" height="600"></canvas>
            <div class="level-display" id="levelInGame">Nível: 1</div>
        </div>

        <div class="controls">
            <button class="btn-start" id="startBtn" onclick="startGame()">▶️ Iniciar Jogo</button>
            <button class="btn-back" onclick="window.location.href='index.php'">← Voltar</button>
        </div>
    </div>

    <script>
        const canvas = document.getElementById('gameCanvas');
        const ctx = canvas.getContext('2d');
        
        // VARIÁVEIS DO JOGO
        let gameRunning = false;
        let gameOver = false;
        let score = 0;
        let level = 1;
        let lives = 3;
        let gameStarted = false;
        let distanceTraveled = 0;
        let speedMultiplier = 1;
        let shieldActive = false;

        // Mario
        const mario = {
            x: canvas.width / 2 - 15,
            y: canvas.height - 100,
            width: 30,
            height: 40,
            velocityY: 0,
            speed: 5,
            jumpPower: 12
        };

        // Arrays de objetos
        let platforms = [];
        let enemies = [];
        let powerups = [];

        // Controles
        const keys = { left: false, right: false };

        // Event Listeners
        window.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft' || e.key.toLowerCase() === 'a') keys.left = true;
            if (e.key === 'ArrowRight' || e.key.toLowerCase() === 'd') keys.right = true;
        });

        window.addEventListener('keyup', (e) => {
            if (e.key === 'ArrowLeft' || e.key.toLowerCase() === 'a') keys.left = false;
            if (e.key === 'ArrowRight' || e.key.toLowerCase() === 'd') keys.right = false;
        });

        function respawnOnPlatform() {
            // Pega uma plataforma aleatória (exceto o chão)
            let availablePlatforms = platforms.filter(p => !p.isFloor);
            if (availablePlatforms.length > 0) {
                let platform = availablePlatforms[Math.floor(Math.random() * availablePlatforms.length)];
                mario.x = platform.x + (platform.width / 2) - (mario.width / 2);
                mario.y = platform.y - mario.height - 5;
            } else {
                // Se não houver plataforma, volta pro chão
                mario.x = canvas.width / 2 - 15;
                mario.y = canvas.height - 100;
            }
            mario.velocityY = 0;
        }

        function getEnemyCount() { return 1 + Math.floor(level / 3); }
        function getEnemySpeed() { return 1.5 + (level * 0.3); }
        function getPlatformCount() { return 7 + Math.floor(level / 2); }

        function initGame() {
            platforms = [];
            enemies = [];
            powerups = [];
            distanceTraveled = 0;
            speedMultiplier = 1;
            
            // CHÃO permanente
            platforms.push({
                x: 0, y: canvas.height - 50,
                width: canvas.width, height: 50,
                color: '#8B4513', isFloor: true
            });
            
            // Plataformas iniciais
            for (let i = 0; i < getPlatformCount(); i++) {
                let platformColor = '#ff6b9d';
                if (Math.random() < 0.2) platformColor = '#FFD700'; // Platform dourada (mais pontos)
                if (Math.random() < 0.1) platformColor = '#8B008B'; // Platform roxa (trêmula)
                
                platforms.push({
                    x: Math.random() * (canvas.width - 60),
                    y: canvas.height - 150 - (i * 80),
                    width: 60,
                    height: 12,
                    color: platformColor,
                    isFloor: false,
                    tremble: platformColor === '#8B008B',
                    trembleAmount: 0
                });
            }
            
            // Inimigos
            for (let i = 0; i < getEnemyCount(); i++) {
                enemies.push({
                    x: Math.random() * (canvas.width - 30),
                    y: 200 + i * 120,
                    width: 30,
                    height: 30,
                    velocityX: (Math.random() - 0.5) * getEnemySpeed(),
                    type: Math.random() < 0.3 ? 'spike' : 'normal'
                });
            }
            
            mario.x = canvas.width / 2 - 15;
            mario.y = canvas.height - 100;
            mario.velocityY = 0;
        }

        function updateLevel() {
            level = 1 + Math.floor(distanceTraveled / 1000);
            document.getElementById('levelDisplay').textContent = level;
            document.getElementById('levelInGame').textContent = 'Nível: ' + level;
        }

        function updateLivesDisplay() {
            let hearts = '';
            for (let i = 0; i < lives; i++) hearts += '❤️ ';
            document.getElementById('livesDisplay').textContent = hearts || '💀';
        }

        function startGame() {
            if (!gameStarted) {
                gameStarted = true;
                gameRunning = true;
                gameOver = false;
                score = 0;
                level = 1;
                lives = 3;
                initGame();
                updateLivesDisplay();
                document.getElementById('startBtn').disabled = true;
                document.getElementById('startBtn').textContent = 'Jogo em andamento...';
                gameLoop();
            }
        }

        function gameLoop() {
            update();
            draw();
            if (gameRunning && !gameOver) {
                requestAnimationFrame(gameLoop);
            }
        }

        function update() {
            if (!gameRunning) return;

            // Movimento
            if (keys.left && mario.x > 0) mario.x -= mario.speed;
            if (keys.right && mario.x < canvas.width - mario.width) mario.x += mario.speed;

            // Gravidade
            mario.velocityY += 0.4;
            mario.y += mario.velocityY;

            // Colisão com plataformas
            platforms.forEach((p) => {
                if (mario.velocityY > 0 &&
                    mario.y + mario.height >= p.y &&
                    mario.y + mario.height <= p.y + p.height + 10 &&
                    mario.x + mario.width > p.x &&
                    mario.x < p.x + p.width) {
                    mario.velocityY = -mario.jumpPower;
                    if (!p.isFloor) {
                        let points = 1;
                        if (p.color === '#FFD700') points = 5;
                        score += points * speedMultiplier;
                        document.getElementById('currentScore').textContent = score;
                    }
                }
            });

            // Colisão com inimigos (dano)
            enemies.forEach((e) => {
                if (mario.x < e.x + e.width && mario.x + mario.width > e.x &&
                    mario.y < e.y + e.height && mario.y + mario.height > e.y) {
                    if (shieldActive) {
                        shieldActive = false;
                        e.x = Math.random() * (canvas.width - 30);
                        e.y = 100;
                    } else {
                        lives--;
                        updateLivesDisplay();
                        respawnOnPlatform();
                        if (lives <= 0) endGame();
                    }
                }
            });

            // Colisão com power-ups
            powerups.forEach((p, idx) => {
                if (mario.x < p.x + 20 && mario.x + mario.width > p.x &&
                    mario.y < p.y + 20 && mario.y + mario.height > p.y) {
                    if (p.type === 'shield') {
                        lives++;
                        shieldActive = true;
                    } else if (p.type === 'coin') {
                        score += 5 * speedMultiplier;
                    } else if (p.type === 'speed') {
                        speedMultiplier = 2;
                        setTimeout(() => speedMultiplier = 1, 5000);
                    }
                    powerups.splice(idx, 1);
                    document.getElementById('currentScore').textContent = score;
                    updateLivesDisplay();
                }
            });

            // Movimento dos inimigos
            enemies.forEach((e) => {
                e.x += e.velocityX;
                if (e.x < 0 || e.x > canvas.width - 30) e.velocityX *= -1;
            });

            // Plataformas tremendo
            platforms.forEach((p) => {
                if (p.tremble) {
                    p.trembleAmount += 0.1;
                }
            });

            // Game Over ao cair para fora da tela
            if (mario.y > canvas.height) {
                lives--;
                updateLivesDisplay();
                respawnOnPlatform();
                if (lives <= 0) endGame();
            }

            // Câmera e geração
            if (mario.y < canvas.height / 3) {
                let diff = canvas.height / 3 - mario.y;
                distanceTraveled += diff;
                mario.y = canvas.height / 3;
                
                platforms.forEach(p => p.y += diff);
                enemies.forEach(e => e.y += diff);
                powerups.forEach(p => p.y += diff);
                
                updateLevel();

                // Gerar novas plataformas
                platforms.forEach((p) => {
                    if (p.isFloor) return;
                    if (p.y > canvas.height) {
                        p.y = -10;
                        p.x = Math.random() * (canvas.width - 60);
                        let rand = Math.random();
                        if (rand < 0.15) p.color = '#FFD700';
                        else if (rand < 0.25) p.color = '#8B008B';
                        else p.color = '#ff6b9d';
                        p.tremble = p.color === '#8B008B';
                    }
                });

                // Gerar novos inimigos
                enemies.forEach((e) => {
                    if (e.y > canvas.height) {
                        e.y = -30;
                        e.x = Math.random() * (canvas.width - 30);
                        e.velocityX = (Math.random() - 0.5) * getEnemySpeed();
                    }
                });

                // Gerar power-ups (bem mais raro agora)
                powerups.forEach((p, idx) => {
                    if (p.y > canvas.height) powerups.splice(idx, 1);
                });

                if (Math.random() < 0.08 && powerups.length < 2) {
                    let rand = Math.random();
                    let type;
                    if (rand < 0.4) type = 'coin';        // 40% moedas
                    else if (rand < 0.65) type = 'shield'; // 25% vidas
                    else type = 'speed';                    // 35% speed
                    
                    powerups.push({
                        x: Math.random() * (canvas.width - 20),
                        y: -20,
                        type: type
                    });
                }
            }
        }

        function draw() {
            // Fundo gradiente
            let gradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
            gradient.addColorStop(0, '#87ceeb');
            gradient.addColorStop(1, '#e0f6ff');
            ctx.fillStyle = gradient;
            ctx.fillRect(0, 0, canvas.width, canvas.height);

            // Nuvens
            ctx.fillStyle = 'rgba(255, 255, 255, 0.4)';
            ctx.beginPath();
            ctx.arc(80, 50, 30, 0, Math.PI * 2);
            ctx.arc(120, 40, 25, 0, Math.PI * 2);
            ctx.arc(300, 80, 35, 0, Math.PI * 2);
            ctx.fill();

            // Plataformas
            platforms.forEach((p) => {
                let offsetX = p.tremble ? Math.sin(p.trembleAmount) * 3 : 0;
                ctx.fillStyle = p.color;
                ctx.fillRect(p.x + offsetX, p.y, p.width, p.height);
                ctx.strokeStyle = p.isFloor ? '#654321' : '#ff1744';
                ctx.lineWidth = 2;
                ctx.strokeRect(p.x + offsetX, p.y, p.width, p.height);
            });

            // Inimigos
            enemies.forEach((e) => {
                ctx.fillStyle = e.type === 'spike' ? '#ff0000' : '#ff6b6b';
                ctx.beginPath();
                ctx.arc(e.x + 15, e.y + 15, 15, 0, Math.PI * 2);
                ctx.fill();
                
                if (e.type === 'spike') {
                    ctx.fillStyle = '#000';
                    ctx.beginPath();
                    ctx.moveTo(e.x + 15, e.y);
                    ctx.lineTo(e.x + 8, e.y + 15);
                    ctx.lineTo(e.x + 22, e.y + 15);
                    ctx.fill();
                } else {
                    ctx.fillStyle = '#fff';
                    ctx.beginPath();
                    ctx.arc(e.x + 10, e.y + 10, 5, 0, Math.PI * 2);
                    ctx.fill();
                    ctx.beginPath();
                    ctx.arc(e.x + 20, e.y + 10, 5, 0, Math.PI * 2);
                    ctx.fill();
                }
            });

            // Power-ups
            powerups.forEach((p) => {
                let icons = { shield: '⭐', coin: '🪙', speed: '🔥' };
                ctx.font = '20px Arial';
                ctx.fillText(icons[p.type], p.x, p.y + 15);
            });

            // Mario com escudo
            if (shieldActive) {
                ctx.strokeStyle = 'rgba(255, 215, 0, 0.5)';
                ctx.lineWidth = 3;
                ctx.beginPath();
                ctx.arc(mario.x + 15, mario.y + 20, 25, 0, Math.PI * 2);
                ctx.stroke();
            }

            ctx.fillStyle = '#ff3333';
            ctx.fillRect(mario.x, mario.y, mario.width, mario.height);
            ctx.fillStyle = '#ffd700';
            ctx.fillRect(mario.x + 5, mario.y + 5, mario.width - 10, 15);
            ctx.fillStyle = '#000';
            ctx.beginPath();
            ctx.arc(mario.x + 10, mario.y + 8, 3, 0, Math.PI * 2);
            ctx.fill();
            ctx.beginPath();
            ctx.arc(mario.x + 20, mario.y + 8, 3, 0, Math.PI * 2);
            ctx.fill();

            // Texto na tela
            ctx.fillStyle = '#000';
            ctx.font = 'bold 14px Arial';
            ctx.fillText('Score: ' + score, 10, 25);
            ctx.fillText('Nível: ' + level, 10, 45);
        }

        function endGame() {
            gameRunning = false;
            gameOver = true;
            document.getElementById('startBtn').disabled = false;
            document.getElementById('startBtn').textContent = '🔄 Jogar Novamente';
            gameStarted = false;

            if (score > 0) {
                fetch('mario.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'acao=salvar_score&score=' + score
                });
            }

            alert('Game Over!\nScore: ' + score + '\nNível: ' + level);
        }

        draw();
        document.getElementById('startBtn').focus();
    </script>
</body>
</html>
