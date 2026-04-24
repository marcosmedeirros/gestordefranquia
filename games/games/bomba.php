<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// bomba.php - Jogo Bomba diário
// session_start já foi chamado em games/index.php
require '../core/conexao.php';

$BASE_PONTOS = 50; // pontos por diamante
$pointsMultiplier = getGamePointsMultiplier($pdo, 'bomba');
$PONTOS_POR_DIAMANTE = $BASE_PONTOS * $pointsMultiplier;
$TOTAL_QUADRADOS = 9;
$TOTAL_DIAMANTES = 4;
$TOTAL_BOMBAS = 5;
$VIDAS_INICIAIS = 2;

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];

// Criar tabela se não existir
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bomba_historico (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario INT NOT NULL,
            data_jogo DATE NOT NULL,
            estado_jogo TEXT NULL,
            vidas INT NOT NULL DEFAULT 2,
            diamantes_achados INT NOT NULL DEFAULT 0,
            pontos_ganhos INT NOT NULL DEFAULT 0,
            status ENUM('jogando','saiu','perdeu') NOT NULL DEFAULT 'jogando',
            streak_count INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_usuario_dia (id_usuario, data_jogo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (PDOException $e) {}

// Dados do usuário
try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro perfil: " . $e->getMessage());
}

function gerarTabuleiro() {
    $quadrados = array_merge(
        array_fill(0, 4, 'diamante'),
        array_fill(0, 5, 'bomba')
    );
    shuffle($quadrados);
    $tabuleiro = [];
    foreach ($quadrados as $i => $tipo) {
        $tabuleiro[] = ['pos' => $i, 'tipo' => $tipo, 'aberto' => false];
    }
    return $tabuleiro;
}

$hoje = date('Y-m-d');

try {
    $stmt = $pdo->prepare("SELECT * FROM bomba_historico WHERE id_usuario = :uid AND data_jogo = :dt");
    $stmt->execute([':uid' => $user_id, ':dt' => $hoje]);
    $dados_jogo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$dados_jogo) {
        $tab = json_encode(gerarTabuleiro());
        $pdo->prepare("INSERT INTO bomba_historico (id_usuario, data_jogo, estado_jogo, vidas, diamantes_achados, pontos_ganhos, status) VALUES (:uid, :dt, :tab, :v, 0, 0, 'jogando')")
            ->execute([':uid' => $user_id, ':dt' => $hoje, ':tab' => $tab, ':v' => $VIDAS_INICIAIS]);
        $stmt->execute([':uid' => $user_id, ':dt' => $hoje]);
        $dados_jogo = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    die("Erro crítico: " . $e->getMessage());
}

$tabuleiro = json_decode($dados_jogo['estado_jogo'], true);
if (!is_array($tabuleiro)) {
    $tabuleiro = gerarTabuleiro();
}

$vidas = (int)$dados_jogo['vidas'];
$diamantes = (int)$dados_jogo['diamantes_achados'];
$pontos = (int)$dados_jogo['pontos_ganhos'];
$status = $dados_jogo['status'];

// Streak
$streak_atual = 0;
try {
    $stmtStreak = $pdo->prepare("SELECT data_jogo, streak_count FROM bomba_historico WHERE id_usuario = :uid ORDER BY data_jogo DESC LIMIT 1");
    $stmtStreak->execute([':uid' => $user_id]);
    $rowStreak = $stmtStreak->fetch(PDO::FETCH_ASSOC);
    if ($rowStreak) $streak_atual = (int)($rowStreak['streak_count'] ?? 0);
} catch (PDOException $e) {}

$update_streak = function() use ($pdo, $user_id, $hoje, &$streak_atual) {
    $stmtToday = $pdo->prepare("SELECT streak_count FROM bomba_historico WHERE id_usuario = :uid AND data_jogo = :hoje LIMIT 1");
    $stmtToday->execute([':uid' => $user_id, ':hoje' => $hoje]);
    $todayRow = $stmtToday->fetch(PDO::FETCH_ASSOC);
    if ($todayRow && (int)($todayRow['streak_count'] ?? 0) > 0) {
        $streak_atual = (int)$todayRow['streak_count'];
        return;
    }
    $stmtPrev = $pdo->prepare("SELECT data_jogo, streak_count FROM bomba_historico WHERE id_usuario = :uid AND data_jogo < :hoje ORDER BY data_jogo DESC LIMIT 1");
    $stmtPrev->execute([':uid' => $user_id, ':hoje' => $hoje]);
    $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);
    $yesterday = date('Y-m-d', strtotime($hoje . ' -1 day'));
    $nova = ($prev && $prev['data_jogo'] === $yesterday) ? ((int)$prev['streak_count'] + 1) : 1;
    $pdo->prepare("UPDATE bomba_historico SET streak_count = :s WHERE id_usuario = :uid AND data_jogo = :hoje")
        ->execute([':s' => $nova, ':uid' => $user_id, ':hoje' => $hoje]);
    $streak_atual = $nova;
};

// ========== API AJAX ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');

    if ($status !== 'jogando') {
        echo json_encode(['erro' => 'Jogo já finalizado.']);
        exit;
    }

    // Abrir quadrado
    if ($_POST['acao'] === 'abrir') {
        $pos = (int)$_POST['pos'];

        if (!isset($tabuleiro[$pos]) || $tabuleiro[$pos]['aberto']) {
            echo json_encode(['erro' => 'Posição inválida ou já aberta.']);
            exit;
        }

        $tabuleiro[$pos]['aberto'] = true;
        $tipo = $tabuleiro[$pos]['tipo'];

        if ($tipo === 'diamante') {
            $diamantes++;
            $pontos += $PONTOS_POR_DIAMANTE;
        } else {
            $vidas--;
            if ($vidas <= 0) {
                $vidas = 0;
                $status = 'perdeu';
                $pontos = 0;
                $diamantes_update = $diamantes;
            }
        }

        // Todos os diamantes achados → venceu (pode sair com pontos)
        $todos_diamantes = ($diamantes >= $TOTAL_DIAMANTES);

        $pdo->prepare("UPDATE bomba_historico SET estado_jogo=:tab, vidas=:v, diamantes_achados=:d, pontos_ganhos=:p, status=:st WHERE id_usuario=:uid AND data_jogo=:dt")
            ->execute([':tab' => json_encode($tabuleiro), ':v' => $vidas, ':d' => $diamantes, ':p' => $pontos, ':st' => $status, ':uid' => $user_id, ':dt' => $hoje]);

        if ($status === 'perdeu') {
            try { $update_streak(); } catch (Exception $e) {}
        }

        echo json_encode([
            'tipo'      => $tipo,
            'vidas'     => $vidas,
            'diamantes' => $diamantes,
            'pontos'    => $pontos,
            'status'    => $status,
            'todos_diamantes' => $todos_diamantes
        ]);
        exit;
    }

    // Sacar prêmio
    if ($_POST['acao'] === 'sacar') {
        if ($pontos <= 0) {
            echo json_encode(['erro' => 'Nenhum ponto para sacar.']);
            exit;
        }

        $status = 'saiu';
        $pdo->prepare("UPDATE bomba_historico SET status='saiu', pontos_ganhos=:p WHERE id_usuario=:uid AND data_jogo=:dt")
            ->execute([':p' => $pontos, ':uid' => $user_id, ':dt' => $hoje]);

        // Revelar tabuleiro completo
        foreach ($tabuleiro as &$q) { $q['aberto'] = true; }
        $pdo->prepare("UPDATE bomba_historico SET estado_jogo=:tab WHERE id_usuario=:uid AND data_jogo=:dt")
            ->execute([':tab' => json_encode($tabuleiro), ':uid' => $user_id, ':dt' => $hoje]);

        // Adicionar pontos ao usuário
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE usuarios SET pontos = pontos + :pts WHERE id = :uid")
                ->execute([':pts' => $pontos, ':uid' => $user_id]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
        }

        try { $update_streak(); } catch (Exception $e) {}

        echo json_encode(['status' => 'saiu', 'pontos' => $pontos, 'tabuleiro' => $tabuleiro]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>💣 Bomba - FBA Games</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>💣</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --bg: #0f0f0f;
            --surface: #1a1a1a;
            --border: #2a2a2a;
            --text: #f0f0f0;
            --text-2: #9ca3af;
            --amber: #f59e0b;
            --red: #ef4444;
            --green: #22c55e;
            --blue: #3b82f6;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: var(--bg); color: var(--text); font-family: 'Segoe UI', sans-serif; min-height: 100vh; }

        .game-wrapper { max-width: 420px; margin: 0 auto; padding: 16px; }

        .info-bar {
            display: grid; grid-template-columns: 1fr 1fr 1fr;
            gap: 10px; margin-bottom: 20px;
        }
        .info-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 12px; padding: 12px; text-align: center;
        }
        .info-card .label { font-size: 10px; font-weight: 700; letter-spacing: .7px; text-transform: uppercase; color: var(--text-2); margin-bottom: 4px; }
        .info-card .value { font-size: 22px; font-weight: 900; }
        .info-card .value.lives { color: var(--red); }
        .info-card .value.diamonds { color: #38bdf8; }
        .info-card .value.points { color: var(--green); }

        .grid {
            display: grid; grid-template-columns: repeat(3, 1fr);
            gap: 10px; margin-bottom: 20px;
        }
        .card-cell {
            aspect-ratio: 1;
            background: var(--surface);
            border: 2px solid var(--border);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 36px;
            cursor: pointer;
            transition: transform .15s, border-color .15s, background .15s;
            position: relative;
            overflow: hidden;
        }
        .card-cell.closed { background: #1e2a3a; border-color: #2d4a6a; }
        .card-cell.closed:not(.disabled):hover { transform: scale(1.05); border-color: #4a8fbf; background: #1f3448; }
        .card-cell.closed::before {
            content: '?';
            font-size: 28px; font-weight: 900; color: #4a8fbf; font-family: monospace;
        }
        .card-cell.aberta { cursor: default; }
        .card-cell.diamante { background: rgba(56,189,248,.12); border-color: rgba(56,189,248,.4); }
        .card-cell.bomba { background: rgba(239,68,68,.1); border-color: rgba(239,68,68,.35); }
        .card-cell.disabled { cursor: not-allowed; opacity: .7; }
        .card-cell.shake { animation: shake .4s; }
        .card-cell.pop { animation: pop .35s; }

        @keyframes shake {
            0%,100%{transform:translateX(0)} 20%{transform:translateX(-6px)} 40%{transform:translateX(6px)} 60%{transform:translateX(-4px)} 80%{transform:translateX(4px)}
        }
        @keyframes pop {
            0%{transform:scale(1)} 50%{transform:scale(1.15)} 100%{transform:scale(1)}
        }

        .btn-sacar {
            width: 100%; padding: 14px; border-radius: 12px;
            background: rgba(34,197,94,.15); border: 2px solid rgba(34,197,94,.4);
            color: var(--green); font-size: 15px; font-weight: 800;
            cursor: pointer; transition: background .15s, transform .1s;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-sacar:hover:not(:disabled) { background: rgba(34,197,94,.25); transform: scale(1.01); }
        .btn-sacar:disabled { opacity: .4; cursor: not-allowed; transform: none; }

        .result-box {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 16px; padding: 24px; text-align: center; margin-bottom: 20px;
        }
        .result-box .emoji { font-size: 56px; margin-bottom: 12px; }
        .result-box .title { font-size: 20px; font-weight: 900; margin-bottom: 6px; }
        .result-box .subtitle { font-size: 14px; color: var(--text-2); }
        .result-box .big-points { font-size: 36px; font-weight: 900; color: var(--green); margin: 12px 0 4px; }

        .streak-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.3);
            border-radius: 999px; padding: 4px 12px; font-size: 12px; font-weight: 700; color: var(--amber);
            margin-bottom: 16px;
        }

        .legend { display: flex; gap: 16px; justify-content: center; margin-bottom: 16px; font-size: 12px; color: var(--text-2); }
        .legend span { display: flex; align-items: center; gap: 4px; }

        .volta-btn {
            display: block; text-align: center; padding: 10px;
            color: var(--text-2); text-decoration: none; font-size: 13px;
            border-radius: 8px; border: 1px solid var(--border);
            background: var(--surface); margin-top: 8px;
        }
        .volta-btn:hover { color: var(--text); background: #222; }

        /* Topbar */
        .topbar { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:10px 14px; background:#101013; border-bottom:1px solid rgba(255,255,255,.07); position:sticky; top:0; z-index:50; }
        .topbar-left { display:flex; align-items:center; gap:10px; }
        .back-btn { display:flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:9px; border:1px solid rgba(255,255,255,.07); background:transparent; color:#868690; text-decoration:none; font-size:14px; transition:.2s; flex-shrink:0; }
        .back-btn:hover { border-color:#ef4444; color:#ef4444; background:rgba(239,68,68,.1); }
        .game-title { font-size:15px; font-weight:800; color:#f0f0f3; }
        .game-title span { color:#ef4444; }
        .daily-badge { display:inline-flex; align-items:center; gap:4px; font-size:8px; font-weight:700; letter-spacing:.8px; text-transform:uppercase; padding:2px 8px; border-radius:999px; background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.25); color:#ef4444; margin-left:6px; }
        .topbar-right { display:flex; align-items:center; gap:6px; }
        .chip { display:flex; align-items:center; gap:4px; padding:4px 10px; border-radius:20px; background:#16161a; border:1px solid rgba(255,255,255,.07); font-size:11px; font-weight:700; color:#f0f0f3; white-space:nowrap; }

        .hint { font-size: 12px; color: var(--text-2); text-align: center; margin-bottom: 12px; }
    </style>
</head>
<body>
<div class="topbar">
  <div class="topbar-left">
    <a href="../games.php" class="back-btn" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    <span class="game-title">💣 <span>Bomba</span><span class="daily-badge"><i class="bi bi-calendar3"></i>Diário</span></span>
  </div>
  <div class="topbar-right">
    <div class="chip"><i class="bi bi-coin" style="color:#f59e0b"></i><?= number_format($meu_perfil['pontos'] ?? 0, 0, ',', '.') ?></div>
  </div>
</div>

<div class="game-wrapper">

    <?php if ($streak_atual > 0): ?>
    <div style="text-align:center;margin-bottom:12px">
        <span class="streak-badge"><i class="bi bi-fire"></i><?= $streak_atual ?> dia<?= $streak_atual > 1 ? 's' : '' ?> seguido<?= $streak_atual > 1 ? 's' : '' ?></span>
    </div>
    <?php endif; ?>

    <div style="text-align:center;margin-bottom:16px">
        <div style="font-size:28px;font-weight:900">💣 Bomba</div>
        <div style="font-size:12px;color:var(--text-2);margin-top:2px">Jogo diário · <?= date('d/m/Y') ?></div>
    </div>

    <?php if ($status === 'jogando'): ?>

    <div class="info-bar">
        <div class="info-card">
            <div class="label">Vidas</div>
            <div class="value lives" id="vidas">
                <?= str_repeat('❤️', $vidas) . str_repeat('🖤', $VIDAS_INICIAIS - $vidas) ?>
            </div>
        </div>
        <div class="info-card">
            <div class="label">Diamantes</div>
            <div class="value diamonds" id="diamantes-count"><?= $diamantes ?>/<?= $TOTAL_DIAMANTES ?></div>
        </div>
        <div class="info-card">
            <div class="label">Pontos</div>
            <div class="value points" id="pontos-count"><?= $pontos ?></div>
        </div>
    </div>

    <div class="legend">
        <span>💎 Diamante +<?= $PONTOS_POR_DIAMANTE ?>pts</span>
        <span>💣 Bomba −1 vida</span>
    </div>

    <div class="hint">Encontre diamantes e saque quando quiser. Se perder as 2 vidas, perde tudo!</div>

    <div class="grid" id="grid">
        <?php foreach ($tabuleiro as $q): ?>
        <div class="card-cell <?= $q['aberto'] ? ('aberta ' . $q['tipo']) : 'closed' ?>"
             data-pos="<?= $q['pos'] ?>"
             <?= ($q['aberto'] || $status !== 'jogando') ? 'data-disabled="1"' : '' ?>>
            <?php if ($q['aberto']): ?>
                <?= $q['tipo'] === 'diamante' ? '💎' : '💣' ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <button class="btn-sacar" id="btnSacar" <?= $pontos <= 0 ? 'disabled' : '' ?> onclick="sacar()">
        <i class="bi bi-cash-coin"></i>
        Sacar <?= $pontos ?> pontos
    </button>

    <?php elseif ($status === 'perdeu'): ?>

    <div class="result-box" style="border-color:rgba(239,68,68,.3)">
        <div class="emoji">💥</div>
        <div class="title" style="color:var(--red)">Você explodiu!</div>
        <div class="subtitle">Perdeu as 2 vidas. Nenhum ponto desta vez.</div>
        <div class="big-points" style="color:var(--red)">0 pts</div>
        <div style="font-size:12px;color:var(--text-2)">Você havia achado <?= $diamantes ?> diamante<?= $diamantes !== 1 ? 's' : '' ?></div>
    </div>

    <div class="grid">
        <?php foreach ($tabuleiro as $q): ?>
        <div class="card-cell aberta <?= $q['tipo'] ?>" data-disabled="1">
            <?= $q['tipo'] === 'diamante' ? '💎' : '💣' ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php elseif ($status === 'saiu'): ?>

    <div class="result-box" style="border-color:rgba(34,197,94,.3)">
        <div class="emoji">💰</div>
        <div class="title" style="color:var(--green)">Você sacou!</div>
        <div class="subtitle">Parabéns! Você saiu na hora certa.</div>
        <div class="big-points"><?= $pontos ?> pts</div>
        <div style="font-size:12px;color:var(--text-2)"><?= $diamantes ?> diamante<?= $diamantes !== 1 ? 's' : '' ?> encontrado<?= $diamantes !== 1 ? 's' : '' ?></div>
    </div>

    <div class="grid">
        <?php foreach ($tabuleiro as $q): ?>
        <div class="card-cell aberta <?= $q['tipo'] ?>" data-disabled="1">
            <?= $q['tipo'] === 'diamante' ? '💎' : '💣' ?>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <a href="../games.php" class="volta-btn"><i class="bi bi-arrow-left"></i> Voltar aos Jogos</a>
</div>

<script>
const STATUS_INICIAL = <?= json_encode($status) ?>;
let jogando = STATUS_INICIAL === 'jogando';
let vidasAtual = <?= $vidas ?>;
let diamantesAtual = <?= $diamantes ?>;
let pontosAtual = <?= $pontos ?>;
const PONTOS_POR_DIAMANTE = <?= $PONTOS_POR_DIAMANTE ?>;
const TOTAL_DIAMANTES = <?= $TOTAL_DIAMANTES ?>;
const VIDAS_INICIAIS = <?= $VIDAS_INICIAIS ?>;

function vidasHTML(v) {
    return '❤️'.repeat(v) + '🖤'.repeat(VIDAS_INICIAIS - v);
}

document.querySelectorAll('.card-cell.closed').forEach(el => {
    el.addEventListener('click', () => abrirCard(el));
});

async function abrirCard(el) {
    if (!jogando || el.dataset.disabled) return;

    const pos = el.dataset.pos;
    el.dataset.disabled = '1';

    const fd = new FormData();
    fd.append('acao', 'abrir');
    fd.append('pos', pos);

    const res = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();

    if (data.erro) { alert(data.erro); return; }

    // Revelar card
    el.classList.remove('closed');
    el.classList.add('aberta', data.tipo);
    el.innerHTML = data.tipo === 'diamante' ? '💎' : '💣';

    if (data.tipo === 'diamante') {
        el.classList.add('pop');
        el.addEventListener('animationend', () => el.classList.remove('pop'), { once: true });
    } else {
        el.classList.add('shake');
        el.addEventListener('animationend', () => el.classList.remove('shake'), { once: true });
    }

    // Atualizar UI
    vidasAtual = data.vidas;
    diamantesAtual = data.diamantes;
    pontosAtual = data.pontos;

    document.getElementById('vidas').innerHTML = vidasHTML(vidasAtual);
    document.getElementById('diamantes-count').textContent = diamantesAtual + '/' + TOTAL_DIAMANTES;
    document.getElementById('pontos-count').textContent = pontosAtual;

    const btnSacar = document.getElementById('btnSacar');
    if (btnSacar) {
        btnSacar.disabled = pontosAtual <= 0;
        btnSacar.innerHTML = `<i class="bi bi-cash-coin"></i> Sacar ${pontosAtual} pontos`;
    }

    if (data.status !== 'jogando') {
        jogando = false;
        document.querySelectorAll('.card-cell').forEach(c => c.dataset.disabled = '1');
        setTimeout(() => location.reload(), 1200);
    }

    // Achou todos os diamantes → mostrar alerta para sacar
    if (data.todos_diamantes && data.status === 'jogando') {
        setTimeout(() => {
            if (confirm('🎉 Você achou todos os diamantes! Deseja sacar ' + pontosAtual + ' pontos?')) {
                sacar();
            }
        }, 400);
    }
}

async function sacar() {
    if (!jogando || pontosAtual <= 0) return;
    jogando = false;

    const fd = new FormData();
    fd.append('acao', 'sacar');

    const btn = document.getElementById('btnSacar');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="margin-right:6px"></span>Sacando...'; }

    const res = await fetch('', { method: 'POST', body: fd });
    const data = await res.json();

    if (data.erro) { alert(data.erro); jogando = true; if (btn) { btn.disabled = false; btn.innerHTML = `<i class="bi bi-cash-coin"></i> Sacar ${pontosAtual} pontos`; } return; }

    setTimeout(() => location.reload(), 600);
}
</script>
</body>
</html>
