<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$usuario = ['nome' => 'Coach', 'pontos' => 0];
try {
    $stmt = $pdo->prepare('SELECT nome, pontos FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $usuario['nome'] = $row['nome'];
        $usuario['pontos'] = (int)$row['pontos'];
    }
} catch (PDOException $e) {
    // Silencia falha de leitura, segue com defaults
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>O Lance Livre Infinito - FBA games</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --bg: #0f0f12;
            --panel: #16171c;
            --panel-2: #1d1f27;
            --border: #252734;
            --accent: #fc0025;
            --accent-2: #ff7043;
            --text: #e9eaee;
            --muted: #9aa0b5;
        }

        body {
            background: radial-gradient(circle at 20% 20%, rgba(252,0,37,0.08), transparent 40%),
                        radial-gradient(circle at 80% 0%, rgba(255,255,255,0.05), transparent 45%),
                        #0b0c0f;
            color: var(--text);
            min-height: 100vh;
            font-family: 'Segoe UI', Arial, sans-serif;
        }

        .navbar-custom {
            background: linear-gradient(180deg, #181921 0%, #0f1015 100%);
            border-bottom: 1px solid var(--border);
            padding: 14px 18px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.45);
        }

        .brand-name {
            font-weight: 900;
            letter-spacing: 0.4px;
            color: var(--text);
            text-decoration: none;
        }

        .saldo-badge {
            background: var(--accent);
            color: #fff;
            padding: 8px 14px;
            border-radius: 999px;
            font-weight: 800;
            box-shadow: 0 6px 18px rgba(252,0,37,0.3);
        }

        .container-main { max-width: 1180px; padding: 26px 18px 60px; margin: 0 auto; }

        .hero {
            background: linear-gradient(135deg, rgba(22,23,28,0.92), rgba(18,19,26,0.94));
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 22px;
            box-shadow: 0 14px 34px rgba(0,0,0,0.45);
            position: relative;
            overflow: hidden;
        }

        .hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 70% 20%, rgba(252,0,37,0.14), transparent 45%);
            pointer-events: none;
        }

        .game-panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 18px;
            height: 100%;
            box-shadow: 0 12px 24px rgba(0,0,0,0.35);
        }

        .court {
            position: relative;
            background: radial-gradient(circle at 50% 15%, rgba(255,255,255,0.05), transparent 55%),
                        linear-gradient(180deg, #11121a 0%, #0c0d14 100%);
            border: 1px solid var(--border);
            border-radius: 16px;
            min-height: 320px;
            overflow: hidden;
        }

        .court-grid {
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(255,255,255,0.04) 1px, transparent 1px),
                        linear-gradient(0deg, rgba(255,255,255,0.04) 1px, transparent 1px);
            background-size: 60px 60px;
            opacity: 0.5;
            pointer-events: none;
        }

        .hoop { position: absolute; top: 38px; right: 14px; width: 92px; height: 70px; }
        .backboard {
            position: absolute; top: 0; right: 10px; width: 72px; height: 54px;
            border: 2px solid rgba(255,255,255,0.35);
            border-radius: 8px;
            background: linear-gradient(180deg, rgba(255,255,255,0.08), rgba(255,255,255,0.02));
            box-shadow: 0 12px 26px rgba(0,0,0,0.35);
        }
        .rim {
            position: absolute; bottom: 6px; right: 0; width: 92px; height: 10px;
            border-radius: 10px;
            background: linear-gradient(90deg, #ff8748, #ff512f);
            box-shadow: 0 10px 20px rgba(255,81,47,0.35);
        }
        .net {
            position: absolute; bottom: -28px; right: 18px; width: 58px; height: 44px;
            background: repeating-linear-gradient(135deg, rgba(255,255,255,0.82) 0 6px, transparent 6px 12px),
                        repeating-linear-gradient(45deg, rgba(255,255,255,0.82) 0 6px, transparent 6px 12px);
            background-size: 12px 12px;
            transform: perspective(200px) rotateX(40deg);
            opacity: 0.9;
            filter: drop-shadow(0 6px 8px rgba(0,0,0,0.35));
        }

        .player-img { position: absolute; bottom: 0; left: 24px; width: clamp(120px, 18vw, 210px); filter: drop-shadow(0 16px 28px rgba(0,0,0,0.5)); }

        .ball {
            position: absolute; bottom: 28px; left: 50%; width: 36px; height: 36px;
            margin-left: -18px; border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, #ffdb9d, #ff8b38 55%, #f05a24 100%);
            box-shadow: 0 10px 22px rgba(0,0,0,0.35);
            z-index: 2;
        }
    .ball.shoot-success { animation: shotSuccess 0.95s ease-in-out forwards; }
        .ball.shoot-miss { animation: shotMiss 0.45s ease-in-out forwards; }
        @keyframes shotSuccess {
            0% { transform: translate(-50%, 0) scale(1); opacity: 1; }
            55% { transform: translate(170px, -240px) scale(0.94); }
            75% { transform: translate(170px, -205px) scale(0.9); }
            100% { transform: translate(170px, -130px) scale(0.82); opacity: 0.15; }
        }
        @keyframes shotMiss {
            0% { transform: translate(-50%, 0) scale(1); }
            40% { transform: translate(34px, -110px) scale(0.92); }
            65% { transform: translate(-22px, -38px) rotate(-8deg); }
            100% { transform: translate(-50%, 0) scale(1); }
        }

        .meter {
            position: relative;
            background: linear-gradient(90deg, rgba(252,0,37,0.16), rgba(255,255,255,0.08));
            border: 1px solid rgba(255,255,255,0.12);
            height: 24px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: inset 0 0 18px rgba(0,0,0,0.45), 0 6px 14px rgba(0,0,0,0.28);
        }
        .meter .sweet { position: absolute; top: 0; height: 100%; background: linear-gradient(90deg, rgba(20,170,115,0.24), rgba(48,211,150,0.34)); border-left: 2px solid rgba(48,211,150,0.8); border-right: 2px solid rgba(48,211,150,0.8); box-shadow: inset 0 0 14px rgba(48,211,150,0.55); }
        .meter .marker { position: absolute; top: -6px; width: 6px; height: 36px; background: linear-gradient(180deg, #ffb347, #ff6a00); border-radius: 8px; box-shadow: 0 8px 18px rgba(255,106,0,0.4); }
        .meter.shake { animation: meterShake 0.4s ease; }
        @keyframes meterShake { 0% { transform: translateX(0); } 25% { transform: translateX(-6px);} 50% { transform: translateX(6px);} 75% { transform: translateX(-4px);} 100% { transform: translateX(0);} }

        .stat-card { background: var(--panel-2); border: 1px solid var(--border); border-radius: 14px; padding: 14px; }
        .stat-label { color: var(--muted); font-weight: 600; font-size: 0.9rem; }
        .stat-value { font-size: 1.6rem; font-weight: 800; }

        .btn-accent { background: linear-gradient(135deg, var(--accent), var(--accent-2)); border: none; color: #fff; font-weight: 700; }
        .btn-accent:hover { filter: brightness(1.06); }
        .btn-ghost { border: 1px solid var(--border); color: var(--text); }

        .overlay { position: absolute; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); border-radius: 16px; z-index: 5; text-align: center; }
        .overlay.active { display: flex; }
        .overlay-card { background: #13141b; padding: 26px; border-radius: 16px; border: 1px solid var(--border); box-shadow: 0 12px 30px rgba(0,0,0,0.45); min-width: 280px; }

        .tag { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 12px; background: rgba(255,255,255,0.06); border: 1px solid var(--border); font-weight: 700; color: #fff; }
    </style>
</head>
<body>

<div class="navbar-custom d-flex justify-content-between align-items-center sticky-top">
    <div class="d-flex align-items-center gap-3">
        <a class="brand-name" href="../index.php">🎮 FBA games</a>
        <span class="text-secondary small">🏀 O Lance Livre Infinito</span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="text-secondary d-none d-md-inline">Olá, <strong class="text-white"><?= htmlspecialchars($usuario['nome']) ?></strong></span>
        <span class="saldo-badge" id="saldoDisplay"><i class="bi bi-coin me-1"></i><?= number_format($usuario['pontos'], 0, ',', '.') ?> pts</span>
        <a href="../index.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar</a>
    </div>
</div>

<div class="container-main">
    <div class="hero mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 position-relative" style="z-index:1;">
            <div>
                <div class="tag mb-2"><i class="bi bi-lightning-charge"></i><span>Timing puro</span></div>
                <h2 class="mb-1">O Lance Livre Infinito</h2>
                <p class="mb-0 text-secondary">Acerte o marcador no verde. Cada cesta aumenta a velocidade. Duas vidas apenas.</p>
            </div>
            <img src="/games/lebron.png" alt="Jogador" class="img-fluid" style="max-height: 160px; filter: drop-shadow(0 16px 28px rgba(0,0,0,0.4));">
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="game-panel position-relative">
                <div class="court mb-3">
                    <div class="court-grid"></div>
                    <div class="hoop">
                        <div class="backboard"></div>
                        <div class="rim"></div>
                        <div class="net"></div>
                    </div>
                    <img src="/games/lebron.png" alt="Jogador" class="player-img">
                    <div class="ball" id="ball"></div>
                    <div class="overlay" id="overlay">
                        <div class="overlay-card">
                            <h4 class="mb-2" id="overlayTitle">Pronto?</h4>
                            <p class="text-secondary mb-3" id="overlayText">Clique no verde para marcar.</p>
                            <button class="btn btn-accent w-100" id="overlayButton">Começar</button>
                        </div>
                    </div>
                </div>

                <div class="meter" id="meter">
                    <div class="sweet" id="sweet"></div>
                    <div class="marker" id="marker"></div>
                </div>
                <div class="d-flex align-items-center justify-content-between mt-2 flex-wrap gap-2">
                    <div class="text-secondary" id="feedback">Clique ou use Espaço quando o marcador entrar na faixa verde.</div>
                    <small class="text-secondary">Controles: clique / Espaço</small>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="game-panel h-100">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="stat-card text-center">
                            <div class="stat-label">Pontuação</div>
                            <div class="stat-value" id="score">0</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card text-center">
                            <div class="stat-label">Recorde</div>
                            <div class="stat-value" id="best">0</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card text-center">
                            <div class="stat-label">Vidas</div>
                            <div class="stat-value" id="lives"></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="stat-card text-center">
                            <div class="stat-label">Velocidade</div>
                            <div class="stat-value" id="speed">1.00x</div>
                        </div>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-accent w-100" id="shootBtn"><i class="bi bi-basket2-fill me-1"></i>Arremessar</button>
                    <button class="btn btn-ghost w-50" id="resetBtn"><i class="bi bi-arrow-repeat me-1"></i>Reset</button>
                </div>
                <div class="mt-3 text-secondary small">
                    <ul class="mb-0 ps-3">
                        <li>Zona verde encolhe aos poucos.</li>
                        <li>Cada acerto acelera a barra.</li>
                        <li>Duas vidas: errou duas vezes, fim de jogo.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const marker = document.getElementById('marker');
    const sweetEl = document.getElementById('sweet');
    const meter = document.getElementById('meter');
    const ball = document.getElementById('ball');
    const feedback = document.getElementById('feedback');
    const overlay = document.getElementById('overlay');
    const overlayBtn = document.getElementById('overlayButton');
    const overlayTitle = document.getElementById('overlayTitle');
    const overlayText = document.getElementById('overlayText');
    const scoreEl = document.getElementById('score');
    const bestEl = document.getElementById('best');
    const livesEl = document.getElementById('lives');
    const speedEl = document.getElementById('speed');
    const shootBtn = document.getElementById('shootBtn');
    const resetBtn = document.getElementById('resetBtn');

    let progress = 0.5;
    let direction = 1;
    let lastTime = null;
    let score = 0;
    let best = 0;
    let lives = 2;
    let isRunning = false;
    let isGameOver = false;

    const baseSpeed = 0.45;
    const speedStep = 0.09;
    const minZone = 0.06;
    const decay = 0.012;
    const maxZone = 0.2;

    const updateLives = () => {
        livesEl.innerHTML = '';
        for (let i = 0; i < 2; i += 1) {
            const icon = document.createElement('i');
            icon.className = i < lives ? 'bi bi-heart-fill text-danger' : 'bi bi-heart text-secondary';
            livesEl.appendChild(icon);
            if (i === 0) {
                livesEl.appendChild(document.createTextNode(' '));
            }
        }
    };

    const updateSpeed = () => {
        const speed = baseSpeed + score * speedStep;
        speedEl.textContent = `${speed.toFixed(2)}x`;
    };

    const updateSweet = (randomize = false) => {
        const width = Math.max(minZone, maxZone - score * decay);
        let start = 0.5 - width / 2;
        if (randomize) {
            const margin = 0.04;
            const maxStart = 1 - width - margin;
            const minStart = margin;
            start = Math.random() * (maxStart - minStart) + minStart;
        }
        sweetEl.style.left = `${start * 100}%`;
        sweetEl.style.width = `${width * 100}%`;
    };

    const resetBallAnim = () => {
        ball.classList.remove('shoot-success', 'shoot-miss');
        void ball.offsetWidth;
    };

    const setFeedback = (text, positive = true) => {
        feedback.textContent = text;
        if (!positive) {
            meter.classList.add('shake');
            setTimeout(() => meter.classList.remove('shake'), 360);
        }
    };

    const animate = (timestamp) => {
        if (!isRunning) return;

        if (!lastTime) {
            lastTime = timestamp;
        }
        const delta = (timestamp - lastTime) / 1000;
        lastTime = timestamp;

        const speed = baseSpeed + score * speedStep;
        progress += direction * speed * delta;

        if (progress >= 1) {
            progress = 1;
            direction = -1;
        } else if (progress <= 0) {
            progress = 0;
            direction = 1;
        }

        marker.style.left = `${progress * 100}%`;
        requestAnimationFrame(animate);
    };

    const shoot = () => {
        if (!isRunning || isGameOver) return;

        const width = parseFloat(sweetEl.style.width) / 100 || 0.16;
        const start = parseFloat(sweetEl.style.left) / 100 || 0.42;
        const end = start + width;

        resetBallAnim();

        if (progress >= start && progress <= end) {
            score += 1;
            best = Math.max(best, score);
            scoreEl.textContent = score;
            bestEl.textContent = best;
            setFeedback('Cesta! +1 ponto', true);
            ball.classList.add('shoot-success');
            updateSweet(true);
        } else {
            lives -= 1;
            updateLives();
            setFeedback('Errou! -1 vida', false);
            ball.classList.add('shoot-miss');

            if (lives <= 0) {
                isGameOver = true;
                isRunning = false;
                overlay.classList.add('active');
                overlayTitle.textContent = 'Game over';
                overlayText.textContent = 'Clique em Reset para tentar de novo.';
                overlayBtn.textContent = 'Resetar';
                return;
            }
        }

        updateSpeed();
        if (!isGameOver) {
            updateSweet(true);
        }
    };

    const resetGame = () => {
        score = 0;
        lives = 2;
        progress = 0.5;
        direction = 1;
        isRunning = true;
        isGameOver = false;
        lastTime = null;
        scoreEl.textContent = '0';
        setFeedback('Clique ou Espaço no verde', true);
        updateLives();
        updateSpeed();
        updateSweet(true);
        resetBallAnim();
        overlay.classList.remove('active');
        requestAnimationFrame(animate);
    };

    overlayBtn.addEventListener('click', () => {
        if (isGameOver) {
            resetGame();
            return;
        }
        overlay.classList.remove('active');
        isRunning = true;
        lastTime = null;
        requestAnimationFrame(animate);
    });

    shootBtn.addEventListener('click', shoot);
    resetBtn.addEventListener('click', resetGame);
    meter.addEventListener('click', shoot);

    document.addEventListener('keydown', (ev) => {
        if (ev.code === 'Space') {
            ev.preventDefault();
            shoot();
        }
        if (ev.code === 'KeyR') {
            resetGame();
        }
    });

    updateLives();
    updateSpeed();
    updateSweet();
    overlay.classList.add('active');
})();
</script>
</body>
</html>
