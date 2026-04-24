<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
// session_start já foi chamado em games/index.php
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];
$hiddenRankingEmailLower = 'medeirros99@gmail.com';
$pointsMultiplier = getGamePointsMultiplier($pdo, 'flappy');

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

    $stmtRank = $pdo->prepare("SELECT u.nome, MAX(h.pontuacao) as recorde FROM flappy_historico h JOIN usuarios u ON h.id_usuario = u.id WHERE LOWER(u.email) <> :hidden_email GROUP BY h.id_usuario ORDER BY recorde DESC LIMIT 5");
    $stmtRank->execute([':hidden_email' => $hiddenRankingEmailLower]);
    $ranking_flappy = $stmtRank->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { die("Erro DB: " . $e->getMessage()); }

$catalogo_skins = [
    'azul'     => ['nome' => 'Azulão',    'cor' => '#29b6f6', 'preco' => 10,  'desc' => 'Clássico Azul',     'emoji' => '🔵'],
    'vermelho' => ['nome' => 'Red Bird',  'cor' => '#ef5350', 'preco' => 20,  'desc' => 'Rápido e Furioso',  'emoji' => '🔴'],
    'verde'    => ['nome' => 'Verdinho',  'cor' => '#66bb6a', 'preco' => 20,  'desc' => 'Camuflado',         'emoji' => '🟢'],
    'fantasma' => ['nome' => 'Fantasma',  'cor' => '#ce93d8', 'preco' => 25,  'desc' => 'Assustador',        'emoji' => '👻'],
    'robo'     => ['nome' => 'Robô-X',   'cor' => '#90caf9', 'preco' => 30,  'desc' => 'Blindado de aço',   'emoji' => '🤖'],
    'dourado'  => ['nome' => 'Lendário',  'cor' => '#ffd700', 'preco' => 50,  'desc' => 'O mais raro',       'emoji' => '👑'],
];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');

    $run_active = isset($_SESSION['flappy_run_active']) && $_SESSION['flappy_run_active'] === true;
    $run_start  = isset($_SESSION['flappy_run_start']) ? (int)$_SESSION['flappy_run_start'] : 0;
    $last_score = isset($_SESSION['flappy_last_score']) ? (int)$_SESSION['flappy_last_score'] : 0;

    $validate_run_score = function($score) use ($run_active, $run_start, $last_score) {
        if (!$run_active || $run_start <= 0) throw new Exception('Sessão de jogo inválida.');
        $elapsed = max(0, time() - $run_start);
        $max_score = (int)($elapsed * 5) + 5;
        if ($score < $last_score) throw new Exception('Score inválido.');
        if ($score > $max_score) throw new Exception('Score acima do permitido pela física do jogo.');
    };

    if ($_POST['acao'] == 'iniciar_run') {
        $_SESSION['flappy_run_active']  = true;
        $_SESSION['flappy_run_start']   = time();
        $_SESSION['flappy_last_score']  = 0;
        $_SESSION['flappy_revive_used'] = false;
        echo json_encode(['sucesso' => true]);
        exit;
    }

    if ($_POST['acao'] == 'comprar_skin') {
        $skin = $_POST['skin'];
        if (!isset($catalogo_skins[$skin])) { echo json_encode(['erro' => 'Skin inválida']); exit; }
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

    if ($_POST['acao'] == 'reviver') {
        try {
            if (!$run_active) throw new Exception('Sessão de jogo inválida.');
            if (!empty($_SESSION['flappy_revive_used'])) throw new Exception('Revive já utilizado.');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $user_id]);
            $saldo = (int)$stmt->fetchColumn();
            if ($saldo < 10) throw new Exception('Saldo insuficiente.');
            $pdo->prepare("UPDATE usuarios SET pontos = pontos - 10 WHERE id = :id")->execute([':id' => $user_id]);
            $pdo->commit();
            $_SESSION['flappy_revive_used'] = true;
            echo json_encode(['sucesso' => true, 'novo_saldo' => $saldo - 10]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['erro' => $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['acao'] == 'salvar_score') {
        $score = (int)$_POST['score'];
        try {
            $validate_run_score($score);
            $milestones   = intdiv(max(0, $score), 5);
            $coins_earned = (int)(($milestones * ($milestones + 3) / 2) * $pointsMultiplier);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO flappy_historico (id_usuario, pontuacao) VALUES (:uid, :score)")
                ->execute([':uid' => $user_id, ':score' => $score]);
            if ($coins_earned > 0) {
                $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")
                    ->execute([':val' => $coins_earned, ':id' => $user_id]);
            }
            $stmtSaldo = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id");
            $stmtSaldo->execute([':id' => $user_id]);
            $novo_saldo = (int)$stmtSaldo->fetchColumn();
            $pdo->commit();
            $_SESSION['flappy_last_score'] = $score;
            $_SESSION['flappy_run_active'] = false;
            echo json_encode(['sucesso' => true, 'coins' => $coins_earned, 'novo_saldo' => $novo_saldo]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['erro' => $e->getMessage()]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>🐦 Flappy Bird – FBA Games</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🐦</text></svg>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg: #0d0d0d;
            --surface: #181818;
            --border: #2a2a2a;
            --text: #f0f0f0;
            --text-2: #9ca3af;
            --red: #FC082B;
            --amber: #f59e0b;
            --green: #22c55e;
        }

        html, body {
            background: var(--bg);
            color: var(--text);
            font-family: 'Segoe UI', system-ui, sans-serif;
            height: 100%;
            overflow: hidden;
            -webkit-tap-highlight-color: transparent;
            touch-action: none;
        }

        /* ── NAV ── */
        #topbar {
            position: fixed; top: 0; left: 0; right: 0; z-index: 100;
            height: 52px;
            background: rgba(13,13,13,.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 14px;
        }
        #topbar a { color: var(--text-2); text-decoration: none; font-size: 13px; display: flex; align-items: center; gap: 5px; }
        #topbar a:hover { color: var(--text); }
        .pts-badge {
            background: var(--red); color: #fff;
            padding: 4px 12px; border-radius: 20px;
            font-size: 13px; font-weight: 800;
        }
        #topbar .title { font-size: 15px; font-weight: 800; letter-spacing: .3px; }

        /* ── GAME AREA ── */
        #game-wrapper {
            position: fixed;
            top: 52px; left: 0; right: 0; bottom: 0;
            display: flex; align-items: center; justify-content: center;
            background: #111;
        }

        canvas {
            display: block;
            border-radius: 12px;
            box-shadow: 0 0 40px rgba(0,0,0,.8);
            image-rendering: pixelated;
        }

        /* ── HUD SCORE ── */
        #hud {
            position: absolute;
            top: 14px; left: 50%; transform: translateX(-50%);
            display: none;
            align-items: center; gap: 10px;
            pointer-events: none; z-index: 10;
        }
        #hud .score-pill {
            background: rgba(0,0,0,.6);
            border: 1px solid rgba(255,255,255,.1);
            backdrop-filter: blur(8px);
            border-radius: 999px;
            padding: 6px 20px;
            font-size: 26px; font-weight: 900;
            color: #fff;
            font-family: 'Courier New', monospace;
            text-shadow: 0 2px 6px rgba(0,0,0,.8);
            letter-spacing: 2px;
            min-width: 70px; text-align: center;
        }

        /* ── OVERLAYS ── */
        .overlay {
            position: absolute;
            inset: 0;
            display: flex; align-items: center; justify-content: center;
            z-index: 20;
            padding: 12px;
        }
        .panel {
            background: rgba(18,18,18,.97);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 24px 20px;
            width: 100%; max-width: 340px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,.8);
            backdrop-filter: blur(12px);
        }

        /* ── START ── */
        .bird-big { font-size: 64px; line-height: 1; margin-bottom: 6px; }
        .game-title { font-size: 22px; font-weight: 900; letter-spacing: 2px; margin-bottom: 2px; }
        .game-sub { font-size: 12px; color: var(--text-2); margin-bottom: 16px; }

        .record-row {
            background: rgba(245,158,11,.08);
            border: 1px solid rgba(245,158,11,.2);
            border-radius: 10px;
            padding: 10px 14px;
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 14px; font-size: 13px;
        }
        .record-row .label { color: var(--text-2); }
        .record-row .value { color: var(--amber); font-weight: 800; font-size: 16px; }

        .ranking-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px 14px;
            margin-bottom: 14px;
            text-align: left;
        }
        .ranking-box .head { font-size: 10px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: var(--amber); margin-bottom: 8px; display: flex; align-items: center; gap: 5px; }
        .rank-row { display: flex; justify-content: space-between; align-items: center; font-size: 12px; padding: 4px 0; border-bottom: 1px solid var(--border); }
        .rank-row:last-child { border-bottom: none; }
        .rank-row .pos { color: var(--text-2); width: 20px; }
        .rank-row .name { flex: 1; color: var(--text); }
        .rank-row .pts { color: var(--amber); font-weight: 700; }

        /* ── BUTTONS ── */
        .btn-play {
            width: 100%; padding: 14px;
            background: linear-gradient(135deg, #16a34a, #15803d);
            border: none; border-radius: 12px;
            color: #fff; font-size: 16px; font-weight: 800;
            cursor: pointer; letter-spacing: .5px;
            transition: transform .1s, box-shadow .1s;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 4px 15px rgba(22,163,74,.4);
            margin-bottom: 10px;
        }
        .btn-play:active { transform: scale(.97); }

        .btn-shop {
            width: 100%; padding: 11px;
            background: rgba(245,158,11,.1);
            border: 1px solid rgba(245,158,11,.3);
            border-radius: 12px;
            color: var(--amber); font-size: 14px; font-weight: 700;
            cursor: pointer;
            transition: background .15s;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .btn-shop:hover { background: rgba(245,158,11,.18); }

        .btn-restart {
            width: 100%; padding: 13px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border: none; border-radius: 12px;
            color: #fff; font-size: 15px; font-weight: 800;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            margin-bottom: 10px;
            box-shadow: 0 4px 15px rgba(37,99,235,.35);
        }
        .btn-revive {
            width: 100%; padding: 13px;
            background: rgba(245,158,11,.12);
            border: 1px solid rgba(245,158,11,.4);
            border-radius: 12px;
            color: var(--amber); font-size: 14px; font-weight: 800;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            margin-bottom: 10px;
        }
        .btn-outline {
            width: 100%; padding: 10px;
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text-2); font-size: 13px; font-weight: 600;
            cursor: pointer;
        }
        .btn-outline:hover { background: var(--surface); }

        /* ── GAME OVER PANEL ── */
        .go-title { font-size: 28px; font-weight: 900; color: var(--red); letter-spacing: 2px; margin-bottom: 14px; }
        .score-grid {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 8px; margin-bottom: 16px;
        }
        .score-cell {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px; padding: 12px 8px;
        }
        .score-cell .sc-label { font-size: 10px; font-weight: 700; letter-spacing: .5px; text-transform: uppercase; color: var(--text-2); margin-bottom: 4px; }
        .score-cell .sc-value { font-size: 24px; font-weight: 900; }
        .score-cell .sc-value.current { color: #fff; }
        .score-cell .sc-value.best { color: var(--amber); }
        .coins-banner {
            background: rgba(34,197,94,.08);
            border: 1px solid rgba(34,197,94,.2);
            border-radius: 10px; padding: 10px 14px;
            font-size: 13px; color: var(--green);
            font-weight: 700; margin-bottom: 16px;
            display: none; align-items: center; justify-content: center; gap: 6px;
        }

        /* ── SHOP ── */
        .shop-title { font-size: 18px; font-weight: 900; color: var(--amber); margin-bottom: 4px; }
        .shop-sub { font-size: 12px; color: var(--text-2); margin-bottom: 14px; }
        .saldo-info {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 10px; padding: 8px 12px;
            font-size: 12px; color: var(--text-2);
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 12px;
        }
        .saldo-info strong { color: var(--amber); }
        .skins-list { max-height: 300px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
        .skin-row {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 12px; padding: 10px 12px;
            display: flex; align-items: center; gap: 10px;
        }
        .skin-row.equipada { border-color: rgba(34,197,94,.4); background: rgba(34,197,94,.06); }
        .skin-row.comprada { border-color: rgba(59,130,246,.3); }
        .skin-ball {
            width: 32px; height: 32px; border-radius: 50%;
            flex-shrink: 0; border: 2px solid rgba(255,255,255,.2);
            position: relative; display: flex; align-items: center; justify-content: center;
            font-size: 14px;
        }
        .skin-info { flex: 1; text-align: left; }
        .skin-info .sname { font-size: 13px; font-weight: 700; }
        .skin-info .sdesc { font-size: 11px; color: var(--text-2); }
        .skin-badge {
            font-size: 11px; font-weight: 700; padding: 5px 10px;
            border-radius: 8px; border: none; cursor: pointer; flex-shrink: 0;
        }
        .skin-badge.equipado-btn { background: rgba(34,197,94,.15); color: var(--green); cursor: default; }
        .skin-badge.usar-btn { background: rgba(59,130,246,.2); color: #60a5fa; }
        .skin-badge.comprar-btn { background: rgba(245,158,11,.15); color: var(--amber); }

        /* ── FLOATING TEXT ── */
        .floating-text {
            position: fixed; font-weight: 800; font-size: 14px;
            color: #ffd700; text-shadow: 0 0 8px rgba(0,0,0,.9);
            animation: floatUp .9s forwards; pointer-events: none; z-index: 200;
            white-space: nowrap;
        }
        @keyframes floatUp { 0%{transform:translateY(0);opacity:1} 100%{transform:translateY(-50px);opacity:0} }

        /* ── SKIN PREVIEW INDICATOR ── */
        #skin-indicator {
            position: absolute; bottom: 14px; right: 14px;
            background: rgba(0,0,0,.6); border: 1px solid rgba(255,255,255,.1);
            backdrop-filter: blur(8px);
            border-radius: 10px; padding: 6px 10px;
            font-size: 11px; color: var(--text-2);
            pointer-events: none; display: none; z-index: 10;
        }
        #skin-indicator span { font-weight: 700; color: var(--text); }
    </style>
</head>
<body>

<!-- NAV -->
<div id="topbar">
    <a href="../games.php"><i class="bi bi-arrow-left-short" style="font-size:18px"></i> Jogos</a>
    <div class="title">🐦 Flappy Bird</div>
    <span class="pts-badge" id="saldoDisplay"><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?></span>
</div>

<!-- GAME -->
<div id="game-wrapper">
    <canvas id="flappyCanvas"></canvas>

    <div id="hud">
        <div class="score-pill" id="scoreDisplay">0</div>
    </div>

    <div id="skin-indicator">Skin: <span id="skinName">Padrão</span></div>

    <!-- START -->
    <div class="overlay" id="start-screen">
        <div class="panel">
            <div class="bird-big">🐦</div>
            <div class="game-title">FLAPPY BIRD</div>
            <div class="game-sub">Toque ou pressione espaço para voar</div>

            <div class="record-row">
                <span class="label"><i class="bi bi-trophy-fill" style="color:var(--amber)"></i> Seu recorde</span>
                <span class="value"><?= $recorde ?></span>
            </div>

            <?php if (!empty($ranking_flappy)): ?>
            <div class="ranking-box">
                <div class="head"><i class="bi bi-bar-chart-fill"></i> Top Voadores</div>
                <?php foreach ($ranking_flappy as $idx => $r): ?>
                <div class="rank-row">
                    <span class="pos">#<?= $idx + 1 ?></span>
                    <span class="name"><?= htmlspecialchars($r['nome']) ?></span>
                    <span class="pts"><?= number_format($r['recorde'], 0, ',', '.') ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <button class="btn-play" id="btnStartGame">
                <i class="bi bi-play-fill"></i> JOGAR AGORA
            </button>
            <button class="btn-shop" id="btnOpenShop">
                <i class="bi bi-palette-fill"></i> Loja de Skins
            </button>
        </div>
    </div>

    <!-- SHOP -->
    <div class="overlay" id="shop-screen" style="display:none">
        <div class="panel">
            <div class="shop-title"><i class="bi bi-palette-fill"></i> Loja de Skins</div>
            <div class="shop-sub">Personalize seu pássaro</div>

            <div class="saldo-info">
                <span>Seu saldo</span>
                <strong id="saldoShop"><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?> pts</strong>
            </div>

            <div class="skins-list">
                <!-- Default -->
                <div class="skin-row <?= $meu_perfil['flappy_skin_equipada'] == 'default' ? 'equipada' : '' ?>">
                    <div class="skin-ball" style="background:#ffeb3b">🐦</div>
                    <div class="skin-info">
                        <div class="sname">Padrão</div>
                        <div class="sdesc">O clássico amarelinho</div>
                    </div>
                    <?php if ($meu_perfil['flappy_skin_equipada'] == 'default'): ?>
                        <span class="skin-badge equipado-btn">✓ Equipado</span>
                    <?php else: ?>
                        <button class="skin-badge usar-btn btn-equip-skin" data-skin="default">Usar</button>
                    <?php endif; ?>
                </div>

                <?php foreach ($catalogo_skins as $key => $skin):
                    $tem     = in_array($key, $minhas_skins);
                    $equipado = ($meu_perfil['flappy_skin_equipada'] == $key);
                ?>
                <div class="skin-row <?= $equipado ? 'equipada' : ($tem ? 'comprada' : '') ?>">
                    <div class="skin-ball" style="background:<?= $skin['cor'] ?>"><?= $skin['emoji'] ?></div>
                    <div class="skin-info">
                        <div class="sname"><?= $skin['nome'] ?></div>
                        <div class="sdesc"><?= $skin['desc'] ?></div>
                    </div>
                    <?php if ($equipado): ?>
                        <span class="skin-badge equipado-btn">✓ Equipado</span>
                    <?php elseif ($tem): ?>
                        <button class="skin-badge usar-btn btn-equip-skin" data-skin="<?= $key ?>">Usar</button>
                    <?php else: ?>
                        <button class="skin-badge comprar-btn btn-buy-skin" data-skin="<?= $key ?>" data-price="<?= $skin['preco'] ?>">
                            <?= $skin['preco'] ?> pts
                        </button>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <button class="btn-outline" id="btnCloseShop">← Voltar</button>
        </div>
    </div>

    <!-- GAME OVER -->
    <div class="overlay" id="game-over-screen" style="display:none">
        <div class="panel">
            <div class="go-title">GAME OVER</div>

            <div class="score-grid">
                <div class="score-cell">
                    <div class="sc-label">Placar</div>
                    <div class="sc-value current" id="finalScore">0</div>
                </div>
                <div class="score-cell">
                    <div class="sc-label">Recorde</div>
                    <div class="sc-value best" id="bestScore"><?= $recorde ?></div>
                </div>
            </div>

            <div class="coins-banner" id="coinsBanner">
                <i class="bi bi-coin"></i> <span id="coinsText">+0 moedas ganhas!</span>
            </div>

            <button id="reviveBtn" class="btn-revive" style="display:none">
                <i class="bi bi-heart-fill"></i> Continuar por 10 pts
            </button>
            <button id="btnRestartGame" class="btn-restart">
                <i class="bi bi-arrow-clockwise"></i> Tentar de Novo
            </button>
            <button class="btn-outline" id="btnMenu">← Menu</button>
        </div>
    </div>
</div>

<script>
(() => {
    // ── CANVAS RESPONSIVO ──
    const canvas  = document.getElementById('flappyCanvas');
    const ctx     = canvas.getContext('2d');
    const wrapper = document.getElementById('game-wrapper');

    function resizeCanvas() {
        const navH  = 52;
        const availW = window.innerWidth;
        const availH = window.innerHeight - navH;
        const ratio  = 400 / 600;
        let w, h;
        if (availW / availH < ratio) {
            w = availW;
            h = w / ratio;
        } else {
            h = availH;
            w = h * ratio;
        }
        canvas.width  = 400;
        canvas.height = 600;
        canvas.style.width  = Math.floor(w) + 'px';
        canvas.style.height = Math.floor(h) + 'px';
    }
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);

    // ── SKINS ──
    const currentSkinKey = '<?= $meu_perfil['flappy_skin_equipada'] ?>';
    const skinConfig = {
        'default':  { body: '#ffeb3b', wing: '#fdd835', eye: '#000', beak: '#ff9800', glow: null },
        'azul':     { body: '#29b6f6', wing: '#0288d1', eye: '#000', beak: '#ff9800', glow: '#29b6f680' },
        'vermelho': { body: '#ef5350', wing: '#c62828', eye: '#fff', beak: '#ffca28', glow: '#ef535080' },
        'verde':    { body: '#66bb6a', wing: '#2e7d32', eye: '#000', beak: '#ff9800', glow: null },
        'fantasma': { body: '#ce93d8', wing: '#9c27b0', eye: '#4a148c', beak: '#e1bee7', glow: '#ce93d860' },
        'robo':     { body: '#90caf9', wing: '#1565c0', eye: '#f44336', beak: '#78909c', glow: '#90caf960' },
        'dourado':  { body: '#ffd700', wing: '#f9a825', eye: '#000', beak: '#e65100', glow: '#ffd70080' },
    };
    let currentSkin = skinConfig[currentSkinKey] || skinConfig['default'];

    // Update skin indicator
    const skinNames = {
        'default':'Padrão','azul':'Azulão','vermelho':'Red Bird',
        'verde':'Verdinho','fantasma':'Fantasma','robo':'Robô-X','dourado':'Lendário'
    };
    const ind = document.getElementById('skinName');
    if (ind) ind.textContent = skinNames[currentSkinKey] || 'Padrão';

    // ── CENÁRIOS ──
    const scenarios = [
        { sky1: '#0a0a1a', sky2: '#1a1a3e', ground: '#1b5e20', grass: '#2e7d32', name: '🌌 NOITE' },
        { sky1: '#87ceeb', sky2: '#4fc3f7', ground: '#33691e', grass: '#558b2f', name: '☀️ DIA' },
        { sky1: '#bf360c', sky2: '#e64a19', ground: '#4e342e', grass: '#6d4c41', name: '🌅 PÔR DO SOL' },
        { sky1: '#1a0533', sky2: '#4a148c', ground: '#1b1b1b', grass: '#311b92', name: '🔮 NEON' },
        { sky1: '#0d1b2a', sky2: '#1c3a57', ground: '#263238', grass: '#37474f', name: '🌊 OCEANO' },
    ];

    const rewardMultiplier = <?= (int)$pointsMultiplier ?>;
    let frames = 0, score = 0, highScore = <?= $recorde ?>, currentState = 'START', coinsEarned = 0;
    let hasUsedRevive = false;

    // ── PÁSSARO ──
    const bird = {
        x: 80, y: 200, radius: 14,
        velocity: 0, gravity: 0.28, jump: 5.2, rotation: 0,
        draw() {
            ctx.save();
            ctx.translate(this.x, this.y);
            this.rotation = Math.min(Math.PI / 3.5, Math.max(-Math.PI / 5, this.velocity * 0.09));
            ctx.rotate(this.rotation);

            const s = currentSkin;

            // Glow
            if (s.glow) {
                ctx.shadowColor = s.glow;
                ctx.shadowBlur = 12;
            }

            // Corpo
            ctx.fillStyle = s.body;
            ctx.beginPath();
            ctx.arc(0, 0, this.radius, 0, Math.PI * 2);
            ctx.fill();

            ctx.shadowBlur = 0;

            // Asa
            ctx.fillStyle = s.wing;
            ctx.beginPath();
            ctx.ellipse(-3, 5, 9, 5, -0.3, 0, Math.PI * 2);
            ctx.fill();

            // Brilho
            ctx.fillStyle = 'rgba(255,255,255,0.25)';
            ctx.beginPath();
            ctx.arc(-3, -4, 6, 0, Math.PI * 2);
            ctx.fill();

            // Olho
            ctx.fillStyle = '#fff';
            ctx.beginPath();
            ctx.arc(6, -5, 5, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = s.eye;
            ctx.beginPath();
            ctx.arc(7.5, -5, 2.5, 0, Math.PI * 2);
            ctx.fill();
            // Brilho do olho
            ctx.fillStyle = 'rgba(255,255,255,0.8)';
            ctx.beginPath();
            ctx.arc(8.5, -6, 1, 0, Math.PI * 2);
            ctx.fill();

            // Bico
            ctx.fillStyle = s.beak;
            ctx.beginPath();
            ctx.moveTo(10, 1);
            ctx.lineTo(19, 4);
            ctx.lineTo(10, 7);
            ctx.closePath();
            ctx.fill();

            ctx.restore();
        },
        update() {
            this.velocity += this.gravity;
            this.y += this.velocity;
            if (this.y + this.radius >= fg.y) { this.y = fg.y - this.radius; gameOver(); }
            if (this.y - this.radius <= 0) { this.y = this.radius; this.velocity = 0; }
        },
        flap() {
            this.velocity = -this.jump;
            // Partícula de voo
            spawnParticle(this.x - 10, this.y + 8, currentSkin.wing);
        }
    };

    // ── CHÃO ──
    const fg = {
        get y() { return canvas.height - 80; },
        dx: 0,
        draw() {
            const scen = scenarios[Math.floor(score / 15) % scenarios.length];
            ctx.fillStyle = scen.ground;
            ctx.fillRect(0, this.y, canvas.width, canvas.height - this.y);
            ctx.fillStyle = scen.grass;
            ctx.fillRect(0, this.y, canvas.width, 12);
            // Grama
            ctx.fillStyle = scen.ground;
            for (let i = 0; i < 20; i++) {
                const gx = ((i * 28) - this.dx % 28 + canvas.width) % canvas.width;
                ctx.beginPath();
                ctx.moveTo(gx, this.y + 12);
                ctx.lineTo(gx + 10, this.y);
                ctx.lineTo(gx + 20, this.y + 12);
                ctx.fill();
            }
        },
        update() { this.dx += pipes.dx; }
    };

    // ── CANOS ──
    const pipes = {
        items: [], w: 56, gap: 130, dx: 2.2,
        colors: { pipe: '#2d6a2d', cap: '#388e3c', dark: '#1b5e20' },
        draw() {
            for (const p of this.items) {
                const c = this.colors;
                // Cano superior
                ctx.fillStyle = c.pipe;
                ctx.fillRect(p.x, 0, this.w, p.top);
                // Cap superior
                ctx.fillStyle = c.cap;
                ctx.fillRect(p.x - 4, p.top - 20, this.w + 8, 22);
                ctx.fillStyle = c.dark;
                ctx.fillRect(p.x - 4, p.top - 20, this.w + 8, 4);

                // Cano inferior
                const botY = fg.y - p.bottom;
                ctx.fillStyle = c.pipe;
                ctx.fillRect(p.x, botY, this.w, p.bottom);
                // Cap inferior
                ctx.fillStyle = c.cap;
                ctx.fillRect(p.x - 4, botY - 2, this.w + 8, 22);
                ctx.fillStyle = c.dark;
                ctx.fillRect(p.x - 4, botY + 18, this.w + 8, 4);

                // Listras 3D
                ctx.fillStyle = 'rgba(255,255,255,0.07)';
                ctx.fillRect(p.x + 4, 0, 6, p.top);
                ctx.fillRect(p.x + 4, botY, 6, p.bottom);
            }
        },
        update() {
            this.dx = 2.2 + Math.floor(score / 10) * 0.4;
            const spawnRate = Math.max(70, Math.floor(210 / this.dx));
            if (frames % spawnRate === 0) {
                const avail = fg.y - this.gap - 60;
                const top   = Math.floor(Math.random() * (avail - 60)) + 30;
                this.items.push({ x: canvas.width, top, bottom: avail - top, passed: false });
            }
            for (let i = this.items.length - 1; i >= 0; i--) {
                const p = this.items[i];
                p.x -= this.dx;
                // Colisão
                if (bird.x + bird.radius > p.x + 4 && bird.x - bird.radius < p.x + this.w - 4) {
                    if (bird.y - bird.radius < p.top || bird.y + bird.radius > fg.y - p.bottom) {
                        gameOver(); return;
                    }
                }
                if (!p.passed && p.x + this.w < bird.x) {
                    p.passed = true; score++;
                    document.getElementById('scoreDisplay').textContent = score;

                    // Troca de cenário
                    if (score % 15 === 0) {
                        const scen = scenarios[Math.floor(score / 15) % scenarios.length];
                        showFloatingText(scen.name, canvas.width / 2, 120);
                    }
                    // Moedas
                    if (score % 5 === 0) {
                        const reward = (1 + (score / 5)) * rewardMultiplier;
                        coinsEarned += reward;
                        showFloatingText(`+${reward} 🪙`, bird.x, bird.y - 30);
                    }
                }
                if (p.x + this.w < 0) this.items.splice(i, 1);
            }
        },
        reset() { this.items = []; this.dx = 2.2; }
    };

    // ── ESTRELAS (fundo noturno) ──
    const stars = Array.from({ length: 40 }, () => ({
        x: Math.random() * 400, y: Math.random() * 300,
        r: Math.random() * 1.5 + 0.5, alpha: Math.random()
    }));

    // ── PARTÍCULAS ──
    const particles = [];
    function spawnParticle(x, y, color) {
        for (let i = 0; i < 4; i++) {
            particles.push({
                x, y, vx: (Math.random() - 0.5) * 3,
                vy: (Math.random() - 1) * 2,
                alpha: 1, color, size: Math.random() * 4 + 2
            });
        }
    }
    function updateParticles() {
        for (let i = particles.length - 1; i >= 0; i--) {
            const p = particles[i];
            p.x += p.vx; p.y += p.vy; p.vy += 0.1; p.alpha -= 0.06;
            if (p.alpha <= 0) { particles.splice(i, 1); continue; }
            ctx.globalAlpha = p.alpha;
            ctx.fillStyle = p.color;
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.globalAlpha = 1;
    }

    // ── FUNDO ──
    function drawBg() {
        const scen = scenarios[Math.floor(score / 15) % scenarios.length];
        const grad = ctx.createLinearGradient(0, 0, 0, fg.y);
        grad.addColorStop(0, scen.sky1);
        grad.addColorStop(1, scen.sky2);
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, canvas.width, fg.y);

        // Estrelas (cenas escuras)
        if (score % 15 === 0 || score % 15 >= 10) {
            for (const s of stars) {
                s.alpha = 0.4 + Math.sin(frames * 0.03 + s.x) * 0.3;
                ctx.globalAlpha = s.alpha;
                ctx.fillStyle = '#fff';
                ctx.beginPath(); ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2); ctx.fill();
            }
            ctx.globalAlpha = 1;
        }
    }

    // ── LOOP ──
    let animId;
    function loop() {
        if (currentState !== 'GAME') return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        drawBg();
        pipes.draw(); fg.draw();
        updateParticles();
        bird.draw();
        bird.update(); fg.update(); pipes.update();
        frames++;
        animId = requestAnimationFrame(loop);
    }

    // ── START ──
    function startGame() {
        const fd = new FormData();
        fd.append('acao', 'iniciar_run');
        fetch('index.php?game=flappy', { method: 'POST', body: fd }).then(() => {
            hideAll();
            document.getElementById('hud').style.display = 'flex';
            document.getElementById('skin-indicator').style.display = 'block';
            document.getElementById('scoreDisplay').textContent = '0';
            bird.y = 200; bird.velocity = 0;
            pipes.reset(); score = 0; frames = 0; coinsEarned = 0;
            hasUsedRevive = false;
            currentState = 'GAME';
            loop();
        });
    }

    // ── GAME OVER ──
    function gameOver() {
        if (currentState !== 'GAME') return;
        currentState = 'OVER';
        cancelAnimationFrame(animId);

        if (score > highScore) highScore = score;
        document.getElementById('finalScore').textContent = score;
        document.getElementById('bestScore').textContent = highScore;
        document.getElementById('hud').style.display = 'none';
        document.getElementById('skin-indicator').style.display = 'none';

        const saldo = parseInt(document.getElementById('saldoDisplay').textContent.replace(/\D/g, ''));
        document.getElementById('reviveBtn').style.display =
            (!hasUsedRevive && saldo >= 10) ? 'flex' : 'none';

        const fd = new FormData();
        fd.append('acao', 'salvar_score'); fd.append('score', score);
        fetch('index.php?game=flappy', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
            if (d?.sucesso) {
                updateSaldo(d.novo_saldo);
                if (d.coins > 0) {
                    const banner = document.getElementById('coinsBanner');
                    document.getElementById('coinsText').textContent = `+${d.coins} moedas ganhas!`;
                    banner.style.display = 'flex';
                }
            }
        });

        setTimeout(() => {
            document.getElementById('game-over-screen').style.display = 'flex';
        }, 400);
    }

    // ── REVIVE ──
    function revive() {
        const fd = new FormData(); fd.append('acao', 'reviver');
        fetch('index.php?game=flappy', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
            if (d.sucesso) {
                updateSaldo(d.novo_saldo);
                hasUsedRevive = true;
                hideAll();
                document.getElementById('hud').style.display = 'flex';
                document.getElementById('skin-indicator').style.display = 'block';
                bird.y = 200; bird.velocity = 0;
                pipes.items = pipes.items.filter(p => p.x > 220);
                showFloatingText('💚 REVIVEU!', canvas.width / 2, 180);
                currentState = 'GAME'; loop();
            } else { alert(d.erro || 'Erro ao processar revive'); }
        });
    }

    // ── SHOP ──
    function buySkin(skin, price) {
        if (!confirm(`Comprar a skin por ${price} pts?`)) return;
        const fd = new FormData(); fd.append('acao', 'comprar_skin'); fd.append('skin', skin);
        fetch('index.php?game=flappy', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
            if (d.sucesso) location.reload();
            else alert(d.erro);
        });
    }
    function equipSkin(skin) {
        const fd = new FormData(); fd.append('acao', 'equipar_skin'); fd.append('skin', skin);
        fetch('index.php?game=flappy', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
            if (d.sucesso) location.reload();
            else alert(d.erro);
        });
    }

    // ── HELPERS ──
    function hideAll() {
        document.getElementById('start-screen').style.display    = 'none';
        document.getElementById('shop-screen').style.display     = 'none';
        document.getElementById('game-over-screen').style.display = 'none';
        document.getElementById('coinsBanner').style.display = 'none';
    }
    function updateSaldo(n) {
        const s = n.toLocaleString('pt-BR') + ' pts';
        document.getElementById('saldoDisplay').textContent = n.toLocaleString('pt-BR');
        document.getElementById('saldoShop').textContent = s;
    }
    function showFloatingText(t, cx, cy) {
        const rect = canvas.getBoundingClientRect();
        const scaleX = rect.width / canvas.width;
        const scaleY = rect.height / canvas.height;
        const el = document.createElement('div');
        el.className = 'floating-text';
        el.textContent = t;
        el.style.left = (rect.left + cx * scaleX - 30) + 'px';
        el.style.top  = (rect.top  + cy * scaleY - 10) + 'px';
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 950);
    }

    // ── EVENTOS ──
    function action() { if (currentState === 'GAME') bird.flap(); }

    document.addEventListener('keydown', e => {
        if (e.code === 'Space' || e.code === 'ArrowUp') { e.preventDefault(); action(); }
    });
    canvas.addEventListener('touchstart', e => { e.preventDefault(); action(); }, { passive: false });
    canvas.addEventListener('click', action);

    document.getElementById('btnStartGame')?.addEventListener('click', startGame);
    document.getElementById('btnRestartGame')?.addEventListener('click', startGame);
    document.getElementById('reviveBtn')?.addEventListener('click', revive);
    document.getElementById('btnMenu')?.addEventListener('click', () => { hideAll(); document.getElementById('start-screen').style.display = 'flex'; });
    document.getElementById('btnOpenShop')?.addEventListener('click', () => {
        document.getElementById('start-screen').style.display = 'none';
        document.getElementById('shop-screen').style.display = 'flex';
    });
    document.getElementById('btnCloseShop')?.addEventListener('click', () => {
        document.getElementById('shop-screen').style.display = 'none';
        document.getElementById('start-screen').style.display = 'flex';
    });

    document.querySelectorAll('.btn-buy-skin').forEach(btn =>
        btn.addEventListener('click', e => buySkin(e.currentTarget.dataset.skin, e.currentTarget.dataset.price))
    );
    document.querySelectorAll('.btn-equip-skin').forEach(btn =>
        btn.addEventListener('click', e => equipSkin(e.currentTarget.dataset.skin))
    );

    // Render inicial (tela start)
    drawBg(); pipes.draw(); fg.draw(); bird.draw();
})();
</script>
</body>
</html>
