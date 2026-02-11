<?php
// LIGA O MOSTRADOR DE ERROS (Remova em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// memoria.php - O JOGO DA MEMÓRIA RAM (COM PERSISTÊNCIA E DARK MODE 💾🌙) 🧠
// session_start já foi chamado em games/index.php
require '../core/conexao.php';
require '../core/sequencia_dias.php';

// --- CONFIGURAÇÕES ---
$PONTOS_VITORIA = 10;
$LIMITE_MOVIMENTOS = 18; 

// 1. Segurança
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];

// 1.1 Campos de streak (idempotente)
try {
    $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS memoria_streak INT DEFAULT 0");
    $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS memoria_last DATE DEFAULT NULL");
} catch (Exception $e) {}

// --- 2. DADOS DO USUÁRIO (PARA O HEADER) ---
try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro perfil: " . $e->getMessage());
}

// --- 3. OBTER SEQUÊNCIA DE DIAS ---
$sequencia_dias = obterSequenciaDias($pdo, $user_id, 'memoria');

// --- FUNÇÕES DO JOGO ---
function gerarTabuleiroNovo() {
    $emojis = ['🚀', '🛸', '☕', '💻', '📅', '📊', '🔥', '💡'];
    $cards = array_merge($emojis, $emojis);
    shuffle($cards);
    $tabuleiro = [];
    foreach ($cards as $id => $emoji) {
        $tabuleiro[] = ['id' => $id, 'emoji' => $emoji, 'encontrado' => false];
    }
    return $tabuleiro;
}

// 2. Verifica ou Cria o Jogo do Dia
$hoje = date('Y-m-d');
$dados_jogo = null;

try {
    $stmt = $pdo->prepare("SELECT * FROM memoria_historico WHERE id_usuario = :uid AND data_jogo = :dt");
    $stmt->execute([':uid' => $user_id, ':dt' => $hoje]);
    $dados_jogo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dados_jogo) {
        $tabuleiro_inicial = json_encode(gerarTabuleiroNovo());
        $stmtIns = $pdo->prepare("INSERT INTO memoria_historico (id_usuario, data_jogo, tempo_segundos, movimentos, pontos_ganhos, status, estado_jogo) VALUES (:uid, :dt, 0, 0, 0, 'jogando', :tab)");
        $stmtIns->execute([':uid' => $user_id, ':dt' => $hoje, ':tab' => $tabuleiro_inicial]);
        $stmt->execute([':uid' => $user_id, ':dt' => $hoje]);
        $dados_jogo = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    die("<div class='alert alert-danger'>Erro Crítico: Tabela memoria_historico faltando.</div>");
}

$tabuleiro_atual = (!empty($dados_jogo['estado_jogo'])) ? json_decode($dados_jogo['estado_jogo'], true) : null;

// FIX CORRUPÇÃO
if (!is_array($tabuleiro_atual)) {
    $tabuleiro_atual = gerarTabuleiroNovo();
    if (isset($dados_jogo['id'])) {
        $pdo->prepare("UPDATE memoria_historico SET estado_jogo = :tab WHERE id = :id")->execute([':tab' => json_encode($tabuleiro_atual), ':id' => $dados_jogo['id']]);
    }
}

$movimentos_atuais = $dados_jogo['movimentos'] ?? 0;
$status_atual = $dados_jogo['status'] ?? 'jogando'; 

// --- API DE ATUALIZAÇÃO (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');

    if ($status_atual !== 'jogando') { echo json_encode(['erro' => 'Jogo já finalizado.']); exit; }

    if ($_POST['acao'] == 'atualizar_estado') {
        $novos_movimentos = (int)$_POST['movimentos'];
        $pares_encontrados = json_decode($_POST['pares_encontrados'], true) ?? []; 
        $tempo = (int)$_POST['tempo'];

        foreach ($tabuleiro_atual as &$carta) {
            if (in_array($carta['id'], $pares_encontrados)) $carta['encontrado'] = true;
        }
        
        $novo_estado_json = json_encode($tabuleiro_atual);
        $novo_status = 'jogando';
        $pontos = 0;

        if ($novos_movimentos >= $LIMITE_MOVIMENTOS) {
            $todos_encontrados = true;
            foreach ($tabuleiro_atual as $c) { if(!$c['encontrado']) $todos_encontrados = false; }
            if (!$todos_encontrados) $novo_status = 'perdeu';
        }

        if ($novo_status == 'jogando') {
            $vitoria = true;
            foreach ($tabuleiro_atual as $c) { if (!$c['encontrado']) { $vitoria = false; break; } }
            if ($vitoria) { $novo_status = 'venceu'; $pontos = $PONTOS_VITORIA; }
        }

        $stmtUpd = $pdo->prepare("UPDATE memoria_historico SET movimentos = :m, tempo_segundos = :t, estado_jogo = :st_json, status = :st, pontos_ganhos = :pts WHERE id = :id");
        $stmtUpd->execute([':m' => $novos_movimentos, ':t' => $tempo, ':st_json' => $novo_estado_json, ':st' => $novo_status, ':pts' => $pontos, ':id' => $dados_jogo['id']]);

        if ($novo_status == 'venceu' && $dados_jogo['pontos_ganhos'] == 0) {
            try {
                $pdo->beginTransaction();

                // Atualiza sequência diária usando helper centralizado (tabela usuario_sequencias_dias)
                $novaSequencia = atualizarSequenciaDias($pdo, $user_id, 'memoria', true);

                $pdo->prepare("UPDATE usuarios SET pontos = pontos + :pts WHERE id = :uid")
                    ->execute([':pts' => $pontos, ':uid' => $user_id]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
            }
        }

        echo json_encode(['status' => $novo_status, 'movimentos' => $novos_movimentos]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Memória RAM - Pikafumo Games</title>
    
    <!-- Favicon -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🧠</text></svg>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        /* PADRÃO DARK MODE */
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; }
        
        /* Navbar Padronizada */
        .navbar-custom { 
            background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%);
            border-bottom: 1px solid #333;
            padding: 15px; 
        }
        .saldo-badge { 
            background-color: #00e676; color: #000; padding: 8px 15px; 
            border-radius: 20px; font-weight: 800; font-size: 1.1em;
            box-shadow: 0 0 10px rgba(0, 230, 118, 0.3);
        }
        .admin-btn { 
            background-color: #ff6d00; color: white; padding: 5px 15px; 
            border-radius: 20px; text-decoration: none; font-weight: bold; font-size: 0.9em; transition: 0.3s; 
        }
        .admin-btn:hover { background-color: #e65100; color: white; box-shadow: 0 0 8px #ff6d00; }

        /* JOGO */
        .game-container { max-width: 600px; margin: 0 auto; padding: 20px; }
        
        .memory-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-top: 20px;
            perspective: 1000px;
        }

        .card-game {
            background-color: transparent;
            aspect-ratio: 1;
            border-radius: 8px;
            cursor: pointer;
            position: relative;
            transform-style: preserve-3d;
            transition: transform 0.5s;
        }

        .card-game.flip { transform: rotateY(180deg); }
        .card-game.matched .card-back { background-color: #00e676 !important; border-color: #00e676; box-shadow: 0 0 10px rgba(0,230,118,0.5); }

        .card-face {
            width: 100%; height: 100%; position: absolute;
            backface-visibility: hidden; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 2.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.5);
            border: 1px solid #333;
        }
        
        .card-front { background-color: #1e1e1e; color: #555; font-size: 1.5rem; transform: rotateY(0deg); }
        .card-back { background-color: #f5f5f5; color: #2c3e50; transform: rotateY(180deg); }

        .stats-bar {
            background: #1e1e1e;
            padding: 15px;
            border-radius: 50px;
            display: flex;
            justify-content: space-around;
            margin-bottom: 20px;
            font-weight: bold;
            border: 1px solid #333;
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
        }
        
        .limit-warning { color: #ff3d00; animation: pulse 1s infinite; text-shadow: 0 0 5px #ff3d00; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
    </style>
</head>
<body>

<!-- Header Padronizado -->
<div class="navbar-custom d-flex justify-content-between align-items-center shadow-lg sticky-top">
    <div class="d-flex align-items-center gap-3">
        <span class="fs-5">Olá, <strong><?= htmlspecialchars($meu_perfil['nome']) ?></strong></span>
        <?php if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1): ?>
            <a href="../admin/dashboard.php" class="admin-btn"><i class="bi bi-gear-fill me-1"></i> Admin</a>
        <?php endif; ?>
    </div>
    
    <div class="d-flex align-items-center gap-3">
        <a href="../index.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar ao Painel</a>
        <div style="background: linear-gradient(135deg, #ff006e, #8338ec); padding: 8px 16px; border-radius: 20px; font-weight: bold; color: white; display: flex; align-items: center; gap: 8px;">
            <i class="bi bi-fire"></i>
            <span id="sequencia-display"><?= $sequencia_dias['sequencia_atual'] ?? 0 ?></span>
            <span style="font-size: 0.85rem;">dias</span>
        </div>
        <span class="saldo-badge me-2"><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?> pts</span>
    </div>
</div>

<div class="container game-container text-center mt-3">
    
    <h3 class="mb-4 text-info fw-bold"><i class="bi bi-cpu-fill me-2"></i>MEMÓRIA RAM</h3>

    <?php if($status_atual == 'venceu'): ?>
        <div class="alert alert-success mt-5 p-5 shadow-lg border-0 bg-success bg-opacity-25 text-white">
            <h1 class="display-1">🧠🏆</h1>
            <h3 class="mt-3">Missão Cumprida!</h3>
            <p class="lead">Você completou o desafio em <strong><?= $dados_jogo['movimentos'] ?></strong> movimentos.</p>
            <p>Pontos creditados: <strong>+<?= $PONTOS_VITORIA ?></strong></p>
            <a href="../index.php" class="btn btn-outline-light btn-lg mt-3 fw-bold">Voltar ao Painel</a>
        </div>
    <?php elseif($status_atual == 'perdeu'): ?>
        <div class="alert alert-danger mt-5 p-5 shadow-lg border-0 bg-danger bg-opacity-25 text-white">
            <h1 class="display-1">💥🧠</h1>
            <h3 class="mt-3">Você perdeu!</h3>
            <p class="lead">Você atingiu o limite de <strong><?= $LIMITE_MOVIMENTOS ?></strong> movimentos sem completar o desafio.</p>
            <p class="fs-5 fw-bold">Tente novamente amanhã! 🧠</p>
            <a href="../index.php" class="btn btn-outline-light btn-lg mt-3 fw-bold">Voltar ao Painel</a>
        </div>
    <?php else: ?>

        <div class="stats-bar text-secondary">
            <span><i class="bi bi-stopwatch me-2"></i>Tempo: <span id="timer" class="text-white"><?= $dados_jogo['tempo_segundos'] ?></span>s</span>
            <span>
                <i class="bi bi-arrow-repeat me-2"></i>Movimentos: 
                <span id="moves" class="<?= ($movimentos_atuais >= $LIMITE_MOVIMENTOS - 4) ? 'limit-warning' : 'text-white' ?>">
                    <?= $movimentos_atuais ?>
                </span> / <?= $LIMITE_MOVIMENTOS ?>
            </span>
        </div>

        <div class="memory-grid" id="grid">
            <?php foreach($tabuleiro_atual as $carta): ?>
                <div class="card-game <?= $carta['encontrado'] ? 'flip matched' : '' ?>" 
                     data-id="<?= $carta['id'] ?>" 
                     data-emoji="<?= $carta['emoji'] ?>"
                     <?= $carta['encontrado'] ? 'style="pointer-events: none;"' : '' ?>>
                    <div class="card-face card-front"><i class="bi bi-cpu-fill"></i></div>
                    <div class="card-face card-back"><?= $carta['emoji'] ?></div>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

</div>

<?php if($status_atual == 'jogando'): ?>
<script>
    const LIMITE = <?= $LIMITE_MOVIMENTOS ?>;
    let cards = document.querySelectorAll('.card-game');
    let hasFlippedCard = false, lockBoard = false;
    let firstCard, secondCard;
    let moves = <?= $movimentos_atuais ?>;
    let seconds = <?= $dados_jogo['tempo_segundos'] ?>;
    let timerInterval, gameStarted = false;

    const timerDisplay = document.getElementById('timer');
    const movesDisplay = document.getElementById('moves');

    cards.forEach(card => {
        if(!card.classList.contains('matched')) card.addEventListener('click', flipCard);
    });

    function startTimer() {
        if(gameStarted) return;
        gameStarted = true;
        timerInterval = setInterval(() => {
            seconds++; timerDisplay.textContent = seconds;
        }, 1000);
    }
    if(moves > 0) startTimer();

    function flipCard() {
        if (lockBoard || this === firstCard) return;
        startTimer();
        this.classList.add('flip');
        if (!hasFlippedCard) { hasFlippedCard = true; firstCard = this; return; }
        secondCard = this;
        incrementMoves();
        checkForMatch();
    }

    function checkForMatch() {
        let isMatch = firstCard.dataset.emoji === secondCard.dataset.emoji;
        isMatch ? disableCards() : unflipCards();
    }

    function disableCards() {
        firstCard.classList.add('matched'); secondCard.classList.add('matched');
        firstCard.removeEventListener('click', flipCard); secondCard.removeEventListener('click', flipCard);
        saveGameState(); resetBoard();
    }

    function unflipCards() {
        lockBoard = true;
        setTimeout(() => {
            firstCard.classList.remove('flip'); secondCard.classList.remove('flip');
            saveGameState(); resetBoard();
        }, 1000);
    }

    function resetBoard() {
        [hasFlippedCard, lockBoard] = [false, false];
        [firstCard, secondCard] = [null, null];
    }

    function incrementMoves() {
        moves++; movesDisplay.textContent = moves;
        if(moves >= LIMITE - 4) { movesDisplay.classList.remove('text-white'); movesDisplay.classList.add('limit-warning'); }
        if(moves >= LIMITE) {
            // trava imediatamente e força salvamento/derrota
            lockBoard = true;
            cards.forEach(c => c.removeEventListener('click', flipCard));
            if(timerInterval) clearInterval(timerInterval);
            saveGameState();
        }
    }

    function saveGameState() {
        let encontrados = [];
        document.querySelectorAll('.card-game.matched').forEach(c => encontrados.push(parseInt(c.dataset.id)));
        const formData = new FormData();
        formData.append('acao', 'atualizar_estado');
        formData.append('movimentos', moves);
        formData.append('tempo', seconds);
        formData.append('pares_encontrados', JSON.stringify(encontrados));

        fetch('index.php?game=memoria', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if(data.status === 'venceu') setTimeout(() => location.reload(), 500);
            else if (data.status === 'perdeu') setTimeout(() => location.reload(), 500);
        });
    }
</script>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
