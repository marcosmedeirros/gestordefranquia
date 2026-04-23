<?php
// pinguim.php - CORRIDA DO PINGUIM RADICAL (DARK MODE 🐧🛹)
// VERSÃO: PROTEGIDA CONTRA CONSOLE DEVTOOLS
ini_set('display_errors', 1);
error_reporting(E_ALL);
// session_start já foi chamado em games/index.php
require '../core/conexao.php';
require_once '../core/mobile-helpers.php';

// 1. Segurança Básica
if (!isset($_SESSION['user_id'])) { header("Location: auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];
$hiddenRankingEmailLower = 'medeirros99@gmail.com';
$pointsMultiplier = getGamePointsMultiplier($pdo, 'pinguim');

// --- AUTOMATIZAÇÃO DO BANCO DE DADOS PARA SKINS ---
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

    $stmtRank = $pdo->prepare("
        SELECT u.nome, MAX(d.pontuacao_final) as recorde
        FROM dino_historico d
        JOIN usuarios u ON d.id_usuario = u.id
        WHERE LOWER(u.email) <> :hidden_email
        GROUP BY d.id_usuario
        ORDER BY recorde DESC
        LIMIT 5
    ");
    $stmtRank->execute([':hidden_email' => $hiddenRankingEmailLower]);
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

// --- API AJAX ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');

    $run_active = isset($_SESSION['pinguim_run_active']) && $_SESSION['pinguim_run_active'] === true;
    $run_start = isset($_SESSION['pinguim_run_start']) ? (int)$_SESSION['pinguim_run_start'] : 0;
    $last_score = isset($_SESSION['pinguim_last_score']) ? (int)$_SESSION['pinguim_last_score'] : 0;
    $last_milestone = isset($_SESSION['pinguim_last_milestone']) ? (int)$_SESSION['pinguim_last_milestone'] : 0;

    $validate_run_score = function($score) use ($run_active, $run_start, $last_score) {
        if (!$run_active || $run_start <= 0) {
            throw new Exception('Sessão de jogo inválida.');
        }
        $elapsed = max(0, time() - $run_start);
        $max_score = (int)($elapsed * 20) + 50;
        if ($score < $last_score) {
            throw new Exception('Score inválido.');
        }
        if ($score > $max_score) {
            throw new Exception('Score acima do permitido.');
        }
    };

    // 0. INICIAR RUN
    if ($_POST['acao'] == 'iniciar_run') {
        $_SESSION['pinguim_run_active'] = true;
        $_SESSION['pinguim_run_start'] = time();
        $_SESSION['pinguim_last_score'] = 0;
        $_SESSION['pinguim_last_milestone'] = 0;
        $_SESSION['pinguim_revive_used'] = false;
        echo json_encode(['sucesso' => true]);
        exit;
    }

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

    // C. SALVAR PONTOS (Milestone dinâmico)
    if ($_POST['acao'] == 'salvar_milestone') {
        $score_atual = isset($_POST['score']) ? (int)$_POST['score'] : 0;
        try {
            $validate_run_score($score_atual);

            $novo_milestone = (int)floor($score_atual / 100);
            if ($novo_milestone < $last_milestone) {
                throw new Exception('Milestone inválido.');
            }

            $creditado = 0;
            if ($novo_milestone > $last_milestone) {
                for ($m = $last_milestone + 1; $m <= $novo_milestone; $m++) {
                    $milestone_score = $m * 100;
                    // Moedas por milestone
                    $coins_per_100 = (1 + (int)floor($milestone_score / 500)) * $pointsMultiplier;
                    $creditado += $coins_per_100;
                }

                $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :uid")
                    ->execute([':val' => $creditado, ':uid' => $user_id]);

                $_SESSION['pinguim_last_milestone'] = $novo_milestone;
            }

            $_SESSION['pinguim_last_score'] = max($last_score, $score_atual);

            $stmtSaldo = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id");
            $stmtSaldo->execute([':id' => $user_id]);
            $novo_saldo = (int)$stmtSaldo->fetchColumn();

            echo json_encode(['sucesso' => true, 'creditado' => $creditado, 'novo_saldo' => $novo_saldo]);
        } catch (Exception $e) {
            echo json_encode(['erro' => $e->getMessage()]);
        }
        exit;
    }

    // D. REVIVER
    if ($_POST['acao'] == 'gastar_moedas_reviver') {
        $custo = 10;
        try {
            if (!$run_active) throw new Exception('Sessão de jogo inválida.');
            if (!empty($_SESSION['pinguim_revive_used'])) throw new Exception('Revive já utilizado.');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $user_id]);
            $saldo = $stmt->fetchColumn();
            if ($saldo < $custo) throw new Exception("Saldo insuficiente.");
            $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :id")->execute([':val' => $custo, ':id' => $user_id]);
            $pdo->commit();
            $_SESSION['pinguim_revive_used'] = true;
            echo json_encode(['sucesso' => true, 'novo_saldo' => $saldo - $custo]);
        } catch (Exception $e) { $pdo->rollBack(); echo json_encode(['erro' => $e->getMessage()]); }
        exit;
    }

    // E. SALVAR SCORE
    if ($_POST['acao'] == 'salvar_score') {
        $score_final = (int)$_POST['score'];
        try {
            $validate_run_score($score_final);
            $pdo->prepare("INSERT INTO dino_historico (id_usuario, pontuacao_final, pontos_ganhos) VALUES (:uid, :score, 0)")
                ->execute([':uid' => $user_id, ':score' => $score_final]);
        } catch (PDOException $ex) { }
        $_SESSION['pinguim_last_score'] = $score_final;
        $_SESSION['pinguim_run_active'] = false;
        echo json_encode(['sucesso' => true]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Pinguim Run 🐧</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🐧</text></svg>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:      #08080f;
    --panel:   #111118;
    --panel2:  #18181f;
    --border:  #26263a;
    --accent:  #fc0025;
    --gold:    #f59e0b;
    --green:   #22c55e;
    --text:    #e8e8f0;
    --muted:   #64648a;
}

html, body {
    height: 100%;
    background: var(--bg);
    color: var(--text);
    font-family: 'Segoe UI', system-ui, sans-serif;
    overscroll-behavior: none;
}

/* ── PAGE LAYOUT ─────────────────────────────────────────────────────────── */
.game-page {
    display: flex;
    flex-direction: column;
    height: 100dvh;
    overflow: hidden;
}

/* ── TOP BAR ─────────────────────────────────────────────────────────────── */
.top-bar {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    background: var(--panel);
    border-bottom: 1px solid var(--border);
    gap: 8px;
    z-index: 50;
}
.top-bar-left { display: flex; align-items: center; gap: 8px; min-width: 0; }
.player-name {
    font-weight: 700; font-size: .88rem;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 110px;
}
.back-btn {
    display: inline-flex; align-items: center; gap: 4px;
    background: transparent; border: 1px solid var(--border); border-radius: 8px;
    color: var(--text); padding: 5px 10px; font-size: .78rem; cursor: pointer;
    text-decoration: none; white-space: nowrap; transition: border-color .15s;
}
.back-btn:hover { border-color: #404060; }
.coin-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.28);
    border-radius: 999px; padding: 4px 12px;
    font-weight: 800; font-size: .88rem; color: var(--gold); white-space: nowrap;
}

/* ── HUD ─────────────────────────────────────────────────────────────────── */
.hud {
    flex-shrink: 0;
    display: flex; align-items: center; justify-content: space-between;
    padding: 5px 14px;
    background: var(--panel2);
    border-bottom: 1px solid var(--border);
    font-family: 'Courier New', monospace;
}
.hud-item { display: flex; flex-direction: column; align-items: center; gap: 1px; }
.hud-label { font-size: .6rem; font-weight: 700; letter-spacing: .8px; color: var(--muted); text-transform: uppercase; }
.hud-val   { font-size: .95rem; font-weight: 800; color: #fff; }
.hud-val.gold  { color: var(--gold); }
.hud-val.green { color: var(--green); }

/* ── CANVAS AREA ─────────────────────────────────────────────────────────── */
.canvas-area {
    flex: 1;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #06060e;
    touch-action: none;
}

#gameCanvas {
    display: block;
    max-width: 100%;
    height: auto;
    image-rendering: pixelated;
}

/* ── FLOATING COINS ──────────────────────────────────────────────────────── */
.floating-text {
    position: absolute;
    font-weight: 800; font-size: 1rem;
    color: var(--gold);
    text-shadow: 0 0 10px rgba(245,158,11,.5);
    pointer-events: none;
    animation: floatUp .85s ease-out forwards;
    z-index: 5;
    white-space: nowrap;
}
@keyframes floatUp {
    0%   { opacity: 1; transform: translateY(0) scale(1); }
    100% { opacity: 0; transform: translateY(-52px) scale(1.15); }
}

/* ── JUMP BUTTON (touch devices) ─────────────────────────────────────────── */
.jump-btn {
    position: absolute;
    bottom: 18px; right: 18px;
    width: 74px; height: 74px;
    border-radius: 50%;
    background: rgba(252,0,37,.12);
    border: 2px solid rgba(252,0,37,.4);
    color: #fff; font-size: 2rem;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; z-index: 10;
    user-select: none; -webkit-tap-highlight-color: transparent;
    transition: background .1s, transform .08s;
    box-shadow: 0 0 20px rgba(252,0,37,.15);
}
.jump-btn:active { background: rgba(252,0,37,.3); transform: scale(.9); }
/* hide on desktop (mouse/hover capable) */
@media (hover: hover) and (pointer: fine) { .jump-btn { display: none; } }

/* ── OVERLAYS ────────────────────────────────────────────────────────────── */
.overlay {
    position: absolute; inset: 0;
    background: rgba(6,6,14,.85);
    backdrop-filter: blur(6px);
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    z-index: 20; padding: 16px;
    overflow-y: auto;
}
.overlay.hidden { display: none; }

.ov-card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 24px 20px;
    width: 100%; max-width: 330px;
    text-align: center;
}
.ov-emoji  { font-size: 3rem; margin-bottom: 6px; line-height: 1; }
.ov-title  { font-size: 1.35rem; font-weight: 900; margin-bottom: 4px; }
.ov-sub    { font-size: .82rem; color: var(--muted); margin-bottom: 16px; }
.ov-score  { font-size: 2.8rem; font-weight: 900; font-family: 'Courier New', monospace; color: #fff; line-height: 1; }
.ov-score-label { font-size: .68rem; font-weight: 700; letter-spacing: 1px; color: var(--muted); text-transform: uppercase; margin-bottom: 18px; }

.btn-primary-action {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; padding: 13px;
    background: var(--accent); border: none; border-radius: 12px;
    color: #fff; font-weight: 800; font-size: 1rem;
    cursor: pointer; margin-bottom: 8px; transition: opacity .15s;
}
.btn-primary-action:hover { opacity: .88; }
.btn-primary-action:disabled { opacity: .35; cursor: not-allowed; }

.btn-ghost-action {
    display: flex; align-items: center; justify-content: center; gap: 7px;
    width: 100%; padding: 11px;
    background: transparent; border: 1px solid var(--border); border-radius: 12px;
    color: var(--text); font-weight: 700; font-size: .88rem;
    cursor: pointer; margin-bottom: 8px; transition: border-color .15s;
}
.btn-ghost-action:hover { border-color: #404060; }

.btn-revive-action {
    display: flex; align-items: center; justify-content: center; gap: 7px;
    width: 100%; padding: 11px;
    background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.3);
    border-radius: 12px; color: var(--gold);
    font-weight: 700; font-size: .88rem;
    cursor: pointer; margin-bottom: 8px; transition: background .15s;
}
.btn-revive-action:hover { background: rgba(245,158,11,.18); }

/* ── RANKING inside start overlay ───────────────────────────────────────── */
.rank-mini {
    margin-top: 14px;
    border-top: 1px solid var(--border);
    padding-top: 12px;
    text-align: left;
}
.rank-mini-title {
    font-size: .65rem; font-weight: 700; letter-spacing: .8px;
    color: var(--muted); text-transform: uppercase; margin-bottom: 8px;
}
.rank-row {
    display: flex; align-items: center; gap: 6px;
    padding: 4px 0; font-size: .8rem;
}
.rank-pos { color: var(--muted); font-weight: 700; min-width: 22px; }
.rank-pos.g1 { color: #ffd700; }
.rank-pos.g2 { color: #c0c0c0; }
.rank-pos.g3 { color: #cd7f32; }
.rank-name { flex: 1; }
.rank-score { color: var(--gold); font-weight: 800; font-family: monospace; font-size: .82rem; }

/* ── SHOP OVERLAY ────────────────────────────────────────────────────────── */
.shop-overlay {
    position: absolute; inset: 0;
    background: rgba(6,6,14,.92);
    z-index: 30;
    display: flex; flex-direction: column;
    padding: 16px;
    overflow-y: auto;
}
.shop-overlay.hidden { display: none; }
.shop-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px;
}
.shop-title { font-size: 1.05rem; font-weight: 800; }
.btn-close {
    width: 36px; height: 36px; border-radius: 50%;
    background: var(--panel2); border: 1px solid var(--border);
    color: var(--text); display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: .95rem; transition: border-color .15s;
}
.btn-close:hover { border-color: #404060; }

.skin-card {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 12px;
    background: var(--panel2); border: 1px solid var(--border);
    border-radius: 12px; margin-bottom: 8px;
    transition: border-color .15s;
}
.skin-card:last-child { margin-bottom: 0; }
.skin-emoji { font-size: 2rem; flex-shrink: 0; }
.skin-info  { flex: 1; text-align: left; }
.skin-name  { font-weight: 700; font-size: .88rem; }
.skin-price { font-size: .75rem; color: var(--gold); font-weight: 700; margin-top: 1px; }
.skin-owned { font-size: .75rem; color: var(--green); margin-top: 1px; }
.btn-skin {
    padding: 6px 14px; border-radius: 8px;
    font-size: .78rem; font-weight: 700;
    cursor: pointer; border: none; white-space: nowrap;
    flex-shrink: 0;
}
.btn-skin.buy      { background: var(--green); color: #000; }
.btn-skin.equip    { background: var(--accent); color: #fff; }
.btn-skin.equipped { background: var(--panel); color: var(--muted); border: 1px solid var(--border); cursor: default; }
</style>
</head>
<body>
<div class="game-page">

    <!-- TOP BAR -->
    <div class="top-bar">
        <div class="top-bar-left">
            <a href="../index.php" class="back-btn">
                <i class="bi bi-arrow-left"></i> Voltar
            </a>
            <span class="player-name"><?= htmlspecialchars($meu_perfil['nome']) ?></span>
        </div>
        <div class="coin-badge">
            🪙 <span id="saldoDisplay"><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?></span>
        </div>
    </div>

    <!-- HUD -->
    <div class="hud">
        <div class="hud-item">
            <span class="hud-label">Recorde</span>
            <span class="hud-val gold" id="hudHi">0</span>
        </div>
        <div class="hud-item">
            <span class="hud-label">Setor</span>
            <span class="hud-val" id="hudBiome">PDSA</span>
        </div>
        <div class="hud-item">
            <span class="hud-label">Metros</span>
            <span class="hud-val green" id="hudScore">0</span>
        </div>
    </div>

    <!-- CANVAS AREA -->
    <div class="canvas-area" id="canvasArea">
        <canvas id="gameCanvas" width="800" height="300"></canvas>

        <!-- Mobile jump button -->
        <button class="jump-btn" id="jumpBtn" aria-label="Pular">🐧</button>

        <!-- ── START OVERLAY ── -->
        <div class="overlay" id="overlayStart">
            <div class="ov-card">
                <div class="ov-emoji">🐧🛹</div>
                <div class="ov-title">PINGUIM SKATER</div>
                <div class="ov-sub">Pule os obstáculos e ganhe moedas!<br><small>Toque duas vezes para pulo duplo ✌️</small></div>
                <button class="btn-primary-action" id="btnPlay">
                    <i class="bi bi-play-fill"></i> JOGAR
                </button>
                <button class="btn-ghost-action" id="btnOpenShop">
                    <i class="bi bi-bag-fill"></i> Loja de Skins
                </button>
                <?php if (!empty($ranking_dino)): ?>
                <div class="rank-mini">
                    <div class="rank-mini-title">🏆 Top Corredores</div>
                    <?php foreach ($ranking_dino as $idx => $r):
                        $medal = $idx === 0 ? 'g1' : ($idx === 1 ? 'g2' : ($idx === 2 ? 'g3' : ''));
                        $medalStr = $idx === 0 ? '🥇' : ($idx === 1 ? '🥈' : ($idx === 2 ? '🥉' : '#'.($idx+1)));
                    ?>
                    <div class="rank-row">
                        <span class="rank-pos <?= $medal ?>"><?= $medalStr ?></span>
                        <span class="rank-name"><?= htmlspecialchars($r['nome']) ?></span>
                        <span class="rank-score"><?= number_format($r['recorde'], 0, ',', '.') ?>m</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── GAME OVER OVERLAY ── -->
        <div class="overlay hidden" id="overlayGameover">
            <div class="ov-card">
                <div class="ov-emoji" id="goEmoji">💀</div>
                <div class="ov-title" id="goTitle">FIM DE JOGO</div>
                <div class="ov-score" id="goScore">0</div>
                <div class="ov-score-label">metros percorridos</div>
                <button class="btn-primary-action" id="btnRestart">
                    <i class="bi bi-arrow-clockwise"></i> Jogar Novamente
                </button>
                <button class="btn-revive-action hidden" id="btnRevive">
                    🪙 Reviver por 10 moedas
                </button>
                <button class="btn-ghost-action" id="btnGoHome">
                    <i class="bi bi-house"></i> Menu
                </button>
            </div>
        </div>

        <!-- ── SHOP OVERLAY ── -->
        <div class="shop-overlay hidden" id="shopOverlay">
            <div class="shop-header">
                <span class="shop-title">🛍️ Loja de Skins</span>
                <button class="btn-close" id="btnCloseShop"><i class="bi bi-x-lg"></i></button>
            </div>

            <div class="skin-card">
                <span class="skin-emoji">🐧</span>
                <div class="skin-info">
                    <div class="skin-name">Padrão</div>
                    <div class="skin-owned">Incluso gratuitamente</div>
                </div>
                <?php if ($meu_perfil['skin_equipada'] == 'default'): ?>
                <button class="btn-skin equipped">Equipado</button>
                <?php else: ?>
                <button class="btn-skin equip btn-equip-skin" data-skin="default">Equipar</button>
                <?php endif; ?>
            </div>

            <?php foreach ($catalogo_skins as $key => $data):
                $tenho   = in_array($key, $minhas_skins);
                $equipado = ($meu_perfil['skin_equipada'] == $key);
            ?>
            <div class="skin-card">
                <span class="skin-emoji"><?= $data['emoji'] ?></span>
                <div class="skin-info">
                    <div class="skin-name"><?= $data['nome'] ?></div>
                    <?php if (!$tenho): ?>
                    <div class="skin-price">🪙 <?= $data['preco'] ?> moedas</div>
                    <?php else: ?>
                    <div class="skin-owned"><i class="bi bi-check-circle-fill"></i> Desbloqueado</div>
                    <?php endif; ?>
                </div>
                <?php if ($equipado): ?>
                <button class="btn-skin equipped">Equipado</button>
                <?php elseif ($tenho): ?>
                <button class="btn-skin equip btn-equip-skin" data-skin="<?= $key ?>">Equipar</button>
                <?php else: ?>
                <button class="btn-skin buy btn-buy-skin" data-skin="<?= $key ?>" data-price="<?= $data['preco'] ?>">Comprar</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div><!-- /canvas-area -->

</div><!-- /game-page -->

<script>
(() => {
'use strict';

// ── DOM ───────────────────────────────────────────────────────────────────────
const canvas         = document.getElementById('gameCanvas');
const ctx            = canvas.getContext('2d');
const canvasArea     = document.getElementById('canvasArea');
const saldoEl        = document.getElementById('saldoDisplay');
const hudScore       = document.getElementById('hudScore');
const hudHi          = document.getElementById('hudHi');
const hudBiome       = document.getElementById('hudBiome');

const overlayStart   = document.getElementById('overlayStart');
const overlayGameover = document.getElementById('overlayGameover');
const shopOverlay    = document.getElementById('shopOverlay');

const btnPlay        = document.getElementById('btnPlay');
const btnRestart     = document.getElementById('btnRestart');
const btnRevive      = document.getElementById('btnRevive');
const btnGoHome      = document.getElementById('btnGoHome');
const btnOpenShop    = document.getElementById('btnOpenShop');
const btnCloseShop   = document.getElementById('btnCloseShop');
const jumpBtn        = document.getElementById('jumpBtn');
const goScore        = document.getElementById('goScore');
const goTitle        = document.getElementById('goTitle');
const goEmoji        = document.getElementById('goEmoji');

// ── Constants ─────────────────────────────────────────────────────────────────
const W  = 800;
const H  = 300;
const GY = 252; // ground Y
const GRAVITY    = 0.72;
const JUMP_FORCE = 13.5;
const REWARD_MUL = <?= (int)$pointsMultiplier ?>;

const BIOMES = [
    { name:'PDSA',       skyA:'#0d1b3e', skyB:'#7a0000', gnd:'#1a1a2e', line:'#c0392b' },
    { name:'PNIP',       skyA:'#1a0633', skyB:'#6b2280', gnd:'#200c3c', line:'#8e44ad' },
    { name:'SIGBS',      skyA:'#002244', skyB:'#155fa0', gnd:'#082808', line:'#27ae60' },
    { name:'BOLICHEIRO', skyA:'#1a0000', skyB:'#8b1a2a', gnd:'#200000', line:'#e74c3c' },
];

const SKINS = {
    'default': { emoji:'🐧', body:'#000',    belly:'#fff'    },
    'porco':   { emoji:'🐷', body:'#f48fb1', belly:'#f8bbd0' },
    'peixe':   { emoji:'🐟', body:'#039be5', belly:'#4fc3f7' },
    'galinha': { emoji:'🐔', body:'#eee',    belly:'#fff'    },
    'boi':     { emoji:'🐂', body:'#5d4037', belly:'#8d6e63' },
    'morcego': { emoji:'🦇', body:'#212121', belly:'#424242' },
};

const OBSTACLE_TYPES = ['☕','💻','📦','🧱','🪤'];

// ── State ─────────────────────────────────────────────────────────────────────
let currentSkin  = '<?= $meu_perfil['skin_equipada'] ?>';
let currentSaldo = <?= $meu_perfil['pontos'] ?>;
let highScore    = parseInt(localStorage.getItem('pingHI') || '0', 10);
hudHi.textContent = highScore;

let gameState = 'idle'; // idle | playing | dead
let score, speed, biomeIdx, bgX, nextReward, hasRevived, animId;
let dino, obstacles, spawnTimer, squashTimer;

// Parallax stars
const stars = Array.from({length: 45}, () => ({
    x: Math.random() * W,
    y: Math.random() * (GY - 30),
    r: Math.random() * 1.4 + 0.4,
    s: Math.random() * 0.15 + 0.05,
}));

// ── Canvas scaling ────────────────────────────────────────────────────────────
function getScale() { return canvas.clientWidth / W; }

function fitCanvas() {
    const r   = canvasArea.getBoundingClientRect();
    const asp = W / H;
    let w = r.width, h = w / asp;
    if (h > r.height) { h = r.height; w = h * asp; }
    canvas.style.width  = Math.floor(w) + 'px';
    canvas.style.height = Math.floor(h) + 'px';
}
window.addEventListener('resize', fitCanvas);
fitCanvas();

// ── Input ─────────────────────────────────────────────────────────────────────
function tryJump() {
    if (gameState !== 'playing') return;
    if (dino.jumps > 0) {
        dino.dy     = -JUMP_FORCE * (dino.jumps === 1 ? 0.82 : 1);
        dino.grounded = false;
        dino.jumps--;
    }
}

document.addEventListener('keydown', e => {
    if (e.code === 'Space' || e.code === 'ArrowUp') { e.preventDefault(); tryJump(); }
});

// Tap anywhere on canvas area = jump (when playing)
canvasArea.addEventListener('touchstart', e => {
    if (gameState === 'playing') { e.preventDefault(); tryJump(); }
}, { passive: false });

canvasArea.addEventListener('mousedown', () => {
    if (gameState === 'playing') tryJump();
});

// Dedicated jump button
jumpBtn.addEventListener('touchstart', e => { e.preventDefault(); e.stopPropagation(); tryJump(); }, { passive: false });
jumpBtn.addEventListener('mousedown',  e => { e.stopPropagation(); tryJump(); });

// ── Buttons ───────────────────────────────────────────────────────────────────
btnPlay.addEventListener('click', startGame);
btnRestart.addEventListener('click', startGame);
btnRevive.addEventListener('click', doRevive);
btnGoHome.addEventListener('click', () => {
    overlayGameover.classList.add('hidden');
    overlayStart.classList.remove('hidden');
    drawIdle();
});
btnOpenShop.addEventListener('click', openShop);
btnCloseShop.addEventListener('click', closeShop);

document.querySelectorAll('.btn-buy-skin').forEach(b =>
    b.addEventListener('click', () => comprarSkin(b.dataset.skin, parseInt(b.dataset.price, 10)))
);
document.querySelectorAll('.btn-equip-skin').forEach(b =>
    b.addEventListener('click', () => equiparSkin(b.dataset.skin))
);

// ── Shop ──────────────────────────────────────────────────────────────────────
function openShop()  { shopOverlay.classList.remove('hidden'); overlayStart.classList.add('hidden'); }
function closeShop() { shopOverlay.classList.add('hidden'); if (gameState === 'idle') overlayStart.classList.remove('hidden'); }

function comprarSkin(key, preco) {
    if (currentSaldo < preco) { alert('Moedas insuficientes!'); return; }
    if (!confirm(`Comprar skin por ${preco} moedas?`)) return;
    const fd = new FormData(); fd.append('acao','comprar_skin'); fd.append('skin', key);
    fetch('index.php?game=pinguim', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => { if (d.sucesso) location.reload(); else alert(d.erro); });
}

function equiparSkin(key) {
    const fd = new FormData(); fd.append('acao','equipar_skin'); fd.append('skin', key);
    fetch('index.php?game=pinguim', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => { if (d.sucesso) location.reload(); else alert(d.erro); });
}

// ── Game lifecycle ────────────────────────────────────────────────────────────
function startGame() {
    btnPlay.disabled = true;
    const fd = new FormData(); fd.append('acao','iniciar_run');
    fetch('index.php?game=pinguim', { method:'POST', body:fd }).then(() => {
        reset();
        gameState = 'playing';
        overlayStart.classList.add('hidden');
        overlayGameover.classList.add('hidden');
        btnPlay.disabled = false;
        loop();
    });
}

function reset() {
    cancelAnimationFrame(animId);
    score       = 0;
    speed       = 7;
    biomeIdx    = 0;
    bgX         = 0;
    nextReward  = 100;
    hasRevived  = false;
    obstacles   = [];
    spawnTimer  = 60;
    squashTimer = 0;
    dino = { x:70, y:GY - 40, w:38, h:40, dy:0, grounded:true, jumps:2 };
    hudScore.textContent = '0';
    hudBiome.textContent = BIOMES[0].name;
}

function spawnObstacle() {
    const type = OBSTACLE_TYPES[Math.floor(Math.random() * OBSTACLE_TYPES.length)];
    const h    = Math.random() * 30 + 26;
    obstacles.push({ x: W + 20, y: GY - h + 2, w: 26, h, type });
}

// ── Draw helpers ──────────────────────────────────────────────────────────────
function drawBg(biome) {
    // Sky
    const g = ctx.createLinearGradient(0, 0, 0, GY);
    g.addColorStop(0, biome.skyA);
    g.addColorStop(1, biome.skyB);
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, H);

    // Stars
    ctx.fillStyle = 'rgba(255,255,255,.55)';
    for (const s of stars) {
        ctx.beginPath();
        ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
        ctx.fill();
        s.x -= s.s * speed * 0.14;
        if (s.x < 0) { s.x = W; s.y = Math.random() * (GY - 30); }
    }

    // Mountains (parallax)
    bgX -= speed * 0.22;
    if (bgX <= -W) bgX = 0;
    ctx.fillStyle = 'rgba(0,0,0,.22)';
    ctx.beginPath();
    for (let i = 0; i < 2; i++) {
        const o = bgX + i * W;
        ctx.moveTo(o, GY);
        ctx.lineTo(o+150, 165); ctx.lineTo(o+300, GY);
        ctx.lineTo(o+460, 150); ctx.lineTo(o+620, 140); ctx.lineTo(o+800, GY);
    }
    ctx.fill();

    // Ground fill
    ctx.fillStyle = biome.gnd;
    ctx.fillRect(0, GY, W, H - GY);

    // Ground line
    ctx.fillStyle = biome.line;
    ctx.fillRect(0, GY, W, 3);

    // Speed dashes
    ctx.strokeStyle = 'rgba(255,255,255,.06)';
    ctx.lineWidth   = 1;
    for (let i = 0; i < 7; i++) {
        const lx = ((bgX * 2.2 + i * 115) % W + W) % W;
        ctx.beginPath(); ctx.moveTo(lx, GY + 8); ctx.lineTo(lx + 55, GY + 8); ctx.stroke();
    }
}

function drawDino() {
    const { x, y, w, h } = dino;
    const skin = SKINS[currentSkin] || SKINS['default'];
    ctx.save();

    // Ground shadow
    const sqFactor = squashTimer > 0 ? 1.3 : 1;
    ctx.fillStyle = 'rgba(0,0,0,.25)';
    ctx.beginPath();
    ctx.ellipse(x + w/2, GY + 2, (w/2) * sqFactor, 4, 0, 0, Math.PI * 2);
    ctx.fill();

    // Skateboard
    ctx.fillStyle = '#795548';
    ctx.beginPath();
    ctx.ellipse(x + w/2, y + h + 5, w/1.8, 5, 0, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = '#ffd54f';
    for (const wx of [x + 9, x + w - 9]) {
        ctx.beginPath(); ctx.arc(wx, y + h + 9, 4, 0, Math.PI * 2); ctx.fill();
    }

    // Body (squash on land)
    const sy = squashTimer > 0 ? 1.25 : 1;
    const sx = squashTimer > 0 ? 0.82 : 1;
    ctx.save();
    ctx.translate(x + w/2, y + h/2);
    ctx.scale(sx, sy);
    ctx.fillStyle = skin.body;
    ctx.beginPath(); ctx.ellipse(0, 0, w/2, h/2, 0, 0, Math.PI * 2); ctx.fill();
    // Belly
    ctx.fillStyle = skin.belly;
    ctx.beginPath(); ctx.ellipse(2, 5, w/3, h/2.8, 0, 0, Math.PI * 2); ctx.fill();
    ctx.restore();

    // Wing
    ctx.fillStyle = skin.body;
    ctx.beginPath(); ctx.ellipse(x + 10, y + h/2 + 5, 5, 11, 0.5, 0, Math.PI * 2); ctx.fill();

    // Head / face
    if (currentSkin === 'default') {
        ctx.fillStyle = '#fff';
        ctx.beginPath(); ctx.arc(x + w/2 + 5, y + 10, 6, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = '#111';
        ctx.beginPath(); ctx.arc(x + w/2 + 7, y + 10, 2.5, 0, Math.PI * 2); ctx.fill();
        // Beak
        ctx.fillStyle = '#FF9800';
        ctx.beginPath();
        ctx.moveTo(x + w - 4, y + 15); ctx.lineTo(x + w + 6, y + 18); ctx.lineTo(x + w - 4, y + 21);
        ctx.fill();
    } else {
        ctx.font = '27px Arial';
        ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
        ctx.save();
        ctx.translate(x + w/2 + 5, y + 10);
        ctx.scale(-1, 1);
        ctx.fillText(skin.emoji, 0, 0);
        ctx.restore();
    }
    ctx.restore();
}

function drawObstacle(o) {
    ctx.font         = `${o.h}px Arial`;
    ctx.textAlign    = 'left';
    ctx.textBaseline = 'top';
    ctx.shadowColor  = 'rgba(0,0,0,.6)';
    ctx.shadowBlur   = 5;
    ctx.fillText(o.type, o.x, o.y);
    ctx.shadowBlur   = 0;
}

function collides(o) {
    const m = 8;
    return dino.x + m < o.x + o.w &&
           dino.x + dino.w - m > o.x &&
           dino.y + m < o.y + o.h &&
           dino.y + dino.h - m > o.y;
}

// ── Main loop ─────────────────────────────────────────────────────────────────
function loop() {
    animId = requestAnimationFrame(loop);

    const biome = BIOMES[biomeIdx % BIOMES.length];
    drawBg(biome);

    // Physics
    dino.dy += GRAVITY;
    dino.y  += dino.dy;
    if (dino.y + dino.h >= GY) {
        dino.y        = GY - dino.h;
        dino.dy       = 0;
        if (!dino.grounded) squashTimer = 5;
        dino.grounded = true;
        dino.jumps    = 2;
    }
    if (squashTimer > 0) squashTimer--;

    drawDino();

    // Obstacles
    spawnTimer--;
    if (spawnTimer <= 0) {
        spawnObstacle();
        spawnTimer = Math.max(32, 56 + Math.random() * 70 - speed * 2.5);
    }
    for (let i = obstacles.length - 1; i >= 0; i--) {
        const o = obstacles[i];
        o.x -= speed;
        drawObstacle(o);
        if (collides(o)) { gameOver(); return; }
        if (o.x + o.w < -20) obstacles.splice(i, 1);
    }

    // Score
    score += 0.25;
    speed += 0.003;
    const intScore = Math.floor(score);
    hudScore.textContent = intScore;

    if (intScore > highScore) {
        highScore = intScore;
        localStorage.setItem('pingHI', highScore);
        hudHi.textContent = highScore;
    }

    // Biome change every 1000m
    const nb = Math.floor(intScore / 1000);
    if (nb !== biomeIdx) { biomeIdx = nb; hudBiome.textContent = BIOMES[biomeIdx % BIOMES.length].name; }

    // Coin milestones
    while (intScore >= nextReward) {
        const coins = (1 + Math.floor(intScore / 500)) * REWARD_MUL;
        grantCoins(coins, intScore);
        nextReward += 100;
    }
}

// ── Game over ─────────────────────────────────────────────────────────────────
function gameOver() {
    gameState = 'dead';
    cancelAnimationFrame(animId);
    const final = Math.floor(score);
    saveFinalScore(final);
    goScore.textContent = final;

    if (!hasRevived && currentSaldo >= 10) {
        goEmoji.textContent  = '🤕';
        goTitle.textContent  = 'BATIDA FEIA!';
        btnRevive.classList.remove('hidden');
    } else {
        goEmoji.textContent  = '💀';
        goTitle.textContent  = 'FIM DE JOGO';
        btnRevive.classList.add('hidden');
    }
    overlayGameover.classList.remove('hidden');
}

function doRevive() {
    const fd = new FormData(); fd.append('acao','gastar_moedas_reviver');
    fetch('index.php?game=pinguim', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => {
            if (d.sucesso) {
                updateSaldo(d.novo_saldo);
                hasRevived    = true;
                gameState     = 'playing';
                obstacles     = [];
                spawnTimer    = 70;
                dino.y        = GY - dino.h;
                dino.dy       = 0;
                dino.grounded = true;
                dino.jumps    = 2;
                overlayGameover.classList.add('hidden');
                floatText('-10 🪙', dino.x + 20, dino.y - 30);
                loop();
            } else { alert(d.erro); }
        });
}

// ── Coins & score save ────────────────────────────────────────────────────────
function grantCoins(amount, curScore) {
    floatText(`+${amount} 🪙`, dino.x + 20, dino.y - 40);
    const fd = new FormData(); fd.append('acao','salvar_milestone'); fd.append('score', curScore);
    fetch('index.php?game=pinguim', { method:'POST', body:fd })
        .then(r => r.json())
        .then(d => { if (d && d.novo_saldo) updateSaldo(d.novo_saldo); });
}

function saveFinalScore(s) {
    const fd = new FormData(); fd.append('acao','salvar_score'); fd.append('score', s);
    fetch('index.php?game=pinguim', { method:'POST', body:fd });
}

function updateSaldo(v) {
    currentSaldo = v;
    saldoEl.textContent = v.toLocaleString('pt-BR');
}

// ── Floating text ─────────────────────────────────────────────────────────────
function floatText(text, lx, ly) {
    const scale    = getScale();
    const cRect    = canvas.getBoundingClientRect();
    const aRect    = canvasArea.getBoundingClientRect();
    const el       = document.createElement('div');
    el.className   = 'floating-text';
    el.textContent = text;
    el.style.left  = (cRect.left - aRect.left + lx * scale) + 'px';
    el.style.top   = (cRect.top  - aRect.top  + ly * scale) + 'px';
    canvasArea.appendChild(el);
    setTimeout(() => el.remove(), 900);
}

// ── Idle draw (initial render) ────────────────────────────────────────────────
function drawIdle() {
    score = 0; speed = 7; biomeIdx = 0; bgX = 0;
    dino  = { x:70, y:GY - 40, w:38, h:40, dy:0, grounded:true, jumps:2 };
    obstacles = [];
    for (const s of stars) { s.x = Math.random() * W; s.y = Math.random() * (GY - 30); }
    drawBg(BIOMES[0]);
    drawDino();
    ctx.fillStyle = BIOMES[0].gnd;
    ctx.fillRect(0, GY, W, H - GY);
}
drawIdle();

})();
</script>
</body>
</html>
