<?php
// corrida.php - CORRIDA MULTIPLAYER REALTIME 🏎️💨
ini_set('display_errors', 1);
error_reporting(E_ALL);
// session_start já é chamado em games/index.php
require '../core/conexao.php';

// 1. Segurança
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];

// 2. Configuração do Banco
try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);

    // Tabelas de Corrida
    $pdo->exec("CREATE TABLE IF NOT EXISTS corrida_salas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        status VARCHAR(20) DEFAULT 'aguardando', -- aguardando, correndo, finalizada
        seed INT DEFAULT 0, -- Semente para gerar obstáculos iguais para todos
        data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS corrida_participantes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_sala INT,
        id_usuario INT,
        nome_usuario VARCHAR(50),
        progresso FLOAT DEFAULT 0, -- Distância percorrida (0 a 100%)
        tempo_final DECIMAL(10,3) DEFAULT NULL,
        status VARCHAR(20) DEFAULT 'aguardando', -- aguardando, pronto, correndo, finalizou
        recompensa_coletada TINYINT(1) DEFAULT 0,
        lane INT DEFAULT 2, -- Pista atual (0-4) para renderizar fantasmas
        ultimo_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Ranking de Vitórias
    $sqlRanking = "SELECT p.nome_usuario, COUNT(*) as vitorias 
                   FROM corrida_participantes p 
                   JOIN (SELECT id_sala, MIN(tempo_final) as melhor_tempo FROM corrida_participantes WHERE status='finalizou' AND tempo_final > 0 GROUP BY id_sala) w 
                   ON p.id_sala = w.id_sala AND p.tempo_final = w.melhor_tempo 
                   GROUP BY p.nome_usuario 
                   ORDER BY vitorias DESC LIMIT 5";
    $ranking_vitorias = $pdo->query($sqlRanking)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erro DB: " . $e->getMessage());
}

// --- API AJAX ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    // Desabilita display de erros para não quebrar JSON
    ini_set('display_errors', 0);
    header('Content-Type: application/json');
    
    // A. ENTRAR/CRIAR SALA
    if ($_POST['acao'] == 'entrar_fila') {
        $custo = 5;
        try {
            $pdo->beginTransaction();
            
            // Verifica saldo
            $stmtS = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
            $stmtS->execute([':id' => $user_id]);
            if ($stmtS->fetchColumn() < $custo) throw new Exception("Saldo insuficiente ($custo moedas).");

            // Busca sala aberta com vaga (< 5 players)
            $stmtCheck = $pdo->query("SELECT s.id FROM corrida_salas s 
                                      LEFT JOIN corrida_participantes p ON s.id = p.id_sala 
                                      WHERE s.status = 'aguardando' 
                                      GROUP BY s.id HAVING COUNT(p.id) < 5 LIMIT 1");
            $sala_id = $stmtCheck->fetchColumn();

            // Se não achar, cria nova
            if (!$sala_id) {
                $seed = rand(1, 999999);
                $pdo->prepare("INSERT INTO corrida_salas (seed) VALUES (:seed)")->execute([':seed' => $seed]);
                $sala_id = $pdo->lastInsertId();
            }

            // Verifica se já estou na sala
            $stmtMe = $pdo->prepare("SELECT id FROM corrida_participantes WHERE id_sala = :sid AND id_usuario = :uid");
            $stmtMe->execute([':sid' => $sala_id, ':uid' => $user_id]);
            
            if ($stmtMe->rowCount() == 0) {
                // Debita e entra
                $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :uid")->execute([':val' => $custo, ':uid' => $user_id]);
                $stmtJoin = $pdo->prepare("INSERT INTO corrida_participantes (id_sala, id_usuario, nome_usuario, status) VALUES (:sid, :uid, :nome, 'aguardando')");
                $stmtJoin->execute([':sid' => $sala_id, ':uid' => $user_id, ':nome' => $meu_perfil['nome']]);
            }

            $pdo->commit();
            echo json_encode(['sucesso' => true, 'sala_id' => $sala_id]);
        } catch (Exception $e) {
            $pdo->rollBack(); echo json_encode(['erro' => $e->getMessage()]);
        }
        exit;
    }

    // B. FICAR PRONTO
    if ($_POST['acao'] == 'ficar_pronto') {
        $sala_id = $_POST['sala_id'];
        $pdo->prepare("UPDATE corrida_participantes SET status = IF(status='pronto', 'aguardando', 'pronto') WHERE id_sala = :sid AND id_usuario = :uid")
            ->execute([':sid' => $sala_id, ':uid' => $user_id]);
        
        // Verifica se todos estão prontos para iniciar
        $stmtAll = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status='pronto' THEN 1 ELSE 0 END) as prontos FROM corrida_participantes WHERE id_sala = :sid");
        $stmtAll->execute([':sid' => $sala_id]);
        $stats = $stmtAll->fetch(PDO::FETCH_ASSOC);

        if ($stats['total'] >= 2 && $stats['total'] == $stats['prontos']) {
            $pdo->prepare("UPDATE corrida_salas SET status = 'correndo' WHERE id = :id")->execute([':id' => $sala_id]);
        }
        echo json_encode(['sucesso' => true]);
        exit;
    }

    // C. POLLING LOBBY & JOGO (Sincronização)
    if ($_POST['acao'] == 'sync_estado') {
        $sala_id = isset($_POST['sala_id']) ? (int)$_POST['sala_id'] : 0;
        
        if (!$sala_id) {
            echo json_encode(['erro' => 'ID da sala inválido']);
            exit;
        }
        
        // Se estiver correndo, atualiza meu progresso
        if (isset($_POST['progresso'])) {
            $pdo->prepare("UPDATE corrida_participantes SET progresso = :p, lane = :l WHERE id_sala = :sid AND id_usuario = :uid")
                ->execute([':p' => $_POST['progresso'], ':l' => $_POST['lane'], ':sid' => $sala_id, ':uid' => $user_id]);
        }

        // Busca dados da sala e oponentes
        $stmt_sala = $pdo->prepare("SELECT status, seed FROM corrida_salas WHERE id = :sid");
        $stmt_sala->execute([':sid' => $sala_id]);
        $sala = $stmt_sala->fetch(PDO::FETCH_ASSOC);
        
        if (!$sala) {
            echo json_encode(['erro' => 'Sala não encontrada']);
            exit;
        }
        
        $stmt_players = $pdo->prepare("SELECT id_usuario, nome_usuario, status, progresso, lane, tempo_final FROM corrida_participantes WHERE id_sala = :sid");
        $stmt_players->execute([':sid' => $sala_id]);
        $players = $stmt_players->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['sala' => $sala, 'players' => $players]);
        exit;
    }

    // D. FINALIZAR CORRIDA
    if ($_POST['acao'] == 'finalizar') {
        $sala_id = $_POST['sala_id'];
        $tempo = $_POST['tempo'];
        $moedas = isset($_POST['moedas']) ? (int)$_POST['moedas'] : 0;
        
        $pdo->prepare("UPDATE corrida_participantes SET tempo_final = :t, status = 'finalizou' WHERE id_sala = :sid AND id_usuario = :uid")
            ->execute([':t' => $tempo, ':sid' => $sala_id, ':uid' => $user_id]);
            
        echo json_encode(['sucesso' => true]);
        exit;
    }
    
    // E. REIVINDICAR PRÊMIO
    if ($_POST['acao'] == 'ver_podio') {
        $sala_id = $_POST['sala_id'];
        $moedas_coletadas = isset($_POST['moedas']) ? min((int)$_POST['moedas'], 10) : 0; // Máximo 10
        
        $stmt_rank = $pdo->prepare("SELECT id_usuario, nome_usuario, tempo_final FROM corrida_participantes WHERE id_sala = :sid AND status = 'finalizou' ORDER BY tempo_final ASC");
        $stmt_rank->execute([':sid' => $sala_id]);
        $ranking = $stmt_rank->fetchAll(PDO::FETCH_ASSOC);
        
        $premio = 0;
        if (!empty($ranking) && $ranking[0]['id_usuario'] == $user_id) {
            // Sou o vencedor!
            $check = $pdo->prepare("SELECT recompensa_coletada FROM corrida_participantes WHERE id_sala = :sid AND id_usuario = :uid");
            $check->execute([':sid' => $sala_id, ':uid' => $user_id]);
            
            if ($check->fetchColumn() == 0) {
                // Multiplayer: Leva todo o dinheiro apostado (5 moedas * número de players)
                $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM corrida_participantes WHERE id_sala = :sid");
                $stmt_count->execute([':sid' => $sala_id]);
                $count = $stmt_count->fetchColumn();
                $premio = $count * 5; // Todo o pote vai para o vencedor
                
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :uid")->execute([':val' => $premio, ':uid' => $user_id]);
                $pdo->prepare("UPDATE corrida_participantes SET recompensa_coletada = 1 WHERE id_sala = :sid AND id_usuario = :uid")->execute([':sid' => $sala_id, ':uid' => $user_id]);
                $pdo->commit();
            }
        } elseif ($_POST['modo'] == 'SOLO') {
            // Pagamento SOLO (Treino) - crédita apenas moedas coletadas
            $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")->execute([':val' => $moedas_coletadas, ':id' => $user_id]);
            $premio = $moedas_coletadas;
        }

        echo json_encode(['ranking' => $ranking, 'premio' => $premio]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Turbo Race - Multiplayer</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🏎️</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #121212; color: #fff; font-family: 'Segoe UI', sans-serif; overflow: hidden; margin: 0; }
        
        #game-wrapper { position: relative; width: 100vw; height: 100vh; background: #222; display: flex; justify-content: center; }
        canvas { background: #333; box-shadow: 0 0 50px rgba(0,0,0,0.5); max-width: 100%; height: 100%; display: block; }

        /* UI Overlays */
        .overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); display: flex; align-items: center; justify-content: center; flex-direction: column; z-index: 100; }
        .hidden { display: none !important; }
        
        .card-menu { background: #1e1e1e; border: 1px solid #333; padding: 30px; border-radius: 20px; text-align: center; max-width: 500px; width: 90%; box-shadow: 0 0 30px rgba(0,255,100,0.1); }
    .btn-neon { background: #FC082B; color: #000; font-weight: 800; border: none; padding: 12px 30px; border-radius: 50px; transition: 0.2s; text-transform: uppercase; letter-spacing: 1px; }
    .btn-neon:hover { transform: scale(1.05); box-shadow: 0 0 15px #FC082B; color: #000; }
        
        /* HUD In-Game */
        .hud { position: absolute; top: 20px; left: 50%; transform: translateX(-50%); width: 100%; max-width: 600px; display: flex; justify-content: space-between; padding: 0 20px; pointer-events: none; z-index: 10; font-family: monospace; font-weight: bold; text-shadow: 2px 2px 0 #000; font-size: 1.2rem; }
        .turbo-bar { width: 150px; height: 20px; background: #555; border: 2px solid #fff; border-radius: 10px; overflow: hidden; position: relative; }
        .turbo-fill { width: 100%; height: 100%; background: linear-gradient(90deg, #ff9800, #ffeb3b); transition: width 0.1s; }
        .turbo-text { position: absolute; top:0; left:0; width:100%; text-align:center; font-size:12px; color:#000; line-height:18px; }
        
        /* Player Tags */
        .player-list { position: absolute; top: 80px; left: 20px; background: rgba(0,0,0,0.5); padding: 10px; border-radius: 8px; font-family: monospace; }
        .p-item { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; font-size: 14px; }
        .p-color { width: 12px; height: 12px; border-radius: 50%; border: 1px solid #fff; }
    </style>
</head>
<body>

<div id="game-wrapper">
    <canvas id="raceCanvas"></canvas>

    <!-- HUD -->
    <div id="game-hud" class="hud hidden">
        <div class="text-warning">
            <div id="timer">00:00.00</div>
            <div class="small text-white" id="lap">VOLTA 1/2</div>
        </div>
        <div class="text-info" style="position: absolute; top: 80px; right: 20px; font-family: monospace; background: rgba(0,0,0,0.5); padding: 8px 12px; border-radius: 8px;">
            💰 <span id="coins-display">0</span>/10
        </div>
        <div class="turbo-bar">
            <div id="turbo-fill" class="turbo-fill"></div>
            <div class="turbo-text">NITRO</div>
        </div>
        <div class="text-success"><span id="speed">0</span> km/h</div>
    </div>

    <!-- Lista de Jogadores (Lateral) -->
    <div id="player-list" class="player-list hidden"></div>

    <!-- TELA MENU -->
    <div id="menu-screen" class="overlay">
        <div class="card-menu">
            <h1 class="display-3 mb-4">🏁 TURBO RACE</h1>
            <p class="text-secondary mb-4">Corra contra outros jogadores ou treine suas habilidades.</p>
            <div class="d-grid gap-3">
                <button onclick="startSolo()" class="btn btn-outline-light btn-lg fw-bold"><i class="bi bi-person me-2"></i>CORRER</button>
                <button onclick="comingSoon()" class="btn btn-secondary btn-lg fw-bold" disabled><i class="bi bi-tools me-2"></i>MULTIPLAYER (Em Desenvolvimento)</button>
            </div>
            <div class="mt-4 pt-3 border-top border-secondary">
                <a href="../index.php" class="text-white-50 text-decoration-none small"><i class="bi bi-arrow-left"></i> Voltar ao Painel</a>
            </div>
        </div>
    </div>

    <!-- TELA LOBBY -->
    <div id="lobby-screen" class="overlay hidden">
        <div class="card-menu">
            <h2 class="text-warning mb-3">SALA DE ESPERA</h2>
            <div id="lobby-players" class="mb-4 text-start bg-dark p-3 rounded" style="min-height: 100px;">
                <!-- Lista de players via JS -->
            </div>
            <button id="btn-ready" onclick="toggleReady()" class="btn btn-secondary w-100 py-3 fw-bold fs-5">NÃO ESTOU PRONTO</button>
            <div class="mt-3 small text-muted">A corrida inicia quando todos estiverem prontos (mín 2).</div>
        </div>
    </div>

    <!-- TELA RESULTADO -->
    <div id="result-screen" class="overlay hidden">
        <div class="card-menu">
            <h1 id="res-title" class="display-1 mb-2">🏆</h1>
            <h2 class="mb-3">FIM DE CORRIDA</h2>
            <div id="res-content" class="bg-dark p-3 rounded mb-3 text-start"></div>
            <button onclick="location.reload()" class="btn-neon w-100">CONTINUAR</button>
        </div>
    </div>
</div>

<script>
    // --- CONFIGURAÇÃO ---
    const canvas = document.getElementById('raceCanvas');
    const ctx = canvas.getContext('2d');
    
    // Ajuste de Resolução (HD)
    canvas.width = 600;
    canvas.height = 800;

    const LANE_COUNT = 5;
    const LANE_WIDTH = canvas.width / LANE_COUNT;
    const CAR_W = 50; 
    const CAR_H = 90;
    const COLORS = ['#d32f2f', '#1976d2', '#388e3c', '#fbc02d', '#7b1fa2']; // Cores dos slots

    // Estado do Jogo
    let gameState = 'MENU'; // MENU, LOBBY, RUNNING, FINISHED
    let gameMode = 'SOLO';
    let myId = <?= $user_id ?>;
    let myName = "<?= $meu_perfil['nome'] ?>";
    let roomId = null;
    let syncInterval = null;
    
    // Variáveis de Corrida
    let speed = 0;
    let maxSpeed = 20;
    let cameraY = 0;
    let playerX = 2.5 * LANE_WIDTH; // Meio
    let playerLane = 2;
    let turbo = 100;
    let isTurbo = false;
    let startTime = 0;
    let raceTime = 0;
    
    let trackLength = 100000; // Distância total (2 voltas x 50000)
    let traffic = []; 
    let opponents = {}; // Jogadores remotos { id: {x, y, progresso, cor} }
    let particles = [];
    let coins = []; // Moedas na pista
    let coinsCollected = 0; // Máximo 10
    const MAX_COINS = 10;

    // Controles
    const keys = { ArrowLeft: false, ArrowRight: false, ArrowUp: false, KeyN: false };
    document.addEventListener('keydown', e => keys[e.code] = true);
    document.addEventListener('keyup', e => keys[e.code] = false);

    // --- FUNÇÕES DE REDE (AJAX) ---
    
    function enterLobby() {
        gameMode = 'MULTI';
        document.getElementById('menu-screen').classList.add('hidden');
        document.getElementById('lobby-screen').classList.remove('hidden');
        
        const fd = new FormData(); fd.append('acao', 'entrar_fila');
        fetch('index.php?game=corrida', { method:'POST', body:fd }).then(r=>r.json()).then(d => {
            if(d.erro) { alert(d.erro); location.reload(); return; }
            if(!d.sala_id) { alert('Erro ao criar sala'); location.reload(); return; }
            roomId = d.sala_id;
            console.log('Sala criada:', roomId);
            syncInterval = setInterval(syncLobby, 1500); // Polling lento no lobby
            syncLobby();
        }).catch(err => {
            alert('Erro de conexão: ' + err.message);
            location.reload();
        });
    }

    function toggleReady() {
        if (!roomId) {
            alert('Erro: Sala não encontrada. Recarregue a página.');
            return;
        }
        const fd = new FormData(); fd.append('acao', 'ficar_pronto'); fd.append('sala_id', roomId);
        fetch('index.php?game=corrida', { method:'POST', body:fd }).then(r=>r.json()).then(d => {
            if(d.erro) alert(d.erro);
            else syncLobby();
        });
    }

    function syncLobby() {
        const fd = new FormData(); fd.append('acao', 'sync_estado'); fd.append('sala_id', roomId);
        fetch('index.php?game=corrida', { method:'POST', body:fd })
            .then(r => r.text())
            .then(text => {
                try {
                    const d = JSON.parse(text);
                    renderLobby(d.players);
                    // Verifica se começou
                    if(d.sala.status === 'correndo') {
                        clearInterval(syncInterval);
                        initRace(parseInt(d.sala.seed));
                    }
                } catch(e) {
                    console.error('Erro ao processar resposta:', text);
                    clearInterval(syncInterval);
                    alert('Erro no servidor. Verifique o console.');
                }
            })
            .catch(err => {
                console.error('Erro de rede:', err);
                clearInterval(syncInterval);
            });
    }

    function renderLobby(players) {
        let html = '';
        let meReady = false;
        players.forEach((p, i) => {
            let color = COLORS[i % COLORS.length];
            let statusBadge = p.status === 'pronto' ? '<span class="badge bg-success">PRONTO</span>' : '<span class="badge bg-secondary">...</span>';
            html += `<div class="d-flex justify-content-between align-items-center mb-2 text-white p-2 rounded" style="border-left: 5px solid ${color}; background: #333;">
                        <span>${p.nome_usuario}</span> ${statusBadge}
                     </div>`;
            if(p.id_usuario == myId && p.status === 'pronto') meReady = true;
        });
        document.getElementById('lobby-players').innerHTML = html;
        
        const btn = document.getElementById('btn-ready');
        if(meReady) {
            btn.className = 'btn btn-success w-100 py-3 fw-bold fs-5 shadow';
            btn.innerHTML = '<i class="bi bi-check-circle-fill"></i> ESPERANDO INÍCIO...';
        } else {
            btn.className = 'btn btn-secondary w-100 py-3 fw-bold fs-5';
            btn.innerHTML = 'MARCAR COMO PRONTO';
        }
    }

    // --- ENGINE DE CORRIDA ---

    function comingSoon() {
        alert('🚀 Em Desenvolvimento! Volta em breve!');
    }

    function startSolo() {
        gameMode = 'SOLO';
        document.getElementById('menu-screen').classList.add('hidden');
        initRace(Date.now()); // Seed aleatória baseada no tempo
    }

    // RNG determinístico simples (LCG) para sincronizar tráfego entre clientes
    function createRng(seed) {
        let state = seed % 2147483647;
        if (state <= 0) state += 2147483646;
        return function() {
            state = state * 16807 % 2147483647;
            return (state - 1) / 2147483646;
        };
    }

    function initRace(seed) {
        document.getElementById('lobby-screen').classList.add('hidden');
        document.getElementById('game-hud').classList.remove('hidden');
        if(gameMode === 'MULTI') document.getElementById('player-list').classList.remove('hidden');

        // Reset Variaveis
        speed = 0; cameraY = 0; turbo = 100;
        playerX = 2.5 * LANE_WIDTH - (CAR_W/2);
        startTime = Date.now();
        gameState = 'RUNNING';

        // Gerar Tráfego com RNG determinístico baseado na seed
        traffic = [];
        const rng = createRng(seed || Date.now());
        for(let i=0; i<25; i++) {
            traffic.push({
                x: Math.floor(rng() * 5) * LANE_WIDTH + (LANE_WIDTH/2 - CAR_W/2),
                y: -i * 600 - 800,
                speed: 5 + rng() * 5,
                color: '#555'
            });
        }

        // Gerar Moedas ao longo da pista (máximo 15, player coleta até 10)
        coins = [];
        for(let i=0; i<15; i++) {
            coins.push({
                x: Math.floor(rng() * 5) * LANE_WIDTH + (LANE_WIDTH/2 - 10),
                y: -i * 8000 - 5000,
                collected: false
            });
        }
        coinsCollected = 0;

        if(gameMode === 'MULTI') syncInterval = setInterval(syncGame, 500); // Polling rápido no jogo
        requestAnimationFrame(gameLoop);
    }

    function syncGame() {
        if(gameState !== 'RUNNING') return;
        // Envia meu progresso (0-100%)
        let prog = Math.min(100, (cameraY / trackLength) * 100);
        // Calcula minha lane atual (0-4)
        let myLane = Math.floor((playerX + CAR_W/2) / LANE_WIDTH);

        const fd = new FormData(); 
        fd.append('acao', 'sync_estado'); 
        fd.append('sala_id', roomId);
        fd.append('progresso', prog);
        fd.append('lane', myLane);
        
        fetch('index.php?game=corrida', { method:'POST', body:fd }).then(r=>r.json()).then(d => {
            updateOpponents(d.players);
        });
    }

    function updateOpponents(players) {
        let html = '';
        players.forEach((p, i) => {
            if(p.id_usuario != myId) {
                // Atualiza ou cria oponente
                // O Y do oponente é relativo ao meu. Se ele tem 50% e eu 40%, ele está na frente.
                // Diferença de progresso * Tamanho da Pista = Diferença em Pixels
                let progDiff = parseFloat(p.progresso) - (cameraY / trackLength * 100);
                let pixelDiff = (progDiff / 100) * trackLength;
                
                // Posição Y na tela (fixa do player é 600)
                // Se pixelDiff > 0 (ele está na frente), o Y dele deve ser < 600
                let renderY = 600 - pixelDiff;

                opponents[p.id_usuario] = {
                    x: parseInt(p.lane) * LANE_WIDTH + (LANE_WIDTH/2 - CAR_W/2),
                    y: renderY,
                    color: COLORS[i % COLORS.length],
                    name: p.nome_usuario
                };
            }
            // Lista lateral
            html += `<div class="p-item"><div class="p-color" style="background:${COLORS[i%COLORS.length]}"></div> ${p.nome_usuario}: ${Math.floor(p.progresso)}%</div>`;
        });
        document.getElementById('player-list').innerHTML = html;
    }

    function gameLoop() {
        if(gameState !== 'RUNNING') return;

        updatePhysics();
        draw();
        requestAnimationFrame(gameLoop);
    }

    function updatePhysics() {
        // Controles
        // Aceleração base (seta para cima)
        if (keys.ArrowUp) {
            speed += 0.3;
        } else {
            speed -= 0.2;
        }

        // Nitro no "N" (consome turbo)
        if (keys.KeyN && turbo > 0) {
            isTurbo = true;
            speed += 0.7;
            turbo = Math.max(0, turbo - 1);
            spawnParticles();
        } else {
            isTurbo = false;
            if (turbo < 100) turbo = Math.min(100, turbo + 0.25);
        }
        
        if(keys.ArrowLeft && playerX > 0) playerX -= 8;
        if(keys.ArrowRight && playerX < canvas.width - CAR_W) playerX += 8;

        // Limite Velocidade
        let target = isTurbo ? 35 : 20;
        if(speed > target) speed = target;
        if(speed < 0) speed = 0;

        cameraY += speed;
        raceTime = (Date.now() - startTime) / 1000;

        // HUD Updates
        document.getElementById('timer').innerText = raceTime.toFixed(2);
        document.getElementById('speed').innerText = Math.floor(speed * 10);
        document.getElementById('turbo-fill').style.width = turbo + '%';
        // Lap counter
        let lap = Math.floor((cameraY / trackLength) * 2) + 1;
        document.getElementById('lap').innerText = 'VOLTA ' + Math.min(lap, 2) + '/2';

        // Colisão Tráfego
        traffic.forEach(t => {
            // Move tráfego relativo à câmera (se eu sou rápido, eles descem)
            t.y += (speed * 0.5) + (speed > 0 ? 2 : 0); 
            // Loop infinito de carros
            if(t.y > 900) { t.y = -1000 - Math.random()*500; t.x = Math.floor(Math.random()*5)*LANE_WIDTH + (LANE_WIDTH-CAR_W)/2; }
            
            // Hitbox simples
            if (playerX < t.x + CAR_W && playerX + CAR_W > t.x && 
                600 < t.y + CAR_H && 600 + CAR_H > t.y) {
                speed *= 0.5; // Bateu, perde velocidade
                shakeScreen();
            }
        });

        // Partículas
        particles.forEach((p, i) => { p.y += speed; p.life--; if(p.life<=0) particles.splice(i,1); });

        // Coleta de Moedas
        coins.forEach(coin => {
            // Move moeda relativa à câmera
            coin.y += speed;
            // Reset infinito
            if(coin.y > 900) coin.y = -trackLength + 5000 + Math.random() * 10000;
            // Colisão com player (hitbox simples)
            if (!coin.collected && 
                playerX < coin.x + 30 && playerX + CAR_W > coin.x &&
                600 - 50 < coin.y + 30 && 600 + CAR_H > coin.y) {
                coin.collected = true;
                if (coinsCollected < MAX_COINS) {
                    coinsCollected++;
                    document.getElementById('coins-display').innerText = coinsCollected;
                }
            }
        });

        // Fim de Jogo
        if(cameraY >= trackLength) finishRace();
    }

    function draw() {
        // Fundo (Pista)
        ctx.fillStyle = '#333'; ctx.fillRect(0,0,canvas.width, canvas.height);
        
        // Linhas
        let offset = cameraY % 100;
        ctx.strokeStyle = '#fff'; ctx.lineWidth = 4; ctx.setLineDash([40, 40]);
        for(let i=1; i<LANE_COUNT; i++) {
            ctx.beginPath(); ctx.moveTo(i*LANE_WIDTH, -100); ctx.lineTo(i*LANE_WIDTH, 900);
            ctx.lineDashOffset = -offset; ctx.stroke();
        }

        // Bordas (Barreira lateral fixa)
        ctx.fillStyle = '#d32f2f';
        ctx.fillRect(0,0, 20, 800); ctx.fillRect(canvas.width-20, 0, 20, 800);

        // Oponentes (Fantasmas)
        for(let id in opponents) {
            let op = opponents[id];
            // Só desenha se estiver na tela
            if(op.y > -100 && op.y < 900) {
                drawCar(op.x, op.y, op.color, false);
                ctx.fillStyle = '#fff'; ctx.font = '12px Arial'; ctx.fillText(op.name, op.x, op.y - 10);
            }
        }

        // Moedas
        coins.forEach(coin => {
            if (!coin.collected && coin.y > -50 && coin.y < 850) {
                ctx.fillStyle = '#ffd700';
                ctx.beginPath();
                ctx.arc(coin.x + 10, coin.y + 10, 15, 0, Math.PI * 2);
                ctx.fill();
                // Borda
                ctx.strokeStyle = '#ffb300';
                ctx.lineWidth = 2;
                ctx.stroke();
            }
        });

        // Tráfego
        traffic.forEach(t => drawCar(t.x, t.y, t.color, false));

        // Player
    drawCar(playerX, 600, '#FC082B', true); // Player fixo no Y=600

        // Partículas
        ctx.fillStyle = 'orange';
        particles.forEach(p => { ctx.fillRect(p.x, p.y, 4, 4); });
    }

    function drawCar(x, y, color, isPlayer) {
        // Sombra
        ctx.fillStyle = 'rgba(0,0,0,0.5)'; ctx.fillRect(x+10, y+10, CAR_W, CAR_H);
        // Corpo
        ctx.fillStyle = color; 
        roundRect(ctx, x, y, CAR_W, CAR_H, 5, true);
        // Detalhes
        ctx.fillStyle = '#111'; ctx.fillRect(x+5, y+15, CAR_W-10, 15); // Parabrisa
        ctx.fillStyle = isPlayer && isTurbo ? '#ffeb3b' : '#fff'; // Faróis
        ctx.fillRect(x+5, y+2, 8, 5); ctx.fillRect(x+CAR_W-13, y+2, 8, 5);
        // Fogo do Turbo
        if(isPlayer && isTurbo) {
            ctx.fillStyle = '#ff5722';
            ctx.beginPath(); ctx.moveTo(x+15, y+CAR_H); ctx.lineTo(x+25, y+CAR_H+30+Math.random()*10); ctx.lineTo(x+35, y+CAR_H); ctx.fill();
        }
    }

    function roundRect(ctx, x, y, w, h, r, fill) {
        ctx.beginPath(); ctx.moveTo(x+r, y); ctx.lineTo(x+w-r, y); ctx.quadraticCurveTo(x+w, y, x+w, y+r);
        ctx.lineTo(x+w, y+h-r); ctx.quadraticCurveTo(x+w, y+h, x+w-r, y+h); ctx.lineTo(x+r, y+h);
        ctx.quadraticCurveTo(x, y+h, x, y+h-r); ctx.lineTo(x, y+r); ctx.quadraticCurveTo(x, y, x+r, y); ctx.closePath();
        if(fill) ctx.fill();
    }

    function spawnParticles() {
        particles.push({ x: playerX + 15 + Math.random()*20, y: 600 + 80, life: 20 });
    }

    function shakeScreen() {
        canvas.style.transform = `translate(${Math.random()*10-5}px, ${Math.random()*10-5}px)`;
        setTimeout(() => canvas.style.transform = 'none', 100);
    }

    function finishRace() {
        gameState = 'FINISHED';
        clearInterval(syncInterval);
        document.getElementById('game-hud').classList.add('hidden');
        document.getElementById('result-screen').classList.remove('hidden');
        
        // Envia tempo final e moedas coletadas
        const fd = new FormData(); 
        fd.append('acao', 'finalizar'); 
        fd.append('sala_id', roomId);
        fd.append('tempo', raceTime);
        fd.append('moedas', coinsCollected);
        fetch('index.php?game=corrida', { method:'POST', body:fd });

        // Busca pódio (delay pequeno para garantir que banco atualizou)
        setTimeout(() => {
            const fd2 = new FormData(); fd2.append('acao', 'ver_podio'); fd2.append('sala_id', roomId); fd2.append('modo', gameMode); fd2.append('moedas', coinsCollected);
            fetch('index.php?game=corrida', { method:'POST', body:fd2 }).then(r=>r.json()).then(d => {
                let html = '<h5 class="text-warning mb-3">⏱️ RANKING DE TEMPO</h5>';
                html += '<ol class="list-group list-group-numbered" style="list-style: none; padding: 0;">';
                d.ranking.forEach((r, idx) => {
                    let medal = idx === 0 ? '🥇' : idx === 1 ? '🥈' : '🥉';
                    html += `<li class="list-group-item d-flex justify-content-between align-items-center bg-dark text-white border-secondary mb-2">
                                <div>${medal} ${r.nome_usuario}</div>
                                <span class="badge bg-primary rounded-pill">${parseFloat(r.tempo_final).toFixed(2)}s</span>
                             </li>`;
                });
                html += '</ol>';
                if(d.premio > 0) {
                    html += `<div class="alert alert-success mt-3 fw-bold text-center">💰 MOEDAS COLETADAS: ${d.premio}/10</div>`;
                }
                document.getElementById('res-content').innerHTML = html;
            });
        }, 1000);
    }

</script>
</body>
</html>