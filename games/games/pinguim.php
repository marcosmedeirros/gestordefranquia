<?php
// pinguim.php - CORRIDA DO PINGUIM RADICAL (DARK MODE 🐧🛹)
// VERSÃO: SEM TRAVAS DE SEGURANÇA (Modo Desenvolvimento)
ini_set('display_errors', 1);
error_reporting(E_ALL);
// session_start já foi chamado em games/index.php
require '../core/conexao.php';

// 1. Segurança Básica
if (!isset($_SESSION['user_id'])) { header("Location: auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];

// --- AUTOMATIZAÃ‡ÃƒO DO BANCO DE DADOS PARA SKINS ---
try {
    // Tabela de compras
    $pdo->exec("CREATE TABLE IF NOT EXISTS compras_skins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        skin VARCHAR(50) NOT NULL,
        data_compra DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(id_usuario, skin)
    )");
    
    // Coluna de skin equipada
    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN skin_equipada VARCHAR(50) DEFAULT 'default'");
    } catch (Exception $e) {}

} catch (PDOException $e) {
    die("Erro DB Skins: " . $e->getMessage());
}

// 2. Dados do Usuário e Skins
try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin, skin_equipada FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
    
    $stmtSkins = $pdo->prepare("SELECT skin FROM compras_skins WHERE id_usuario = :id");
    $stmtSkins->execute([':id' => $user_id]);
    $minhas_skins = $stmtSkins->fetchAll(PDO::FETCH_COLUMN);

    $stmtRank = $pdo->query("
        SELECT u.nome, MAX(d.pontuacao_final) as recorde 
        FROM dino_historico d 
        JOIN usuarios u ON d.id_usuario = u.id 
        GROUP BY d.id_usuario 
        ORDER BY recorde DESC 
        LIMIT 5
    ");
    $ranking_dino = $stmtRank->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $ranking_dino = [];
    $minhas_skins = [];
}

// DEFINIÇÃO DAS SKINS (Configuração PHP - Preço e Emoji)
$catalogo_skins = [
    'porco'   => ['nome' => 'Porco',   'emoji' => '🐷', 'preco' => 10],
    'peixe'   => ['nome' => 'Peixe',   'emoji' => '🐟', 'preco' => 20],
    'galinha' => ['nome' => 'Galinha', 'emoji' => '🐔', 'preco' => 30],
    'boi'     => ['nome' => 'Boi',     'emoji' => '🐂', 'preco' => 40],
    'morcego' => ['nome' => 'Morcego', 'emoji' => '🦇', 'preco' => 50]
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

            $stmtCheck = $pdo->prepare("SELECT id FROM compras_skins WHERE id_usuario = :uid AND skin = :skin");
            $stmtCheck->execute([':uid' => $user_id, ':skin' => $skin]);
            if ($stmtCheck->rowCount() > 0) throw new Exception("Você já possui esta skin!");

            $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :id")->execute([':val' => $preco, ':id' => $user_id]);
            $pdo->prepare("INSERT INTO compras_skins (id_usuario, skin) VALUES (:uid, :skin)")->execute([':uid' => $user_id, ':skin' => $skin]);

            $pdo->commit();
            echo json_encode(['sucesso' => true, 'novo_saldo' => ($meu_perfil['pontos'] - $preco)]);
        } catch (Exception $e) { $pdo->rollBack(); echo json_encode(['erro' => $e->getMessage()]); }
        exit;
    }

    // B. EQUIPAR SKIN
    if ($_POST['acao'] == 'equipar_skin') {
        $skin = $_POST['skin'];
        $stmtCheck = $pdo->prepare("SELECT id FROM compras_skins WHERE id_usuario = :uid AND skin = :skin");
        $stmtCheck->execute([':uid' => $user_id, ':skin' => $skin]);
        
        if ($skin !== 'default' && $stmtCheck->rowCount() == 0) { echo json_encode(['erro' => 'Skin não adquirida.']); exit; }

        $pdo->prepare("UPDATE usuarios SET skin_equipada = :skin WHERE id = :id")->execute([':skin' => $skin, ':id' => $user_id]);
        echo json_encode(['sucesso' => true]);
        exit;
    }
    
    // C. SALVAR PONTOS (Milestone dinâmico, com limite anti-abuso)
    if ($_POST['acao'] == 'salvar_milestone') {
        $qtd = isset($_POST['qtd']) ? (int) $_POST['qtd'] : 0;
        // Evita valores negativos ou exagerados vindo do cliente
        $qtd = max(0, min($qtd, 10));
        if ($qtd > 0) {
            $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :uid")
                ->execute([':val' => $qtd, ':uid' => $user_id]);
        }
        echo json_encode(['sucesso' => true, 'creditado' => $qtd]);
        exit;
    }

    // D. REVIVER
    if ($_POST['acao'] == 'gastar_moedas_reviver') {
        $custo = 10;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $user_id]);
            $saldo = $stmt->fetchColumn();
            if ($saldo < $custo) throw new Exception("Saldo insuficiente.");
            $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :id")->execute([':val' => $custo, ':id' => $user_id]);
            $pdo->commit();
            echo json_encode(['sucesso' => true, 'novo_saldo' => $saldo - $custo]);
        } catch (Exception $e) { $pdo->rollBack(); echo json_encode(['erro' => $e->getMessage()]); }
        exit;
    }

    // E. SALVAR SCORE
    if ($_POST['acao'] == 'salvar_score') {
        $score_final = (int)$_POST['score'];
        try {
            $pdo->prepare("INSERT INTO dino_historico (id_usuario, pontuacao_final, pontos_ganhos) VALUES (:uid, :score, 0)")
                ->execute([':uid' => $user_id, ':score' => $score_final]);
        } catch (PDOException $ex) { }
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
    <title>Pinguim Run - Pikafumo Games</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🐧</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; overflow: hidden; }
        
        .navbar-custom { background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%); border-bottom: 1px solid #333; padding: 15px; }
        .saldo-badge { background-color: #00e676; color: #000; padding: 8px 15px; border-radius: 20px; font-weight: 800; font-size: 1.1em; box-shadow: 0 0 10px rgba(0, 230, 118, 0.3); transition: background-color 0.3s; }
        .admin-btn { background-color: #ff6d00; color: white; padding: 5px 15px; border-radius: 20px; text-decoration: none; font-weight: bold; font-size: 0.9em; transition: 0.3s; }

        #game-wrapper { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 80vh; }
        #game-container-inner { position: relative; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }

        canvas { border-bottom: 2px solid #555; display: block; border-radius: 8px; }

        .hud {
            font-family: 'Courier New', monospace;
            width: 800px; max-width: 100%; display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 1.2rem; font-weight: bold;
        }

        #start-msg {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            background: rgba(0,0,0,0.9); padding: 30px; border-radius: 15px; text-align: center;
            border: 1px solid #333; backdrop-filter: blur(5px); min-width: 320px; z-index: 10;
        }

        /* --- LOJA MODAL --- */
        #shop-modal {
            display: none; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            background: #1e1e1e; width: 90%; max-width: 500px; border-radius: 15px; border: 2px solid #444;
            z-index: 20; padding: 20px; box-shadow: 0 0 50px rgba(0,0,0,0.8);
        }
        .skin-card {
            background: #2c2c2c; border: 1px solid #444; border-radius: 10px; padding: 10px;
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;
        }
        .skin-emoji { font-size: 2rem; margin-right: 15px; }
        
        .floating-text {
            position: absolute; font-weight: bold; font-size: 1.5rem; color: #ffff00;
            text-shadow: 0 0 5px #ff9800; pointer-events: none; animation: floatUp 1s ease-out forwards;
        }
        @keyframes floatUp {
            0% { opacity: 1; transform: translateY(0) scale(1); }
            100% { opacity: 0; transform: translateY(-50px) scale(1.5); }
        }
        .rank-list li { border-bottom: 1px solid rgba(255,255,255,0.1); padding: 5px 0; }
    </style>
</head>
<body>

<!-- Header -->
<div class="navbar-custom d-flex justify-content-between align-items-center shadow-lg sticky-top">
    <div class="d-flex align-items-center gap-3">
        <span class="fs-5">Olá, <strong><?= htmlspecialchars($meu_perfil['nome']) ?></strong></span>
        <?php if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1): ?>
            <a href="../admin/dashboard.php" class="admin-btn"><i class="bi bi-gear-fill me-1"></i> Admin</a>
        <?php endif; ?>
    </div>
    <div class="d-flex align-items-center gap-3">
        <a href="../index.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar</a>
        <span class="saldo-badge" id="saldoDisplay"><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?> pts</span>
    </div>
</div>

<div id="game-wrapper">
    <div class="hud">
        <span class="text-warning">HI: <span id="highScore">0</span></span>
        <span>SETOR: <span id="biomeDisplay" class="text-white">PDSA</span></span>
        <span class="text-info">METROS: <span id="score">0</span></span>
    </div>
    
    <div id="game-container-inner">
        <canvas id="gameCanvas" width="800" height="300"></canvas>
        
        <!-- LOJA DE SKINS -->
        <div id="shop-modal">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="m-0 text-white"><i class="bi bi-shop"></i> Loja de Skins</h4>
                <button class="btn btn-sm btn-outline-secondary" onclick="toggleShop()"><i class="bi bi-x-lg"></i></button>
            </div>
            
            <div class="overflow-auto" style="max-height: 300px;">
                <!-- Padrão -->
                <div class="skin-card">
                    <div class="d-flex align-items-center">
                        <span class="skin-emoji">🐧</span>
                        <div>
                            <strong class="d-block text-white">Padrão</strong>
                            <small class="text-muted">Clássico</small>
                        </div>
                    </div>
                    <?php if($meu_perfil['skin_equipada'] == 'default'): ?>
                        <button class="btn btn-sm btn-secondary" disabled>Equipado</button>
                    <?php else: ?>
                        <button class="btn btn-sm btn-primary" onclick="equiparSkin('default')">Equipar</button>
                    <?php endif; ?>
                </div>

                <!-- Skins do Catálogo -->
                <?php foreach($catalogo_skins as $key => $data): 
                    $tenho = in_array($key, $minhas_skins);
                    $equipado = ($meu_perfil['skin_equipada'] == $key);
                ?>
                <div class="skin-card">
                    <div class="d-flex align-items-center">
                        <span class="skin-emoji"><?= $data['emoji'] ?></span>
                        <div>
                            <strong class="d-block text-white"><?= $data['nome'] ?></strong>
                            <?php if(!$tenho): ?>
                                <small class="text-warning fw-bold">$ <?= $data['preco'] ?></small>
                            <?php else: ?>
                                <small class="text-success"><i class="bi bi-check-circle-fill"></i> Comprado</small>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <?php if($equipado): ?>
                            <button class="btn btn-sm btn-secondary" disabled>Equipado</button>
                        <?php elseif($tenho): ?>
                            <button class="btn btn-sm btn-primary" onclick="equiparSkin('<?= $key ?>')">Equipar</button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-success" onclick="comprarSkin('<?= $key ?>', <?= $data['preco'] ?>)">Comprar</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- TELA INICIAL / GAME OVER -->
        <div id="start-msg">
            <div id="start-content">
                <h1 class="text-white mb-0 display-4" id="logoEmoji">🐧🛹</h1>
                <h3 class="text-white mb-2" id="msgTitle">PINGUIM SKATER</h3>
                <p class="text-secondary mb-3" id="msgSubtitle">Pule os bugs e cafés!</p>
                
                <div id="actionButtons">
                    <button class="btn btn-success btn-lg fw-bold px-5 rounded-pill shadow mb-3" onclick="startGame()">
                        <i class="bi bi-play-fill"></i> JOGAR
                    </button>
                </div>
                
                <div id="shopBtnContainer">
                    <button class="btn btn-warning fw-bold mb-3 w-100 rounded-pill" onclick="toggleShop()">
                        <i class="bi bi-cart-fill"></i> LOJA DE SKINS
                    </button>
                </div>
                
                <div id="rankingBox">
                    <?php if(!empty($ranking_dino)): ?>
                    <div class="mt-2 text-start bg-dark p-3 rounded border border-secondary">
                        <h6 class="text-warning border-bottom border-secondary pb-2 mb-2"><i class="bi bi-trophy-fill"></i> Top Corredores</h6>
                        <ul class="list-unstyled small text-secondary mb-0 rank-list">
                            <?php foreach($ranking_dino as $idx => $r): ?>
                                <li class="d-flex justify-content-between">
                                    <span>#<?= $idx+1 ?> <?= htmlspecialchars($r['nome']) ?></span>
                                    <strong class="text-white"><?= number_format($r['recorde'], 0, ',', '.') ?> HI</strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const canvas = document.getElementById('gameCanvas');
    const ctx = canvas.getContext('2d');
    const startMsg = document.getElementById('start-msg');
    const shopModal = document.getElementById('shop-modal');
    const scoreEl = document.getElementById('score');
    const highScoreEl = document.getElementById('highScore');
    const saldoDisplay = document.getElementById('saldoDisplay');
    const gameContainer = document.getElementById('game-container-inner');
    const biomeDisplay = document.getElementById('biomeDisplay');
    const msgTitle = document.getElementById('msgTitle');
    const msgSubtitle = document.getElementById('msgSubtitle');
    const actionButtons = document.getElementById('actionButtons');
    const rankingBox = document.getElementById('rankingBox');
    const logoEmoji = document.getElementById('logoEmoji');
    const shopBtnContainer = document.getElementById('shopBtnContainer');

    // --- CONFIGURAÇÃO ---
    // Adicionamos as CORES DO CORPO aqui no JavaScript
    const catalogoJS = {
        'default': { emoji: '🐧', bodyColor: '#000000', bellyColor: '#FFFFFF' },
        'porco':   { emoji: '🐷', bodyColor: '#f48fb1', bellyColor: '#f8bbd0' }, // Rosa
        'peixe':   { emoji: '🐟', bodyColor: '#039be5', bellyColor: '#4fc3f7' }, // Azul
        'galinha': { emoji: '🐔', bodyColor: '#eeeeee', bellyColor: '#ffffff' }, // Branco
        'boi':     { emoji: '🐂', bodyColor: '#5d4037', bellyColor: '#8d6e63' }, // Marrom
        'morcego': { emoji: '🦇', bodyColor: '#212121', bellyColor: '#424242' }  // Cinza Escuro
    };
    
    let currentSkin = '<?= $meu_perfil['skin_equipada'] ?>'; 
    let currentSaldo = <?= $meu_perfil['pontos'] ?>;

    const biomes = [
        { name: "PDSA",       skyTop: "#1a2a6c", skyBot: "#b21f1f", ground: "#222222" },
        { name: "PNIP",       skyTop: "#3a1c71", skyBot: "#d76d77", ground: "#8e44ad" },
        { name: "SIGBS",      skyTop: "#2980b9", skyBot: "#6dd5fa", ground: "#27ae60" },
        { name: "BOLICHEIRO", skyTop: "#cc2b5e", skyBot: "#753a88", ground: "#c0392b" }
    ];

    let gameSpeed = 8; 
    let score = 0;
    let highScore = localStorage.getItem('dinoHighScore') || 0;
    let isGameOver = false;
    let animationId;
    let nextRewardAt = 100; // controle de pagamento a cada 100m
    let currentBiomeIndex = 0;
    let backgroundX = 0;
    let hasRevived = false;

    let dino = { x: 50, y: 200, w: 40, h: 40, dy: 0, jumpForce: 13, grounded: false };
    let obstacles = [];
    let spawnTimer = 0;
    const gravity = 0.8; 
    const groundHeight = 250;

    highScoreEl.innerText = Math.floor(highScore);

    // --- FUNÃ‡Ã•ES DA LOJA ---
    function toggleShop() {
        if(shopModal.style.display === 'block') {
            shopModal.style.display = 'none';
            startMsg.style.display = 'block';
        } else {
            shopModal.style.display = 'block';
            startMsg.style.display = 'none';
        }
    }

    function comprarSkin(skinKey, preco) {
        if(currentSaldo < preco) { alert('Saldo insuficiente!'); return; }
        if(!confirm('Comprar skin por ' + preco + ' pontos?')) return;

        const fd = new FormData();
        fd.append('acao', 'comprar_skin');
        fd.append('skin', skinKey);

        fetch('index.php?game=pinguim', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if(data.sucesso) {
                alert('Compra realizada com sucesso!');
                location.reload(); 
            } else {
                alert(data.erro);
            }
        });
    }

    function equiparSkin(skinKey) {
        const fd = new FormData();
        fd.append('acao', 'equipar_skin');
        fd.append('skin', skinKey);

        fetch('index.php?game=pinguim', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if(data.sucesso) {
                currentSkin = skinKey;
                alert('Skin equipada!');
                location.reload();
            } else {
                alert(data.erro);
            }
        });
    }

    // --- GAME ENGINE ---

    function handleInput(e) {
        if((e.code === 'Space' || e.code === 'ArrowUp' || e.type === 'touchstart' || e.type === 'mousedown') && !isGameOver) {
            e.preventDefault();
            jump();
        }
    }

    document.addEventListener('keydown', handleInput);
    canvas.addEventListener('touchstart', handleInput);
    canvas.addEventListener('mousedown', handleInput);

    function jump() {
        if (dino.grounded) {
            dino.dy = -dino.jumpForce;
            dino.grounded = false;
        }
    }

    function startGame() {
        startMsg.style.display = 'none';
        resetGame();
        animate();
    }

    function resetGame() {
        isGameOver = false;
        hasRevived = false;
        score = 0;
        nextRewardAt = 100;
        gameSpeed = 8;
        obstacles = [];
        spawnTimer = 0;
        dino.dy = 0;
        dino.y = 200;
        dino.grounded = false;
        currentBiomeIndex = 0;
        scoreEl.innerText = 0;
    }

    function spawnObstacle() {
        let size = Math.random() * (55 - 35) + 35; 
        let type = Math.random() > 0.5 ? '☕' : '💻'; 
        let obstacle = { x: canvas.width + size, y: groundHeight - size + 5, w: 30, h: size, type: type };
        obstacles.push(obstacle);
    }

    function drawPenguin(x, y, w, h) {
        ctx.save();
        ctx.shadowColor = "rgba(0,0,0,0.5)"; ctx.shadowBlur = 5;

        // Recupera configuração da skin atual (ou usa default)
        const skinConfig = catalogoJS[currentSkin] || catalogoJS['default'];

        // 1. DESENHA O SKATE (Igual para todos)
        ctx.fillStyle = "#8d6e63"; 
        ctx.beginPath(); ctx.ellipse(x + w/2, y + h + 5, w/1.2, 5, 0, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = "#ffeb3b";
        ctx.beginPath(); ctx.arc(x + 10, y + h + 8, 4, 0, Math.PI * 2); ctx.arc(x + w - 10, y + h + 8, 4, 0, Math.PI * 2); ctx.fill();

        // 2. DESENHA O CORPO (Cor personalizada por Skin)
        
        // Corpo Principal
        ctx.fillStyle = skinConfig.bodyColor; 
        ctx.beginPath(); 
        ctx.ellipse(x + w/2, y + h/2, w/2, h/2, 0, 0, Math.PI * 2); 
        ctx.fill();
        
        // Barriga (Se for galinha ou outro muito claro, talvez não contraste bem, mas ok)
        ctx.fillStyle = skinConfig.bellyColor; 
        ctx.beginPath(); 
        ctx.ellipse(x + w/2 + 2, y + h/2 + 5, w/3, h/2.5, 0, 0, Math.PI * 2); 
        ctx.fill();

        // Asa/Braço (Mesma cor do corpo)
        ctx.fillStyle = skinConfig.bodyColor; 
        ctx.beginPath(); ctx.ellipse(x + 10, y + h/2 + 5, 5, 12, 0.5, 0, Math.PI * 2); ctx.fill();
        // Sombra da asa para destacar
        ctx.strokeStyle = "rgba(0,0,0,0.2)"; ctx.lineWidth = 1; ctx.stroke();

        if (currentSkin === 'default') {
            // CABEÃ‡A PADRÃƒO (Pinguim Original)
            ctx.fillStyle = "white"; ctx.beginPath(); ctx.arc(x + w/2 + 5, y + 10, 6, 0, Math.PI * 2); ctx.fill(); // Olho
            ctx.fillStyle = "black"; ctx.beginPath(); ctx.arc(x + w/2 + 7, y + 10, 2, 0, Math.PI * 2); ctx.fill(); // Pupila
            ctx.fillStyle = "#FF9800"; ctx.beginPath(); ctx.moveTo(x + w - 5, y + 15); ctx.lineTo(x + w + 5, y + 18); ctx.lineTo(x + w - 5, y + 21); ctx.fill(); // Bico
        } else {
            // CABEÃ‡A SKIN (Emoji Invertido)
            let emoji = skinConfig.emoji;
            
            ctx.save();
            ctx.font = "30px Arial";
            ctx.textAlign = "center";
            ctx.textBaseline = "middle";
            
            // Ponto central da cabeça
            let headX = x + w/2 + 5; 
            let headY = y + 10;
            
            ctx.translate(headX, headY);
            ctx.scale(-1, 1); // Espelha horizontalmente
            
            ctx.fillText(emoji, 0, 0); 
            ctx.restore();
        }

        ctx.restore();
    }

    function drawBackground() {
        let biome = biomes[currentBiomeIndex % biomes.length];
        
        let gradient = ctx.createLinearGradient(0, 0, 0, canvas.height);
        gradient.addColorStop(0, biome.skyTop);
        gradient.addColorStop(1, biome.skyBot);
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        backgroundX -= gameSpeed * 0.2; 
        if (backgroundX <= -canvas.width) backgroundX = 0;
        
        ctx.fillStyle = "rgba(0,0,0,0.3)";
        ctx.beginPath();
        for (let i = 0; i < 2; i++) {
            let offset = backgroundX + (i * canvas.width);
            ctx.moveTo(offset, groundHeight);
            ctx.lineTo(offset + 200, 150);
            ctx.lineTo(offset + 400, groundHeight);
            ctx.lineTo(offset + 600, 180);
            ctx.lineTo(offset + 800, groundHeight);
        }
        ctx.fill();

        ctx.beginPath();
        ctx.moveTo(0, groundHeight);
        ctx.lineTo(canvas.width, groundHeight);
        ctx.strokeStyle = biome.ground;
        ctx.lineWidth = 4;
        ctx.stroke();
    }

    function animate() {
        if (isGameOver) return; 

        animationId = requestAnimationFrame(animate);
        
        let newBiomeIndex = Math.floor(score / 1000);
        if(newBiomeIndex !== currentBiomeIndex) {
            currentBiomeIndex = newBiomeIndex;
            let biomeName = biomes[currentBiomeIndex % biomes.length].name;
            biomeDisplay.innerText = biomeName;
        }

        drawBackground(); 

        dino.dy += gravity;
        dino.y += dino.dy;
        if (dino.y + dino.h > groundHeight) {
            dino.y = groundHeight - dino.h;
            dino.dy = 0;
            dino.grounded = true;
        }

        drawPenguin(dino.x, dino.y, dino.w, dino.h);

        spawnTimer--;
        if (spawnTimer <= 0) {
            spawnObstacle();
            spawnTimer = 50 + Math.random() * (90 - gameSpeed * 2); 
            if (spawnTimer < 35) spawnTimer = 35; 
        }

        for (let i = 0; i < obstacles.length; i++) {
            let o = obstacles[i];
            o.x -= gameSpeed;
            ctx.font = "40px Arial";
            ctx.shadowColor = "rgba(0,0,0,0.8)";
            ctx.shadowBlur = 4;
            ctx.fillStyle = "#fff";
            ctx.fillText(o.type, o.x, o.y + 30);
            ctx.shadowBlur = 0;

            if (dino.x + 10 < o.x + o.w && dino.x + dino.w - 10 > o.x && dino.y + 10 < o.y + o.h && dino.y + dino.h > o.y) {
                gameOver();
            }

            if (o.x + o.w < 0) {
                obstacles.splice(i, 1);
                i--;
            }
        }

        score += 0.25; 
        let currentIntScore = Math.floor(score);
        scoreEl.innerText = currentIntScore;
        gameSpeed += 0.003; 

        while (currentIntScore >= nextRewardAt) {
            const coinsPer100 = 1 + Math.floor(currentIntScore / 500); // 0-499:1 | 500-999:2 | 1000+:3...
            creditMilestoneCoins(coinsPer100);
            nextRewardAt += 100;
        }
    }

    function gameOver() {
        isGameOver = true;
        cancelAnimationFrame(animationId);
        
        let finalScore = Math.floor(score);

        ctx.fillStyle = "rgba(0,0,0,0.7)";
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        
        startMsg.style.display = 'block';
        rankingBox.style.display = 'none'; 
        
        if(shopBtnContainer) shopBtnContainer.style.display = 'none';

        if (!hasRevived && currentSaldo >= 10) {
            msgTitle.innerText = "BATIDA FEIA! 🤕";
            msgSubtitle.innerHTML = `Putz, bateu! O que deseja fazer?`;
            
            actionButtons.innerHTML = `
                <button class="btn btn-success btn-lg fw-bold px-5 rounded-pill shadow mb-3 w-100" onclick="finishGame(${finalScore})">
                    <i class="bi bi-arrow-clockwise"></i> JOGAR DO ZERO
                </button>
                <button class="btn btn-outline-warning fw-bold w-100 rounded-pill" onclick="reviveGame()">
                    <i class="bi bi-heart-pulse-fill"></i> REVIVER POR 10 PTS
                </button>
            `;
        } else {
            finishGame(finalScore);
        }
    }

    function reviveGame() {
        const formData = new FormData();
        formData.append('acao', 'gastar_moedas_reviver');

        fetch('index.php?game=pinguim', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if(data.sucesso) {
                currentSaldo = data.novo_saldo;
                saldoDisplay.innerText = currentSaldo.toLocaleString('pt-BR') + " pts";
                isGameOver = false;
                hasRevived = true; 
                obstacles = []; 
                spawnTimer = 60; 
                startMsg.style.display = 'none';
                animate(); 
                showFloatingText("REVIVEU! -10 PTS", dino.x, dino.y - 50);
            } else {
                alert("Erro: " + data.erro);
                finishGame(Math.floor(score)); 
            }
        });
    }

    function finishGame(finalScore) {
        if (finalScore > highScore) {
            highScore = finalScore;
            localStorage.setItem('dinoHighScore', highScore);
            highScoreEl.innerText = highScore;
        }

        saveFinalScore(finalScore);

        msgTitle.innerText = "FIM DE JOGO 💀";
        msgSubtitle.innerHTML = `Você correu <strong class="text-white">${finalScore}m</strong>`;
        
        actionButtons.innerHTML = `
            <button class="btn btn-success btn-lg fw-bold px-5 rounded-pill shadow mb-3" onclick="startGame()">
                <i class="bi bi-play-fill"></i> JOGAR NOVAMENTE
            </button>
        `;
        
        rankingBox.style.display = 'block'; 
        startMsg.style.display = 'block';
        
        if(shopBtnContainer) shopBtnContainer.style.display = 'none';
    }

    function creditMilestoneCoins(amount) {
        if (amount <= 0) return;
        showFloatingText(`+${amount} PTS`, dino.x + 20, dino.y - 50);

        const formData = new FormData();
        formData.append('acao', 'salvar_milestone');
        formData.append('qtd', amount);

        fetch('index.php?game=pinguim', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            const ganho = (data && typeof data.creditado !== 'undefined') ? parseInt(data.creditado, 10) : amount;
            if(ganho > 0) {
                currentSaldo += ganho; 
                saldoDisplay.innerText = currentSaldo.toLocaleString('pt-BR') + " pts";
                saldoDisplay.style.backgroundColor = "#ffeb3b";
                saldoDisplay.style.color = "#000";
                setTimeout(() => { saldoDisplay.style.backgroundColor = "#00e676"; }, 500);
            }
        });
    }

    function saveFinalScore(finalScore) {
        const formData = new FormData();
        formData.append('acao', 'salvar_score');
        formData.append('score', finalScore);

        fetch('index.php?game=pinguim', { method: 'POST', body: formData });
    }

    function showFloatingText(text, x, y) {
        const el = document.createElement('div');
        el.className = 'floating-text';
        el.innerText = text;
        el.style.left = x + 'px'; 
        el.style.top = y + 'px';
        gameContainer.appendChild(el);
        setTimeout(() => { el.remove(); }, 1000);
    }
</script>

</body>
</html>
