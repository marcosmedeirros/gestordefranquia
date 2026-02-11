<?php
// flappy.php - O CLÁSSICO VICIANTE (FBA games EDITION 🐦)
// VERSÃO: SEM TRAVAS DE SEGURANÇA (Modo Desenvolvimento)
ini_set('display_errors', 1);
error_reporting(E_ALL);
// session_start já foi chamado em games/index.php
require '../core/conexao.php';

// 1. Segurança Básica (Apenas Login)
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];

// 2. Configuração de Banco de Dados e Skins
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS flappy_historico (id INT AUTO_INCREMENT PRIMARY KEY, id_usuario INT NOT NULL, pontuacao INT NOT NULL, data_jogo DATETIME DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS flappy_compras_skins (id INT AUTO_INCREMENT PRIMARY KEY, id_usuario INT NOT NULL, skin VARCHAR(50) NOT NULL, data_compra DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE(id_usuario, skin))");
    try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN flappy_skin_equipada VARCHAR(50) DEFAULT 'default'"); } catch (Exception $e) {}

    $stmtMe = $pdo->prepare("SELECT nome, pontos, flappy_skin_equipada FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);

    $stmtSkins = $pdo->prepare("SELECT skin FROM flappy_compras_skins WHERE id_usuario = :id");
    $stmtSkins->execute([':id' => $user_id]);
    $minhas_skins = $stmtSkins->fetchAll(PDO::FETCH_COLUMN);

    $stmtRecorde = $pdo->prepare("SELECT MAX(pontuacao) FROM flappy_historico WHERE id_usuario = :id");
    $stmtRecorde->execute([':id' => $user_id]);
    $recorde = $stmtRecorde->fetchColumn() ?: 0;

    $stmtRank = $pdo->query("SELECT u.nome, MAX(h.pontuacao) as recorde FROM flappy_historico h JOIN usuarios u ON h.id_usuario = u.id GROUP BY h.id_usuario ORDER BY recorde DESC LIMIT 5");
    $ranking_flappy = $stmtRank->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { die("Erro DB: " . $e->getMessage()); }

// CONFIGURAÇÃO DAS SKINS
$catalogo_skins = [
    'azul' => ['nome' => 'Azulão', 'cor' => '#29b6f6', 'preco' => 10, 'desc' => 'Clássico Azul'],
    'vermelho' => ['nome' => 'Red Bird', 'cor' => '#ef5350', 'preco' => 20, 'desc' => 'Rápido e Furioso'],
    'verde' => ['nome' => 'Verdinho', 'cor' => '#66bb6a', 'preco' => 20, 'desc' => 'Camuflado'],
    'fantasma' => ['nome' => 'Fantasma', 'cor' => '#ab47bc', 'preco' => 25, 'desc' => 'Assustador'],
    'robo' => ['nome' => 'Robô-X', 'cor' => '#bdbdbd', 'preco' => 30, 'desc' => 'Blindado']
];

// --- API AJAX (Sem Token CSRF) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');

    // A. COMPRAR SKIN
    if ($_POST['acao'] == 'comprar_skin') {
        $skin = $_POST['skin'];
        if (!isset($catalogo_skins[$skin])) die(json_encode(['erro' => 'Skin inválida']));
        $preco = $catalogo_skins[$skin]['preco'];

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $user_id]);
            if ($stmt->fetchColumn() < $preco) throw new Exception("Saldo insuficiente!");

            $stmtCheck = $pdo->prepare("SELECT id FROM flappy_compras_skins WHERE id_usuario = :uid AND skin = :skin");
            $stmtCheck->execute([':uid' => $user_id, ':skin' => $skin]);
            if ($stmtCheck->rowCount() > 0) throw new Exception("Você já tem essa skin!");

            $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :uid")->execute([':val' => $preco, ':uid' => $user_id]);
            $pdo->prepare("INSERT INTO flappy_compras_skins (id_usuario, skin) VALUES (:uid, :skin)")->execute([':uid' => $user_id, ':skin' => $skin]);
            $pdo->commit();
            echo json_encode(['sucesso' => true]);
        } catch (Exception $e) { $pdo->rollBack(); echo json_encode(['erro' => $e->getMessage()]); }
        exit;
    }

    // B. EQUIPAR SKIN
    if ($_POST['acao'] == 'equipar_skin') {
        $skin = $_POST['skin'];
        try {
            if ($skin !== 'default') {
                $stmtCheck = $pdo->prepare("SELECT id FROM flappy_compras_skins WHERE id_usuario = :uid AND skin = :skin");
                $stmtCheck->execute([':uid' => $user_id, ':skin' => $skin]);
                if ($stmtCheck->rowCount() == 0) throw new Exception("Skin não encontrada.");
            }
            $pdo->prepare("UPDATE usuarios SET flappy_skin_equipada = :skin WHERE id = :uid")->execute([':skin' => $skin, ':uid' => $user_id]);
            echo json_encode(['sucesso' => true]);
        } catch (Exception $e) { echo json_encode(['erro' => $e->getMessage()]); }
        exit;
    }

    // C. SALVAR PONTOS (suporta valores positivos e negativos)
    if ($_POST['acao'] == 'salvar_moedas') {
        $moedas = (int)$_POST['qtd'];
        if ($moedas != 0) {
            $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")->execute([':val' => $moedas, ':id' => $user_id]);
        }
        echo json_encode(['sucesso' => true]);
        exit;
    }

    // D. SALVAR SCORE
    if ($_POST['acao'] == 'salvar_score') {
        $score = (int)$_POST['score'];
        $pdo->prepare("INSERT INTO flappy_historico (id_usuario, pontuacao) VALUES (:uid, :score)")->execute([':uid' => $user_id, ':score' => $score]);
        echo json_encode(['sucesso' => true]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flappy - FBA games</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🐦</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; overflow: hidden; }
        .navbar-custom { background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%); border-bottom: 1px solid #333; padding: 15px; }
    .saldo-badge { background-color: #FC082B; color: #000; padding: 5px 15px; border-radius: 20px; font-weight: 800; }
        #game-wrapper { position: relative; width: 100%; height: 85vh; display: flex; justify-content: center; align-items: center; background: #222; }
        canvas { background: #111; border: 2px solid #444; border-radius: 10px; box-shadow: 0 0 30px rgba(0,0,0,0.5); max-width: 100%; max-height: 100%; }
        .overlay-screen { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(20, 20, 20, 0.95); padding: 30px; border-radius: 15px; text-align: center; border: 1px solid #555; backdrop-filter: blur(5px); z-index: 10; min-width: 320px; }
        .hud-score { position: absolute; top: 20px; left: 50%; transform: translateX(-50%); font-family: 'Courier New', monospace; font-size: 3rem; font-weight: bold; color: #fff; text-shadow: 2px 2px 0 #000; pointer-events: none; z-index: 5; }
        .floating-text { position: absolute; font-weight: bold; color: #ffd700; text-shadow: 0 0 5px #000; animation: floatUp 1s forwards; pointer-events: none; white-space: nowrap; }
        @keyframes floatUp { 0% { transform: translateY(0); opacity: 1; } 100% { transform: translateY(-50px); opacity: 0; } }
        .shop-grid { display: grid; grid-template-columns: 1fr; gap: 10px; max-height: 250px; overflow-y: auto; text-align: left; }
        .skin-item { background: #333; padding: 10px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; border: 1px solid #444; }
        .skin-preview { width: 24px; height: 24px; border-radius: 50%; display: inline-block; margin-right: 10px; border: 2px solid #fff; }
        .rank-list li { border-bottom: 1px solid rgba(255,255,255,0.1); padding: 5px 0; }
    </style>
</head>
<body>

<div class="navbar-custom d-flex justify-content-between align-items-center shadow-lg sticky-top">
    <div class="d-flex align-items-center gap-3">
        <span class="fs-5">Olá, <strong><?= htmlspecialchars($meu_perfil['nome']) ?></strong></span>
    </div>
    <div class="d-flex align-items-center gap-3">
        <a href="../index.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar</a>
        <span class="saldo-badge" id="saldoDisplay"><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?> pts</span>
    </div>
</div>

<div id="game-wrapper">
    <canvas id="flappyCanvas" width="400" height="600"></canvas>
    <div class="hud-score" id="scoreDisplay" style="display: none;">0</div>

    <div id="start-screen" class="overlay-screen">
        <h1 class="display-1 mb-0">🐦</h1>
        <h3 class="text-white mb-2">FLAPPY BIRD</h3>
        <p class="text-white-50 mb-3">Recorde: <strong class="text-warning"><?= $recorde ?></strong></p>
        <button class="btn btn-warning w-100 fw-bold rounded-pill mb-3" onclick="toggleShop()"><i class="bi bi-cart-fill"></i> LOJA DE SKINS</button>
        <?php if(!empty($ranking_flappy)): ?>
        <div class="text-start bg-dark p-3 rounded border border-secondary mb-3">
            <h6 class="text-warning border-bottom border-secondary pb-2 mb-2"><i class="bi bi-trophy-fill"></i> Top Voadores</h6>
            <ul class="list-unstyled small text-secondary mb-0 rank-list">
                <?php foreach($ranking_flappy as $idx => $r): ?>
                    <li class="d-flex justify-content-between">
                        <span>#<?= $idx+1 ?> <?= htmlspecialchars($r['nome']) ?></span>
                        <strong class="text-white"><?= number_format($r['recorde'], 0, ',', '.') ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        <button class="btn btn-success btn-lg w-100 fw-bold rounded-pill shadow" onclick="startGame()"><i class="bi bi-play-fill"></i> JOGAR</button>
    </div>

    <div id="shop-screen" class="overlay-screen" style="display: none;">
        <h3 class="text-warning mb-3"><i class="bi bi-shop"></i> Loja de Pássaros</h3>
        <div class="shop-grid mb-3">
            <div class="skin-item">
                <div><span class="skin-preview" style="background: #ffeb3b;"></span><strong class="text-white">Padrão</strong></div>
                <?php if($meu_perfil['flappy_skin_equipada'] == 'default'): ?>
                    <button class="btn btn-sm btn-secondary" disabled>Equipado</button>
                <?php else: ?>
                    <button class="btn btn-sm btn-primary" onclick="equipSkin('default')">Usar</button>
                <?php endif; ?>
            </div>
            <?php foreach($catalogo_skins as $key => $skin): 
                $tem = in_array($key, $minhas_skins);
                $equipado = ($meu_perfil['flappy_skin_equipada'] == $key);
            ?>
            <div class="skin-item">
                <div><span class="skin-preview" style="background: <?= $skin['cor'] ?>;"></span><div><strong class="d-block text-white" style="line-height: 1;"><?= $skin['nome'] ?></strong><small class="text-white-50" style="font-size: 0.7em;"><?= $skin['desc'] ?></small></div></div>
                <div>
                    <?php if($equipado): ?><button class="btn btn-sm btn-secondary" disabled>Equipado</button>
                    <?php elseif($tem): ?><button class="btn btn-sm btn-primary" onclick="equipSkin('<?= $key ?>')">Usar</button>
                    <?php else: ?><button class="btn btn-sm btn-success" onclick="buySkin('<?= $key ?>', <?= $skin['preco'] ?>)">$<?= $skin['preco'] ?></button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-outline-light w-100 rounded-pill" onclick="toggleShop()">Fechar Loja</button>
    </div>

    <div id="game-over-screen" class="overlay-screen" style="display: none;">
        <h2 class="text-danger mb-3">GAME OVER</h2>
        <div class="bg-dark p-3 rounded mb-3 border border-secondary">
            <div class="d-flex justify-content-between mb-2"><span class="text-white-50">Placar</span><strong class="text-white" id="finalScore">0</strong></div>
            <div class="d-flex justify-content-between"><span class="text-white-50">Melhor</span><strong class="text-warning" id="bestScore"><?= $recorde ?></strong></div>
        </div>
        <button id="reviveBtn" class="btn btn-warning w-100 fw-bold rounded-pill mb-2" onclick="revive()" style="display: none;"><i class="bi bi-heart-fill"></i> CONTINUAR (10 pts)</button>
        <button class="btn btn-primary w-100 fw-bold rounded-pill mb-2" onclick="startGame()"><i class="bi bi-arrow-clockwise"></i> TENTAR DE NOVO</button>
        <button class="btn btn-outline-light w-100 rounded-pill" onclick="location.href='../index.php'">Menu Principal</button>
    </div>
</div>

<script>
    const canvas = document.getElementById('flappyCanvas');
    const ctx = canvas.getContext('2d');
    const saldoDisplay = document.getElementById('saldoDisplay');
    
    // CONFIGURAÃ‡Ã•ES DE CENÃRIOS (NOVO - A cada 15)
    const scenarios = [
        { bg: '#1a1a1a', name: 'NOITE ESCURA' },     // 0-14
        { bg: '#4FC3F7', name: 'DIA CLARO' },        // 15-29
        { bg: '#FF9800', name: 'POR DO SOL' },       // 30-44
        { bg: '#4A148C', name: 'NEON CITY' },        // 45-59
        { bg: '#263238', name: 'CAVERNA PROFUNDA' }  // 60+
    ];

    let currentSkin = '<?= $meu_perfil['flappy_skin_equipada'] ?>';
    const skinConfig = {
        'default': { body: '#ffeb3b', wing: '#fdd835' },
        'azul':    { body: '#29b6f6', wing: '#0288d1' },
        'vermelho':{ body: '#ef5350', wing: '#c62828' },
        'verde':   { body: '#66bb6a', wing: '#2e7d32' },
        'fantasma':{ body: '#ab47bc', wing: '#7b1fa2' },
        'robo':    { body: '#bdbdbd', wing: '#757575' }
    };

    // Variáveis Jogo
    let frames = 0, score = 0, highScore = <?= $recorde ?>, currentState = 'START', coinsEarned = 0;
    let hasUsedRevive = false; // Controla se já usou o revive
    
    // Entidades
    const bird = {
        x: 50, y: 150, w: 34, h: 24, radius: 12, velocity: 0, gravity: 0.25, jump: 4.6, rotation: 0,
        draw: function() {
            ctx.save(); ctx.translate(this.x, this.y);
            this.rotation = Math.min(Math.PI/4, Math.max(-Math.PI/4, (this.velocity * 0.1))); 
            ctx.rotate(this.rotation);
            
            let colors = skinConfig[currentSkin] || skinConfig['default'];
            
            // Corpo
            ctx.fillStyle = colors.body; ctx.beginPath(); ctx.arc(0, 0, this.radius, 0, Math.PI*2); ctx.fill();
            // Olho
            ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(6, -6, 5, 0, Math.PI*2); ctx.fill();
            ctx.fillStyle = (currentSkin == 'robo') ? '#f00' : '#000'; // Olho vermelho pro robÃ´
            ctx.beginPath(); ctx.arc(8, -6, 2, 0, Math.PI*2); ctx.fill();
            // Bico
            ctx.fillStyle = '#ff9800'; ctx.beginPath(); ctx.moveTo(8, 2); ctx.lineTo(16, 6); ctx.lineTo(8, 10); ctx.fill();
            // Asa
            ctx.fillStyle = colors.wing; ctx.beginPath(); ctx.ellipse(-4, 4, 8, 5, -0.2, 0, Math.PI*2); ctx.fill();
            ctx.restore();
        },
        update: function() {
            this.velocity += this.gravity; this.y += this.velocity;
            if(this.y + this.radius >= canvas.height - fg.h) { this.y = canvas.height - fg.h - this.radius; gameOver(); }
            if(this.y - this.radius <= 0) { this.y = this.radius; this.velocity = 0; }
        },
        flap: function() { this.velocity = -this.jump; }
    };
    
    // Background Atualizado (15 pts)
    const bg = { 
        draw: function() { 
            // Calcula qual cenário usar (agora a cada 15 pontos)
            let idx = Math.floor(score / 15) % scenarios.length;
            ctx.fillStyle = scenarios[idx].bg; 
            ctx.fillRect(0, 0, canvas.width, canvas.height); 
        } 
    };

    const fg = {
        h: 100, dx: 0,
        draw: function() {
            ctx.fillStyle = '#2e7d32'; ctx.fillRect(0, canvas.height - this.h, canvas.width, this.h);
            ctx.fillStyle = '#4caf50'; ctx.fillRect(0, canvas.height - this.h, canvas.width, 10);
            ctx.fillStyle = '#1b5e20';
            for(let i=0; i<20; i++) {
                ctx.beginPath(); ctx.moveTo((i*30)-this.dx, canvas.height-this.h+10);
                ctx.lineTo((i*30)+15-this.dx, canvas.height); ctx.lineTo((i*30)-10-this.dx, canvas.height); ctx.fill();
            }
        },
        update: function() { this.dx = (this.dx + pipes.dx) % 30; }
    };
    
    const pipes = {
        items: [], w: 52, gap: 120, dx: 2,
        draw: function() {
            for(let i=0; i<this.items.length; i++) {
                let p = this.items[i];
                ctx.fillStyle = '#388e3c'; ctx.fillRect(p.x, 0, this.w, p.top);
                ctx.fillStyle = '#4caf50'; ctx.fillRect(p.x - 2, p.top - 20, this.w + 4, 20);
                ctx.fillStyle = '#388e3c'; ctx.fillRect(p.x, canvas.height - fg.h - p.bottom, this.w, p.bottom);
                ctx.fillStyle = '#4caf50'; ctx.fillRect(p.x - 2, canvas.height - fg.h - p.bottom, this.w + 4, 20);
            }
        },
        update: function() {
            this.dx = 2 + Math.floor(score / 10) * 0.5; // Dificuldade progressiva
            let spawnRate = Math.floor(240 / this.dx);
            
            if(frames % spawnRate == 0) {
                let availH = canvas.height - fg.h - this.gap - 40;
                let topH = Math.floor(Math.random() * (availH - 40)) + 20;
                let botH = canvas.height - fg.h - this.gap - topH;
                this.items.push({ x: canvas.width, top: topH, bottom: botH, passed: false });
            }
            
            for(let i=0; i<this.items.length; i++) {
                let p = this.items[i]; p.x -= this.dx;
                if(bird.x + bird.radius > p.x && bird.x - bird.radius < p.x + this.w) {
                    if(bird.y - bird.radius < p.top || bird.y + bird.radius > canvas.height - fg.h - p.bottom) gameOver();
                }
                if(p.x + this.w < bird.x && !p.passed) {
                    score++; p.passed = true;
                    
                    // LÃ“GICA DE CENÃRIO (Atualizado para 15)
                    if(score % 15 === 0) {
                        let idx = Math.floor(score / 15) % scenarios.length;
                        showFloatingText("NOVO CENÃRIO: " + scenarios[idx].name, canvas.width/2 - 100, 150);
                        // Flash na tela
                        ctx.fillStyle = '#FFF'; ctx.fillRect(0,0,canvas.width,canvas.height);
                    }

                    if(score % 10 === 0) {
                        const reward = 1 + (score / 10); // 10 túneis=2, 20=3, 30=4...
                        coinsEarned += reward;
                        showFloatingText(`+${reward} MOEDAS`, bird.x, bird.y - 30);
                    }
                }
                if(p.x + this.w <= 0) { this.items.shift(); i--; }
            }
        },
        reset: function() { this.items = []; this.dx = 2; }
    };

    // --- CONTROLES E LOJA ---
    function toggleShop() {
        let shop = document.getElementById('shop-screen');
        let start = document.getElementById('start-screen');
        if (shop.style.display === 'none') { shop.style.display = 'block'; start.style.display = 'none'; }
        else { shop.style.display = 'none'; start.style.display = 'block'; }
    }

    function buySkin(skin, price) {
        if(!confirm('Comprar skin por ' + price + ' moedas?')) return;
        const fd = new FormData(); 
        fd.append('acao', 'comprar_skin'); 
        fd.append('skin', skin);
        fetch('index.php?game=flappy', { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{
            if(d.sucesso) { alert('Comprado!'); location.reload(); }
            else alert(d.erro);
        });
    }

    function equipSkin(skin) {
        const fd = new FormData(); 
        fd.append('acao', 'equipar_skin'); 
        fd.append('skin', skin);
        fetch('index.php?game=flappy', { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{
            if(d.sucesso) { currentSkin = skin; alert('Equipado!'); location.reload(); }
            else alert(d.erro);
        });
    }

    document.addEventListener('keydown', function(e) { if(e.code === 'Space' || e.code === 'ArrowUp') action(); });
    canvas.addEventListener('touchstart', function(e) { e.preventDefault(); action(); }, {passive: false});
    canvas.addEventListener('click', action);

    function action() { if(currentState === 'GAME') bird.flap(); }

    function loop() {
        if(currentState === 'GAME') { bird.update(); fg.update(); pipes.update(); frames++; }
        bg.draw(); pipes.draw(); fg.draw(); bird.draw();
        if(currentState === 'GAME') { document.getElementById('scoreDisplay').innerText = score; requestAnimationFrame(loop); }
    }

    function startGame() {
        document.getElementById('start-screen').style.display = 'none';
        document.getElementById('game-over-screen').style.display = 'none';
        document.getElementById('scoreDisplay').style.display = 'block';
        bird.y = 150; bird.velocity = 0; pipes.reset(); score = 0; frames = 0; coinsEarned = 0;
        hasUsedRevive = false; // Reset do revive ao iniciar novo jogo
        currentState = 'GAME'; loop();
    }

    function gameOver() {
        currentState = 'OVER';
        if(score > highScore) highScore = score;
        document.getElementById('finalScore').innerText = score;
        document.getElementById('bestScore').innerText = highScore;
        document.getElementById('scoreDisplay').style.display = 'none';
        
        // Mostra botão de revive apenas na primeira morte e se tiver pontos suficientes
        let currentPoints = parseInt(saldoDisplay.innerText.replace(/\D/g,''));
        if(!hasUsedRevive && currentPoints >= 10) {
            document.getElementById('reviveBtn').style.display = 'block';
        } else {
            document.getElementById('reviveBtn').style.display = 'none';
        }
        
        setTimeout(() => document.getElementById('game-over-screen').style.display = 'block', 500);
        
        const fd = new FormData(); 
        fd.append('acao', 'salvar_score'); 
        fd.append('score', score);
        fetch('index.php?game=flappy', { method: 'POST', body: fd });
        
        if(coinsEarned > 0) {
            const fdc = new FormData(); 
            fdc.append('acao', 'salvar_moedas'); 
            fdc.append('qtd', coinsEarned);
            fetch('index.php?game=flappy', { method: 'POST', body: fdc }).then(r=>r.json()).then(d=>{
                if(d.sucesso) {
                    let cur = parseInt(saldoDisplay.innerText.replace(/\D/g,''));
                    saldoDisplay.innerText = (cur + coinsEarned).toLocaleString('pt-BR') + ' pts';
                }
            });
        }
    }

    function showFloatingText(t,x,y){
        let e=document.createElement('div'); e.className='floating-text'; e.innerText=t;
        let r=canvas.getBoundingClientRect(); e.style.left=(r.left+x)+'px'; e.style.top=(r.top+y)+'px';
        document.body.appendChild(e); setTimeout(()=>e.remove(),1000);
    }

    function revive() {
        // Cobra 10 pontos e retoma o jogo
        const fd = new FormData();
        fd.append('acao', 'salvar_moedas');
        fd.append('qtd', -10); // Desconta 10 pontos
        fetch('index.php?game=flappy', { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{
            if(d.sucesso) {
                let cur = parseInt(saldoDisplay.innerText.replace(/\D/g,''));
                saldoDisplay.innerText = (cur - 10).toLocaleString('pt-BR') + ' pts';
                
                hasUsedRevive = true; // Marca que já usou o revive
                document.getElementById('game-over-screen').style.display = 'none';
                document.getElementById('scoreDisplay').style.display = 'block';
                
                // Reposiciona o pássaro e limpa canos próximos
                bird.y = 150;
                bird.velocity = 0;
                pipes.items = pipes.items.filter(p => p.x > 200); // Remove canos muito próximos
                
                showFloatingText('REVIVEU! 💚', canvas.width/2 - 50, 200);
                currentState = 'GAME';
                loop();
            } else {
                alert('Erro ao processar revive');
            }
        });
    }

    bg.draw(); fg.draw(); bird.draw();
</script>
</body>
</html>
