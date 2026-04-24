<?php
// LIGA O MOSTRADOR DE ERROS (Remova em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// memoria.php - O JOGO DA MEMÓRIA RAM (COM PERSISTÊNCIA E DARK MODE 💾🌙) 🧠
// session_start já foi chamado em games/index.php
require '../core/conexao.php';

// --- CONFIGURACOES ---
$BASE_PONTOS_VITORIA = 100;
$pointsMultiplier = getGamePointsMultiplier($pdo, 'memoria');
$PONTOS_VITORIA = $BASE_PONTOS_VITORIA * $pointsMultiplier;
$LIMITE_MOVIMENTOS = 18; 

// Garantir colunas de sequência
try {
    $hasStreak = $pdo->query("SHOW COLUMNS FROM memoria_historico LIKE 'streak_count'")->rowCount() > 0;
    if (!$hasStreak) {
        $pdo->exec("ALTER TABLE memoria_historico ADD COLUMN streak_count INT DEFAULT 0 AFTER pontos_ganhos");
    }
} catch (Exception $e) {
}

// 1. Segurança
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];


// --- 2. DADOS DO USUÁRIO (PARA O HEADER) ---
try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro perfil: " . $e->getMessage());
}

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

$streak_atual = 0;
try {
    $stmtStreak = $pdo->prepare("SELECT data_jogo, streak_count FROM memoria_historico WHERE id_usuario = :uid ORDER BY data_jogo DESC LIMIT 1");
    $stmtStreak->execute([':uid' => $user_id]);
    $rowStreak = $stmtStreak->fetch(PDO::FETCH_ASSOC);
    if ($rowStreak) {
        $streak_atual = (int)($rowStreak['streak_count'] ?? 0);
    }
} catch (PDOException $e) {
    $streak_atual = 0;
}

$update_streak = function () use ($pdo, $user_id, $hoje, &$streak_atual) {
    $stmtToday = $pdo->prepare("SELECT streak_count FROM memoria_historico WHERE id_usuario = :uid AND data_jogo = :hoje LIMIT 1");
    $stmtToday->execute([':uid' => $user_id, ':hoje' => $hoje]);
    $todayRow = $stmtToday->fetch(PDO::FETCH_ASSOC);
    if ($todayRow && (int)($todayRow['streak_count'] ?? 0) > 0) {
        $streak_atual = (int)$todayRow['streak_count'];
        return;
    }

    $stmtPrev = $pdo->prepare("SELECT data_jogo, streak_count FROM memoria_historico WHERE id_usuario = :uid AND data_jogo < :hoje ORDER BY data_jogo DESC LIMIT 1");
    $stmtPrev->execute([':uid' => $user_id, ':hoje' => $hoje]);
    $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);
    $yesterday = date('Y-m-d', strtotime($hoje . ' -1 day'));
    $nova_streak = ($prev && $prev['data_jogo'] === $yesterday) ? ((int)$prev['streak_count'] + 1) : 1;
    $pdo->prepare("UPDATE memoria_historico SET streak_count = :streak WHERE id_usuario = :uid AND data_jogo = :hoje")
        ->execute([':streak' => $nova_streak, ':uid' => $user_id, ':hoje' => $hoje]);
    $streak_atual = $nova_streak;
};

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

                $pdo->prepare("UPDATE usuarios SET pontos = pontos + :pts WHERE id = :uid")
                    ->execute([':pts' => $pontos, ':uid' => $user_id]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
            }
        }

        if ($novo_status !== 'jogando') {
            try {
                $update_streak();
            } catch (Exception $e) {
            }
        }

        echo json_encode(['status' => $novo_status, 'movimentos' => $novos_movimentos]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Memória · FBA</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#07070a;--panel:#101013;--panel2:#16161a;--panel3:#1c1c21;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.14);
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);--red-glow:rgba(252,0,37,.25);
  --text:#f0f0f3;--text2:#868690;--text3:#3c3c44;
  --green:#22c55e;--amber:#f59e0b;--font:'Poppins',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;overflow-x:hidden}

.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.topbar-left{display:flex;align-items:center;gap:10px}
.back-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;font-size:14px;transition:.2s;flex-shrink:0}
.back-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.game-title{font-size:15px;font-weight:800;color:var(--text)}
.game-title span{color:var(--red)}
.daily-badge{display:inline-flex;align-items:center;gap:4px;font-size:8px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;padding:2px 8px;border-radius:999px;background:var(--red-soft);border:1px solid var(--red-glow);color:var(--red);margin-left:6px}
.topbar-right{display:flex;align-items:center;gap:6px}
.chip{display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:var(--panel2);border:1px solid var(--border);font-size:11px;font-weight:700;color:var(--text);white-space:nowrap}
.chip.fire{border-color:rgba(245,158,11,.3)!important;color:var(--amber)!important}

.main{max-width:520px;margin:0 auto;padding:16px 12px 60px}

.stats-row{display:flex;gap:8px;margin-bottom:16px}
.stat-pill{flex:1;background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:10px 8px;text-align:center}
.stat-pill .lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text2);margin-bottom:3px}
.stat-pill .val{font-size:18px;font-weight:800;color:var(--text)}

.memory-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;perspective:1000px}
.card-game{aspect-ratio:1;border-radius:12px;cursor:pointer;position:relative;transform-style:preserve-3d;transition:transform .45s}
.card-game.flip{transform:rotateY(180deg)}
.card-game.matched .card-back{background:rgba(252,0,37,.18)!important;border-color:rgba(252,0,37,.5)!important;box-shadow:0 0 14px rgba(252,0,37,.3)!important}
.card-face{width:100%;height:100%;position:absolute;backface-visibility:hidden;border-radius:12px;display:flex;align-items:center;justify-content:center;border:1px solid var(--border2)}
.card-front{background:var(--panel2);color:var(--text3);font-size:1.1rem;transform:rotateY(0deg)}
.card-back{background:var(--panel3);color:var(--text);font-size:2rem;transform:rotateY(180deg)}

.result-card{background:var(--panel);border:1px solid var(--border2);border-radius:18px;padding:28px 20px;text-align:center;margin:16px 0}
.result-icon{font-size:3.2rem;display:block;margin-bottom:10px}
.result-title{font-size:20px;font-weight:800;margin-bottom:6px}
.result-sub{font-size:13px;color:var(--text2);margin-bottom:18px;line-height:1.5}
.btn-back{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 24px;border-radius:12px;background:var(--panel2);border:1px solid var(--border2);color:var(--text2);font-family:var(--font);font-size:13px;font-weight:700;text-decoration:none;transition:all .2s;width:100%}
.btn-back:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}

@keyframes pulse{0%,100%{opacity:1}50%{opacity:.35}}
.warn{animation:pulse 1.1s infinite;color:var(--red)!important}

::-webkit-scrollbar{width:4px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border2);border-radius:2px}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <a href="../games.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
    <span class="game-title">🧠 <span>Memória</span><span class="daily-badge"><i class="bi bi-calendar3"></i>Diário</span></span>
  </div>
  <div class="topbar-right">
    <?php if ($streak_atual > 0): ?>
    <div class="chip fire"><i class="bi bi-fire"></i><?= $streak_atual ?></div>
    <?php endif; ?>
    <div class="chip"><i class="bi bi-coin" style="color:var(--amber)"></i><?= number_format($meu_perfil['pontos'],0,',','.') ?></div>
  </div>
</div>

<div class="main">

  <?php if($status_atual === 'venceu'): ?>
  <div class="result-card" style="border-color:rgba(34,197,94,.3)">
    <span class="result-icon">🧠🏆</span>
    <div class="result-title" style="color:var(--green)">Missão Cumprida!</div>
    <div class="result-sub">Completou em <strong style="color:var(--text)"><?= $dados_jogo['movimentos'] ?></strong> movimentos · <strong style="color:var(--green)">+<?= $PONTOS_VITORIA ?> moedas</strong></div>
    <a href="../games.php" class="btn-back"><i class="bi bi-arrow-left"></i>Voltar aos Jogos</a>
  </div>

  <?php elseif($status_atual === 'perdeu'): ?>
  <div class="result-card" style="border-color:rgba(252,0,37,.3)">
    <span class="result-icon">💥</span>
    <div class="result-title" style="color:var(--red)">Você perdeu!</div>
    <div class="result-sub">Atingiu o limite de <strong style="color:var(--text)"><?= $LIMITE_MOVIMENTOS ?></strong> movimentos sem completar. Tente amanhã!</div>
    <a href="../games.php" class="btn-back"><i class="bi bi-arrow-left"></i>Voltar aos Jogos</a>
  </div>

  <?php else: ?>

  <div class="stats-row">
    <div class="stat-pill">
      <div class="lbl"><i class="bi bi-stopwatch"></i> Tempo</div>
      <div class="val" id="timer"><?= $dados_jogo['tempo_segundos'] ?>s</div>
    </div>
    <div class="stat-pill">
      <div class="lbl"><i class="bi bi-arrow-repeat"></i> Movimentos</div>
      <div class="val <?= ($movimentos_atuais >= $LIMITE_MOVIMENTOS - 4) ? 'warn' : '' ?>" id="moves"><?= $movimentos_atuais ?></div>
    </div>
    <div class="stat-pill">
      <div class="lbl">Limite</div>
      <div class="val"><?= $LIMITE_MOVIMENTOS ?></div>
    </div>
  </div>

  <div class="memory-grid" id="grid">
    <?php foreach($tabuleiro_atual as $carta): ?>
    <div class="card-game <?= $carta['encontrado'] ? 'flip matched' : '' ?>"
         data-id="<?= $carta['id'] ?>"
         data-emoji="<?= $carta['emoji'] ?>"
         <?= $carta['encontrado'] ? 'style="pointer-events:none"' : '' ?>>
      <div class="card-face card-front"><i class="bi bi-cpu-fill"></i></div>
      <div class="card-face card-back"><?= $carta['emoji'] ?></div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php endif; ?>
</div>

<?php if($status_atual === 'jogando'): ?>
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
        timerInterval = setInterval(() => { seconds++; timerDisplay.textContent = seconds + 's'; }, 1000);
    }
    if(moves > 0) startTimer();

    function flipCard() {
        if(lockBoard || this === firstCard) return;
        startTimer();
        this.classList.add('flip');
        if(!hasFlippedCard) { hasFlippedCard = true; firstCard = this; return; }
        secondCard = this;
        incrementMoves();
        checkForMatch();
    }

    function checkForMatch() {
        firstCard.dataset.emoji === secondCard.dataset.emoji ? disableCards() : unflipCards();
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
        if(moves >= LIMITE - 4) movesDisplay.classList.add('warn');
        if(moves >= LIMITE) {
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
        .then(r => r.json())
        .then(data => {
            if(data.status === 'venceu' || data.status === 'perdeu') setTimeout(() => location.reload(), 500);
        });
    }
</script>
<?php endif; ?>
</body>
</html>
