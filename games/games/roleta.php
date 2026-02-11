<?php
// roleta.php - CASSINO PIKAFUMO (ROLETA EUROPEIA 🎡) - MODO REAL
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require '../core/conexao.php';

// 1. Segurança
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
$user_id = $_SESSION['user_id'];

// 2. Dados do Usuário
try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro DB: " . $e->getMessage());
}

// --- LÓGICA DA ROLETA ---
// Ordem da Roleta Europeia (0 no topo, sentido horário)
$ROULETTE_ORDER = [0, 32, 15, 19, 4, 21, 2, 25, 17, 34, 6, 27, 13, 36, 11, 30, 8, 23, 10, 5, 24, 16, 33, 1, 20, 14, 31, 9, 22, 18, 29, 7, 28, 12, 35, 3, 26];
$RED_NUMBERS = [1, 3, 5, 7, 9, 12, 14, 16, 18, 19, 21, 23, 25, 27, 30, 32, 34, 36];

function getCor($n) {
    global $RED_NUMBERS;
    if ($n == 0) return 'green';
    return in_array($n, $RED_NUMBERS) ? 'red' : 'black';
}

// --- API AJAX ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');
    $acao = $_POST['acao'];

    if ($acao == 'girar') {
        $apostas = json_decode($_POST['apostas'], true); 
        
        // 1. Validação de Aposta
        $totalApostado = 0;
        foreach ($apostas as $a) $totalApostado += $a['montante'];
        
        if ($totalApostado <= 0) die(json_encode(['erro' => 'Faça uma aposta!']));

        try {
            $pdo->beginTransaction();

            // 2. Verifica e Desconta Saldo
            $stmt = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $user_id]);
            $saldoAtual = $stmt->fetchColumn();

            if ($saldoAtual < $totalApostado) throw new Exception("Saldo insuficiente!");

            // Desconta a aposta
            $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :id")->execute([':val' => $totalApostado, ':id' => $user_id]);

            // 3. Sorteio
            $numeroSorteado = $ROULETTE_ORDER[array_rand($ROULETTE_ORDER)];
            $corSorteada = getCor($numeroSorteado);

            // 4. Cálculo de Prêmios
            $totalGanho = 0;

            foreach ($apostas as $aposta) {
                $tipo = $aposta['tipo']; 
                $alvo = $aposta['valor'];
                $valor = $aposta['montante'];
                $ganhou = false;
                $multiplicador = 0;

                switch ($tipo) {
                    case 'number':
                        if ($numeroSorteado == $alvo) { $ganhou = true; $multiplicador = 36; } // Paga 35:1 + aposta = 36x
                        break;
                    case 'color':
                        if ($corSorteada == $alvo) { $ganhou = true; $multiplicador = 2; }
                        break;
                    case 'parity':
                        if ($numeroSorteado != 0) {
                            if ($alvo == 'even' && $numeroSorteado % 2 == 0) { $ganhou = true; $multiplicador = 2; }
                            if ($alvo == 'odd' && $numeroSorteado % 2 != 0) { $ganhou = true; $multiplicador = 2; }
                        }
                        break;
                    case 'dozen':
                        if ($numeroSorteado != 0) {
                            if ($alvo == 1 && $numeroSorteado >= 1 && $numeroSorteado <= 12) { $ganhou = true; $multiplicador = 3; }
                            if ($alvo == 2 && $numeroSorteado >= 13 && $numeroSorteado <= 24) { $ganhou = true; $multiplicador = 3; }
                            if ($alvo == 3 && $numeroSorteado >= 25 && $numeroSorteado <= 36) { $ganhou = true; $multiplicador = 3; }
                        }
                        break;
                    case 'half':
                        if ($numeroSorteado != 0) {
                            if ($alvo == 1 && $numeroSorteado <= 18) { $ganhou = true; $multiplicador = 2; }
                            if ($alvo == 2 && $numeroSorteado >= 19) { $ganhou = true; $multiplicador = 2; }
                        }
                        break;
                }

                if ($ganhou) {
                    $totalGanho += $valor * $multiplicador;
                }
            }

            // Paga prêmios
            if ($totalGanho > 0) {
                $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")->execute([':val' => $totalGanho, ':id' => $user_id]);
            }

            $pdo->commit();

            echo json_encode([
                'sucesso' => true,
                'numero' => $numeroSorteado,
                'cor' => $corSorteada,
                'total_ganho' => $totalGanho,
                'saldo_atual' => ($saldoAtual - $totalApostado + $totalGanho)
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['erro' => $e->getMessage()]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roleta - Pikafumo Games</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🎡</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .navbar-custom { background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%); border-bottom: 1px solid #333; padding: 15px; }
        .saldo-badge { background-color: #00e676; color: #000; padding: 5px 15px; border-radius: 20px; font-weight: 800; }

        /* MESA DE APOSTAS */
        .betting-table {
            background-color: #004d40; border: 8px solid #3e2723; border-radius: 10px;
            padding: 20px; max-width: 900px; margin: 0 auto; user-select: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }

        .bet-grid { display: grid; grid-template-columns: 50px repeat(12, 1fr); gap: 2px; }
        
        .bet-cell {
            background-color: #2e7d32; border: 1px solid #4caf50; height: 60px;
            display: flex; align-items: center; justify-content: center;
            font-weight: bold; color: white; cursor: pointer; transition: 0.2s;
            position: relative;
        }
        .bet-cell:hover { filter: brightness(1.2); }
        .cell-red { background-color: #d32f2f; border-color: #ef5350; }
        .cell-black { background-color: #212121; border-color: #424242; }
        .cell-zero { grid-row: 1 / span 3; height: auto; background-color: #2e7d32; }
        
        .bet-option { background-color: #004d40; border: 1px solid #80cbc4; font-size: 0.8em; }
        .bet-option:hover { background-color: #00695c; }

        /* FICHAS */
        .chip {
            width: 30px; height: 30px; border-radius: 50%; border: 3px dashed white;
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            display: flex; align-items: center; justify-content: center; font-size: 0.7em;
            color: white; font-weight: bold; box-shadow: 0 3px 5px rgba(0,0,0,0.5); pointer-events: none; z-index: 5;
        }
        .chip-1 { background: #9e9e9e; }
        .chip-5 { background: #d32f2f; }
        .chip-10 { background: #1976d2; }
        .chip-25 { background: #7b1fa2; color: white; border-style: solid; }
        .chip-50 { background: #ff6f00; color: white; border-style: solid; }

        /* SELETOR DE FICHAS */
        .chip-selector { display: flex; justify-content: center; gap: 15px; margin: 20px 0; }
        .chip-btn {
            width: 60px; height: 60px; border-radius: 50%; border: 4px dashed white; cursor: pointer;
            display: flex; align-items: center; justify-content: center; font-weight: bold; color: white;
            transition: transform 0.2s; opacity: 0.7;
        }
        .chip-btn:hover { transform: scale(1.1); opacity: 1; }
        .chip-btn.active { transform: scale(1.1) translateY(-5px); opacity: 1; box-shadow: 0 0 15px #ffd700; border-color: #ffd700; }

        /* RODA CANVAS */
        #wheel-container { position: relative; width: 320px; height: 320px; margin: 0 auto 30px; }
        #wheel-canvas { 
            width: 100%; height: 100%; border-radius: 50%; 
            transition: transform 4s cubic-bezier(0.1, 0.7, 0.1, 1); 
            box-shadow: 0 0 30px #000; border: 8px solid #2c2c2c;
        }
        #wheel-arrow {
            position: absolute; top: -15px; left: 50%; transform: translateX(-50%);
            width: 0; height: 0; border-left: 15px solid transparent; border-right: 15px solid transparent; border-top: 25px solid #ffd700; 
            z-index: 10; filter: drop-shadow(0 2px 2px rgba(0,0,0,0.5));
        }
        
        .result-overlay {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            background: rgba(0,0,0,0.9); padding: 20px 40px; border-radius: 15px; border: 2px solid #ffd700;
            text-align: center; display: none; z-index: 20; min-width: 200px; box-shadow: 0 0 50px rgba(0,0,0,0.8);
        }
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

<div class="container mt-4 pb-5">
    <div class="text-center mb-4">
        <h1 class="display-4 fw-bold text-white d-flex align-items-center justify-content-center gap-2">
            🎡 ROLETA
            <i class="bi bi-info-circle cursor-pointer text-info" onclick="togglePayouts()" style="font-size: 1.2em; opacity: 0.8;"></i>
        </h1>
        <p class="text-muted">Apostas</p>
    </div>
    
    <!-- Modal de Payouts -->
    <div id="payoutsModal" class="modal fade" tabindex="-1" style="display: none;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-info"><i class="bi bi-bar-chart-fill me-2"></i>Tabela de Payouts</h5>
                    <button type="button" class="btn-close btn-close-white" onclick="togglePayouts()"></button>
                </div>
                <div class="modal-body text-light">
                    <div class="list-group list-group-flush bg-dark">
                        <div class="list-group-item bg-dark border-secondary d-flex justify-content-between align-items-center py-3">
                            <span><i class="bi bi-circle-fill text-danger me-2"></i>Cor (Vermelho/Preto)</span>
                            <span class="badge bg-warning text-dark fw-bold">2x</span>
                        </div>
                        <div class="list-group-item bg-dark border-secondary d-flex justify-content-between align-items-center py-3">
                            <span><i class="bi bi-dice-1-fill me-2"></i>Número (0-36)</span>
                            <span class="badge bg-danger fw-bold">36x</span>
                        </div>
                        <div class="list-group-item bg-dark border-secondary d-flex justify-content-between align-items-center py-3">
                            <span><i class="bi bi-grid-3x2-gap-fill me-2"></i>Dúzia (1-12, 13-24, 25-36)</span>
                            <span class="badge bg-success fw-bold">3x</span>
                        </div>
                        <div class="list-group-item bg-dark border-secondary d-flex justify-content-between align-items-center py-3">
                            <span><i class="bi bi-arrow-left-right me-2"></i>Metade (1-18, 19-36)</span>
                            <span class="badge bg-warning text-dark fw-bold">2x</span>
                        </div>
                        <div class="list-group-item bg-dark border-secondary d-flex justify-content-between align-items-center py-3">
                            <span><i class="bi bi-activity me-2"></i>Paridade (Par/Ímpar)</span>
                            <span class="badge bg-warning text-dark fw-bold">2x</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- RODA -->
    <div id="wheel-container">
        <div id="wheel-arrow"></div>
        <canvas id="wheel-canvas" width="400" height="400"></canvas>
        
        <div id="result-overlay" class="result-overlay">
            <h1 class="display-3 mb-0 fw-bold" id="res-num">0</h1>
            <div id="res-color" class="badge bg-success mb-2">ZERO</div>
            <div id="res-win" class="text-warning fw-bold fs-5"></div>
        </div>
    </div>

    <!-- MESA DE APOSTAS -->
    <div class="betting-table">
        <!-- Números -->
        <div class="bet-grid mb-2">
            <div class="bet-cell cell-zero" onclick="placeBet('number', 0, this)">0</div>
            <?php 
            $nums = [
                [3,6,9,12,15,18,21,24,27,30,33,36],
                [2,5,8,11,14,17,20,23,26,29,32,35],
                [1,4,7,10,13,16,19,22,25,28,31,34]
            ];
            foreach($nums as $row) {
                foreach($row as $n) {
                    $color = in_array($n, $RED_NUMBERS) ? 'cell-red' : 'cell-black';
                    echo "<div class='bet-cell $color' onclick=\"placeBet('number', $n, this)\">$n</div>";
                }
            }
            ?>
        </div>

        <!-- Apostas Externas -->
        <div class="d-flex gap-1 mb-1">
            <div style="width: 50px;"></div>
            <div class="flex-grow-1 d-flex gap-1">
                <div class="bet-cell bet-option flex-grow-1" onclick="placeBet('dozen', 1, this)">1ª 12</div>
                <div class="bet-cell bet-option flex-grow-1" onclick="placeBet('dozen', 2, this)">2ª 12</div>
                <div class="bet-cell bet-option flex-grow-1" onclick="placeBet('dozen', 3, this)">3ª 12</div>
            </div>
        </div>
        <div class="d-flex gap-1">
            <div style="width: 50px;"></div>
            <div class="flex-grow-1 d-flex gap-1">
                <div class="bet-cell bet-option flex-grow-1" onclick="placeBet('half', 1, this)">1-18</div>
                <div class="bet-cell bet-option flex-grow-1" onclick="placeBet('parity', 'even', this)">PAR</div>
                <div class="bet-cell cell-red flex-grow-1" onclick="placeBet('color', 'red', this)">🔴</div>
                <div class="bet-cell cell-black flex-grow-1" onclick="placeBet('color', 'black', this)">⚫</div>
                <div class="bet-cell bet-option flex-grow-1" onclick="placeBet('parity', 'odd', this)">ÍMPAR</div>
                <div class="bet-cell bet-option flex-grow-1" onclick="placeBet('half', 2, this)">19-36</div>
            </div>
        </div>
    </div>

    <!-- CONTROLES -->
    <div class="chip-selector">
        <div class="chip-btn chip-1 active" onclick="selectChip(1, this)">1</div>
        <div class="chip-btn chip-5" onclick="selectChip(5, this)">5</div>
        <div class="chip-btn chip-10" onclick="selectChip(10, this)">10</div>
        <div class="chip-btn chip-25" onclick="selectChip(25, this)">25</div>
        <div class="chip-btn chip-50" onclick="selectChip(50, this)">50</div>
    </div>

    <div class="text-center mt-3 d-flex justify-content-center gap-3">
        <button class="btn btn-outline-secondary rounded-pill px-4" onclick="limparApostas()">LIMPAR</button>
        <button id="btn-spin" class="btn btn-warning btn-lg fw-bold px-5 rounded-pill shadow" onclick="girarRoleta()">
            GIRAR (<span id="total-bet">0</span>)
        </button>
    </div>
</div>

<script>
    let currentChip = 1;
    let bets = []; 
    let isSpinning = false;
    let currentRotation = 0;

    // Função para abrir/fechar modal de payouts
    function togglePayouts() {
        const modal = document.getElementById('payoutsModal');
        const isHidden = modal.style.display === 'none';
        modal.style.display = isHidden ? 'block' : 'none';
        if (isHidden) {
            modal.classList.add('show');
        } else {
            modal.classList.remove('show');
        }
    }

    // Fechar modal ao clicar fora
    document.addEventListener('click', function(event) {
        const modal = document.getElementById('payoutsModal');
        if (modal && event.target === modal) {
            modal.style.display = 'none';
            modal.classList.remove('show');
        }
    });

    // Ordem exata da roda para desenho e cálculo
    const wheelOrder = [0, 32, 15, 19, 4, 21, 2, 25, 17, 34, 6, 27, 13, 36, 11, 30, 8, 23, 10, 5, 24, 16, 33, 1, 20, 14, 31, 9, 22, 18, 29, 7, 28, 12, 35, 3, 26];
    const redNumbers = [1, 3, 5, 7, 9, 12, 14, 16, 18, 19, 21, 23, 25, 27, 30, 32, 34, 36];

    // --- FUNÇÕES DE DESENHO (CANVAS) ---
    function drawRoulette() {
        const canvas = document.getElementById('wheel-canvas');
        const ctx = canvas.getContext('2d');
        const W = canvas.width;
        const H = canvas.height;
        const CX = W/2;
        const CY = H/2;
        const R = W/2 - 10;
        const sliceDeg = 360 / 37;
        const sliceRad = (Math.PI * 2) / 37;

        // Limpa
        ctx.clearRect(0,0,W,H);

        // Desenha as fatias
        // Ajuste inicial: O 0 deve estar no topo. Em Canvas, 0 rad é 3 horas (direita).
        // -PI/2 é 12 horas (topo).
        // Vamos desenhar o 0 no topo.
        
        for(let i=0; i<37; i++) {
            let num = wheelOrder[i];
            let startAngle = (i * sliceRad) - (Math.PI/2) - (sliceRad/2);
            let endAngle = startAngle + sliceRad;

            // Define cor
            if (num === 0) ctx.fillStyle = '#008000'; // Verde
            else if (redNumbers.includes(num)) ctx.fillStyle = '#d32f2f'; // Vermelho
            else ctx.fillStyle = '#212121'; // Preto

            ctx.beginPath();
            ctx.moveTo(CX, CY);
            ctx.arc(CX, CY, R, startAngle, endAngle);
            ctx.closePath();
            ctx.fill();
            ctx.stroke();

            // Texto
            ctx.save();
            ctx.translate(CX, CY);
            ctx.rotate(startAngle + sliceRad/2);
            ctx.textAlign = "right";
            ctx.fillStyle = "#fff";
            ctx.font = "bold 18px Arial";
            ctx.fillText(num, R - 15, 6);
            ctx.restore();
        }

        // Centro decorativo
        ctx.beginPath();
        ctx.arc(CX, CY, R * 0.4, 0, Math.PI * 2);
        ctx.fillStyle = '#e0e0e0'; // Prata
        ctx.fill();
        ctx.beginPath();
        ctx.arc(CX, CY, R * 0.35, 0, Math.PI * 2);
        ctx.fillStyle = '#111'; // Preto
        ctx.fill();
    }

    // --- FUNÇÕES DO JOGO ---
    
    function selectChip(val, el) {
        currentChip = val;
        document.querySelectorAll('.chip-btn').forEach(b => b.classList.remove('active'));
        el.classList.add('active');
    }

    function placeBet(tipo, valor, el) {
        if (isSpinning) return;

        let total = bets.reduce((sum, b) => sum + b.montante, 0);
        // Sem limite de apostas

        let existing = bets.find(b => b.tipo === tipo && b.valor === valor);
        if (existing) {
            existing.montante += currentChip;
            updateChipVisual(existing);
        } else {
            let newBet = { tipo, valor, montante: currentChip, el };
            bets.push(newBet);
            addChipVisual(newBet);
        }
        updateTotal();
    }

    function addChipVisual(bet) {
        let chip = document.createElement('div');
        chip.className = `chip chip-${getChipColorClass(bet.montante)}`;
        chip.innerText = bet.montante;
        bet.el.appendChild(chip);
        bet.chipEl = chip;
    }

    function updateChipVisual(bet) {
        bet.chipEl.className = `chip chip-${getChipColorClass(bet.montante)}`;
        bet.chipEl.innerText = bet.montante;
    }

    function getChipColorClass(val) {
        if (val >= 15) return '15';
        if (val >= 10) return '10';
        if (val >= 5) return '5';
        return '1';
    }

    function updateTotal() {
        let total = bets.reduce((sum, b) => sum + b.montante, 0);
        document.getElementById('total-bet').innerText = total;
    }

    function limparApostas() {
        if (isSpinning) return;
        bets.forEach(b => b.chipEl.remove());
        bets = [];
        updateTotal();
        document.getElementById('result-overlay').style.display = 'none';
    }

    function girarRoleta() {
        if (bets.length === 0) { alert("Faça uma aposta!"); return; }
        if (isSpinning) return;

        isSpinning = true;
        document.getElementById('btn-spin').disabled = true;
        document.getElementById('result-overlay').style.display = 'none';

        const fd = new FormData();
        fd.append('acao', 'girar');
        fd.append('apostas', JSON.stringify(bets));

        fetch('roleta.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (data.erro) {
                alert(data.erro);
                isSpinning = false;
                document.getElementById('btn-spin').disabled = false;
                return;
            }
            spinWheel(data);
        });
    }

    function spinWheel(data) {
        const wheel = document.getElementById('wheel-canvas');
        const sliceDeg = 360 / 37;
        const targetIndex = wheelOrder.indexOf(parseInt(data.numero));
        const spins = 5; // Voltas completas
        
        // CÁLCULO DE SINCRONIA EXATO
        const numberAngle = targetIndex * sliceDeg;
        const targetRotation = (360 - numberAngle); 
        
        let finalAngle = targetRotation + (spins * 360);
        
        // Pequena variação para não cair sempre no centro exato da fatia
        let noise = (Math.random() - 0.5) * (sliceDeg * 0.8);
        finalAngle += noise;

        // Acumula rotação anterior para não voltar bruscamente
        let diff = finalAngle - (currentRotation % 360);
        if(diff < 0) diff += 360; // Garante giro sempre pra frente
        
        currentRotation += diff + (spins * 360); 

        wheel.style.transform = `rotate(${currentRotation}deg)`;

        setTimeout(() => {
            showResult(data);
            isSpinning = false;
            document.getElementById('btn-spin').disabled = false;
        }, 4000);
    }

    function showResult(data) {
        const overlay = document.getElementById('result-overlay');
        const numEl = document.getElementById('res-num');
        const colEl = document.getElementById('res-color');
        const winEl = document.getElementById('res-win');

        numEl.innerText = data.numero;
        
        let corText = 'VERDE';
        let corClass = 'bg-success';
        if (data.cor === 'red') { corText = 'VERMELHO'; corClass = 'bg-danger'; }
        if (data.cor === 'black') { corText = 'PRETO'; corClass = 'bg-dark border border-secondary'; }
        
        colEl.className = `badge ${corClass} mb-2`;
        colEl.innerText = corText;

        if (data.total_ganho > 0) {
            winEl.className = 'text-warning fw-bold fs-5';
            winEl.innerText = `GANHOU +${data.total_ganho}!`;
            document.getElementById('saldoDisplay').innerText = data.saldo_atual.toLocaleString('pt-BR') + " pts";
        } else {
            winEl.className = 'text-white-50 fs-6';
            winEl.innerText = 'Não foi dessa vez...';
            document.getElementById('saldoDisplay').innerText = data.saldo_atual.toLocaleString('pt-BR') + " pts";
        }

        overlay.style.display = 'block';
        setTimeout(() => { limparApostas(); }, 3000);
    }

    // Inicializa desenho
    drawRoulette();
</script>

</body>
</html>