<?php
require_once __DIR__ . '/../../backend/auth.php';
requireAuth();
$user = getUserSession();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <?php include __DIR__ . '/../../includes/head-pwa.php'; ?>
    <title>O Lance Livre Infinito</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css">
    <style>
        .arcade-hero {
            background: radial-gradient(circle at 15% 20%, rgba(252, 0, 37, 0.18), transparent 40%),
                        radial-gradient(circle at 80% 10%, rgba(255, 255, 255, 0.06), transparent 35%),
                        linear-gradient(145deg, rgba(20, 20, 20, 0.95), rgba(12, 12, 12, 0.92));
            border: 1px solid var(--fba-border);
            border-radius: 18px;
            box-shadow: var(--shadow-1);
            padding: 24px;
            position: relative;
            overflow: hidden;
        }
        .arcade-hero::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: radial-gradient(circle at 75% 65%, rgba(252, 0, 37, 0.12), transparent 45%);
            opacity: 0.8;
        }
        .game-arena {
            background: linear-gradient(180deg, rgba(17, 17, 17, 0.92), rgba(10, 10, 10, 0.96)),
                        radial-gradient(circle at 50% 15%, rgba(255, 255, 255, 0.05), transparent 55%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            min-height: 360px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-1);
        }
        .court-grid {
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(255, 255, 255, 0.04) 1px, transparent 1px),
                        linear-gradient(0deg, rgba(255, 255, 255, 0.04) 1px, transparent 1px);
            background-size: 60px 60px;
            opacity: 0.5;
            pointer-events: none;
        }
        .hoop {
            position: absolute;
            top: 46px;
            right: 48px;
            width: 140px;
            height: 90px;
        }
        .backboard {
            position: absolute;
            top: 0;
            right: 22px;
            width: 96px;
            height: 70px;
            border: 2px solid rgba(255, 255, 255, 0.35);
            border-radius: 8px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.09), rgba(255, 255, 255, 0.02));
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.35);
        }
        .rim {
            position: absolute;
            bottom: 6px;
            right: 0;
            width: 140px;
            height: 14px;
            border-radius: 10px;
            background: linear-gradient(90deg, #ff7043, #ff512f);
            box-shadow: 0 8px 18px rgba(255, 81, 47, 0.35);
        }
        .net {
            position: absolute;
            bottom: -36px;
            right: 32px;
            width: 78px;
            height: 58px;
            background: repeating-linear-gradient(135deg, rgba(255, 255, 255, 0.8) 0 6px, transparent 6px 12px),
                        repeating-linear-gradient(45deg, rgba(255, 255, 255, 0.8) 0 6px, transparent 6px 12px);
            background-size: 12px 12px;
            transform: perspective(200px) rotateX(40deg);
            filter: drop-shadow(0 6px 10px rgba(0,0,0,0.35));
            opacity: 0.9;
        }
        .player-figure {
            position: absolute;
            bottom: 0;
            left: 24px;
            width: clamp(120px, 18vw, 220px);
            filter: drop-shadow(0 18px 38px rgba(0,0,0,0.5));
            pointer-events: none;
            opacity: 0.94;
        }
        .ball {
            position: absolute;
            bottom: 28px;
            left: 50%;
            width: 38px;
            height: 38px;
            margin-left: -19px;
            border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, #ffdb9d, #ff8b38 55%, #f05a24 100%);
            box-shadow: 0 10px 22px rgba(0,0,0,0.35);
            z-index: 4;
        }
        .ball.shoot-success { animation: shotSuccess 0.72s ease-out forwards; }
        .ball.shoot-miss { animation: shotMiss 0.48s ease-in-out forwards; }
        @keyframes shotSuccess {
            0% { transform: translate(-50%, 0) scale(1); }
            55% { transform: translate(110px, -220px) scale(0.94); }
            100% { transform: translate(110px, -190px) scale(0.9); opacity: 0.25; }
        }
        @keyframes shotMiss {
            0% { transform: translate(-50%, 0) scale(1); }
            40% { transform: translate(40px, -120px) scale(0.92); }
            65% { transform: translate(-24px, -40px) rotate(-8deg); }
            100% { transform: translate(-50%, 0) scale(1); }
        }
        .floor-gradient {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 120px;
            background: radial-gradient(ellipse at 50% 100%, rgba(255, 255, 255, 0.1), transparent 65%),
                        linear-gradient(180deg, rgba(0, 0, 0, 0), rgba(0, 0, 0, 0.5));
            pointer-events: none;
        }
        .force-bar {
            position: relative;
            background: linear-gradient(90deg, rgba(252,0,37,0.18), rgba(255,255,255,0.08));
            border: 1px solid rgba(255,255,255,0.16);
            height: 26px;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: inset 0 0 18px rgba(0,0,0,0.45), 0 6px 14px rgba(0,0,0,0.35);
        }
        .force-bar .sweet-spot {
            position: absolute;
            top: 0;
            height: 100%;
            background: linear-gradient(90deg, rgba(16, 185, 129, 0.22), rgba(52, 211, 153, 0.32));
            border-left: 2px solid rgba(52, 211, 153, 0.8);
            border-right: 2px solid rgba(52, 211, 153, 0.8);
            box-shadow: inset 0 0 18px rgba(52, 211, 153, 0.55);
            border-radius: 12px;
        }
        .force-bar .marker {
            position: absolute;
            top: -6px;
            width: 6px;
            height: 38px;
            background: linear-gradient(180deg, #ff9f43, #ff6b08);
            border-radius: 8px;
            box-shadow: 0 8px 18px rgba(255, 107, 8, 0.4);
        }
        .force-bar.shake { animation: barShake 0.45s ease; }
        @keyframes barShake {
            0% { transform: translateX(0); }
            25% { transform: translateX(-6px); }
            50% { transform: translateX(6px); }
            75% { transform: translateX(-4px); }
            100% { transform: translateX(0); }
        }
        .game-stats {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.02));
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 14px;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.08);
            font-weight: 600;
        }
        .lives i { font-size: 1.1rem; }
        .cta-button {
            background: linear-gradient(135deg, #fc0025, #ff7043);
            border: none;
            padding: 12px 16px;
            border-radius: 14px;
            color: #fff;
            font-weight: 700;
            box-shadow: var(--shadow-brand);
        }
        .cta-button:hover { filter: brightness(1.05); transform: translateY(-1px); }
        .mini-tip {
            font-size: 0.9rem;
            color: var(--fba-text-muted);
        }
        .status-badge {
            padding: 6px 10px;
            border-radius: 10px;
            font-weight: 700;
            background: rgba(16, 185, 129, 0.16);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.45);
        }
        .status-badge.negative {
            background: rgba(255, 107, 8, 0.14);
            color: #ff9f43;
            border-color: rgba(255, 107, 8, 0.4);
        }
    </style>
</head>
<body>
    <button class="sidebar-toggle" id="sidebarToggle">
        <i class="bi bi-list fs-4"></i>
    </button>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

    <main class="dashboard-content">
        <div class="container-fluid">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4 arcade-hero">
                <div class="position-relative" style="z-index: 2;">
                    <div class="pill mb-2"><i class="bi bi-controller"></i><span>Arcade FBA</span></div>
                    <h1 class="mb-2">O Lance Livre Infinito</h1>
                    <p class="mb-3 text-muted">Clique ou aperte espaço quando o marcador cruzar a zona verde. Cada acerto aumenta a velocidade, cada erro custa uma vida.</p>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <span class="status-badge" id="statusBadge">Pronto para arremessar</span>
                        <span class="mini-tip"><i class="bi bi-lightning-fill text-warning"></i> +1 ponto por cesta · 2 erros e é game over</span>
                    </div>
                </div>
                <div class="text-end" style="z-index: 2;">
                    <img src="/games/lebron.png" alt="Avatar do jogador" class="img-fluid" style="max-height: 220px; filter: drop-shadow(0 20px 38px rgba(0,0,0,0.45));">
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card bg-dark-panel p-4 position-relative overflow-hidden">
                        <div class="game-arena mb-3">
                            <div class="court-grid"></div>
                            <div class="hoop">
                                <div class="backboard"></div>
                                <div class="rim"></div>
                                <div class="net"></div>
                            </div>
                            <img src="/games/lebron.png" alt="Jogador preparando o lance" class="player-figure">
                            <div class="ball" id="ball"></div>
                            <div class="floor-gradient"></div>
                        </div>
                        <div class="force-bar" id="forceBar">
                            <div class="sweet-spot" id="sweetSpot"></div>
                            <div class="marker" id="marker"></div>
                        </div>
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
                            <div class="fw-semibold" id="feedback">Clique ou pressione espaço no verde</div>
                            <div class="mini-tip"><i class="bi bi-mouse"></i> Botão esquerdo ou <kbd>Espaço</kbd></div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card bg-dark-panel h-100 p-4">
                        <div class="game-stats mb-3">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="text-muted">Pontuação</span>
                                <span class="fs-4 fw-bold" id="score">0</span>
                            </div>
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="text-muted">Recorde</span>
                                <span class="fs-5 fw-semibold" id="bestScore">0</span>
                            </div>
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="text-muted">Vidas</span>
                                <span class="lives" id="lives"></span>
                            </div>
                            <div class="d-flex align-items-center justify-content-between">
                                <span class="text-muted">Velocidade</span>
                                <span class="pill" id="speedLabel"><i class="bi bi-speedometer2"></i><span>1.0x</span></span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <h6 class="mb-2">Como jogar</h6>
                            <ul class="text-muted small ps-3 mb-3">
                                <li>A barra oscila. Mire no centro verde.</li>
                                <li>Clique ou aperte espaço para travar o marcador.</li>
                                <li>Acertos valem +1 ponto e deixam o jogo mais rápido.</li>
                                <li>Errou? Você perde uma vida. Erre duas vezes e é fim de jogo.</li>
                            </ul>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="cta-button w-100" id="shootButton">Arremessar agora</button>
                            <button class="btn btn-outline-light w-50" id="resetButton"><i class="bi bi-arrow-repeat me-1"></i>Reiniciar</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (() => {
            const marker = document.getElementById('marker');
            const sweetSpotEl = document.getElementById('sweetSpot');
            const feedback = document.getElementById('feedback');
            const statusBadge = document.getElementById('statusBadge');
            const ball = document.getElementById('ball');
            const scoreEl = document.getElementById('score');
            const bestEl = document.getElementById('bestScore');
            const livesEl = document.getElementById('lives');
            const speedLabel = document.getElementById('speedLabel');
            const shootButton = document.getElementById('shootButton');
            const resetButton = document.getElementById('resetButton');
            const forceBar = document.getElementById('forceBar');

            let progress = 0.5;
            let direction = 1;
            let lastTime = null;
            let score = 0;
            let best = 0;
            let lives = 2;
            let isGameOver = false;

            const baseSpeed = 0.65; // unidades de barra por segundo
            const minZoneWidth = 0.14;
            const zoneDecay = 0.008; // diminui a zona a cada ponto para leve dificuldade

            const updateLives = () => {
                livesEl.innerHTML = '';
                for (let i = 0; i < 2; i += 1) {
                    const icon = document.createElement('i');
                    const alive = i < lives;
                    icon.className = alive ? 'bi bi-heart-fill text-danger me-1' : 'bi bi-heart text-secondary me-1';
                    livesEl.appendChild(icon);
                }
            };

            const updateSpeedLabel = () => {
                const speed = (baseSpeed + score * 0.12).toFixed(2);
                speedLabel.querySelector('span').textContent = `${speed}x`;
            };

            const updateSweetSpot = () => {
                const width = Math.max(minZoneWidth - score * zoneDecay, 0.06);
                const start = 0.5 - width / 2;
                sweetSpotEl.style.left = `${start * 100}%`;
                sweetSpotEl.style.width = `${width * 100}%`;
            };

            const setFeedback = (text, positive = true) => {
                feedback.textContent = text;
                statusBadge.textContent = text;
                statusBadge.classList.toggle('negative', !positive);
            };

            const resetBallAnimation = () => {
                ball.classList.remove('shoot-success', 'shoot-miss');
                void ball.offsetWidth;
            };

            const animate = (timestamp) => {
                if (!lastTime) {
                    lastTime = timestamp;
                    requestAnimationFrame(animate);
                    return;
                }

                const delta = (timestamp - lastTime) / 1000;
                lastTime = timestamp;

                const speed = baseSpeed + score * 0.12;
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
                if (isGameOver) return;

                const width = Math.max(minZoneWidth - score * zoneDecay, 0.06);
                const start = 0.5 - width / 2;
                const end = 0.5 + width / 2;

                resetBallAnimation();

                if (progress >= start && progress <= end) {
                    score += 1;
                    best = Math.max(best, score);
                    scoreEl.textContent = score;
                    bestEl.textContent = best;
                    setFeedback('Cesta! +1 ponto', true);
                    ball.classList.add('shoot-success');
                } else {
                    lives -= 1;
                    updateLives();
                    setFeedback('Errou! Perdeu uma vida', false);
                    ball.classList.add('shoot-miss');
                    forceBar.classList.add('shake');
                    setTimeout(() => forceBar.classList.remove('shake'), 420);

                    if (lives <= 0) {
                        isGameOver = true;
                        setFeedback('Game over. Clique em Reiniciar', false);
                        statusBadge.textContent = 'Game over';
                        statusBadge.classList.add('negative');
                        return;
                    }
                }

                updateSpeedLabel();
                updateSweetSpot();
            };

            const resetGame = () => {
                score = 0;
                lives = 2;
                progress = 0.5;
                direction = 1;
                isGameOver = false;
                scoreEl.textContent = '0';
                setFeedback('Pronto para arremessar', true);
                updateLives();
                updateSpeedLabel();
                updateSweetSpot();
                resetBallAnimation();
            };

            shootButton.addEventListener('click', shoot);
            resetButton.addEventListener('click', resetGame);
            document.addEventListener('keydown', (event) => {
                if (event.code === 'Space') {
                    event.preventDefault();
                    shoot();
                }
            });
            forceBar.addEventListener('click', shoot);

            updateLives();
            updateSweetSpot();
            updateSpeedLabel();
            requestAnimationFrame(animate);
        })();

        (() => {
            const sidebar = document.querySelector('.dashboard-sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const toggle = document.getElementById('sidebarToggle');
            const closeSidebar = () => {
                sidebar?.classList.remove('active');
                overlay?.classList.remove('active');
            };

            toggle?.addEventListener('click', () => {
                sidebar?.classList.toggle('active');
                overlay?.classList.toggle('active');
            });
            overlay?.addEventListener('click', closeSidebar);
        })();
    </script>
</body>
</html>
