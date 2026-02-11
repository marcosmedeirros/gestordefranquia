<?php
// blackjack.php - CASSINO FBA games (21 ♠️♥️♣️♦️) - MODO REAL (LIMITE 15)
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require '../core/conexao.php';

// 1. Segurança
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];

// 2. Dados do Usuário
try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro DB: " . $e->getMessage());
}

// --- FUNÇÕES DE LÓGICA DO JOGO ---

function novoBaralho() {
    $naipes = ['♠', '♥', '♣', '♦'];
    $valores = ['2', '3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A'];
    $deck = [];
    foreach ($naipes as $n) {
        foreach ($valores as $v) {
            $deck[] = ['n' => $n, 'v' => $v];
        }
    }
    shuffle($deck);
    return $deck;
}

function calcularPontos($mao) {
    $pontos = 0;
    $ases = 0;
    foreach ($mao as $carta) {
        $v = $carta['v'];
        if (is_numeric($v)) {
            $pontos += intval($v);
        } elseif ($v == 'A') {
            $pontos += 11;
            $ases++;
        } else {
            $pontos += 10; // J, Q, K
        }
    }
    // Lógica do Ás
    while ($pontos > 21 && $ases > 0) {
        $pontos -= 10;
        $ases--;
    }
    return $pontos;
}

// --- API AJAX ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');
    $acao = $_POST['acao'];

    // A. INICIAR APOSTA (DEAL)
    if ($acao == 'apostar') {
        $valor = (int)$_POST['valor'];
        
        // VALIDAÇÃO DE LIMITE
        if ($valor <= 0) die(json_encode(['erro' => 'Valor inválido']));
        if ($valor > 15) die(json_encode(['erro' => 'Aposta máxima permitida: 15 pontos!']));
        
        try {
            // TRANSAÇÃO REAL: Desconta aposta inicial
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $user_id]);
            $saldo = $stmt->fetchColumn();

            if ($saldo < $valor) throw new Exception("Saldo insuficiente!");

            $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :id")->execute([':val' => $valor, ':id' => $user_id]);
            // Não comita ainda se for BJ instantâneo, tratamos abaixo, ou comita agora e faz update depois.
            // Vamos comitar o desconto agora para garantir.
            $pdo->commit(); 
            
            $saldo -= $valor; // Atualiza visual

            // Setup Inicial
            $deck = novoBaralho();
            $maos_jogador = [
                [
                    'cartas' => [array_shift($deck), array_shift($deck)],
                    'aposta' => $valor,
                    'status' => 'jogando'
                ]
            ];
            
            $dealerHand = [array_shift($deck), array_shift($deck)]; 

            $_SESSION['bj_game'] = [
                'deck' => $deck,
                'maos' => $maos_jogador,
                'dealer' => $dealerHand,
                'mao_atual' => 0, 
                'status_jogo' => 'jogando' 
            ];

            // Verifica Blackjack Instantâneo
            $ptsPlayer = calcularPontos($maos_jogador[0]['cartas']);
            $ptsDealer = calcularPontos($dealerHand);
            
            $msg = "";
            
            if ($ptsPlayer == 21) {
                $_SESSION['bj_game']['maos'][0]['status'] = 'blackjack';
                $_SESSION['bj_game']['status_jogo'] = 'fim';
                
                if ($ptsDealer == 21) {
                    // Empate: Devolve aposta
                    $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")->execute([':val' => $valor, ':id' => $user_id]);
                    $saldo += $valor;
                    $msg = "Empate! Ambos com Blackjack.";
                } else {
                    // Vitória BJ (3:2 = 2.5x aposta total retornada)
                    $premio = $valor * 2.5;
                    $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")->execute([':val' => $premio, ':id' => $user_id]);
                    $saldo += $premio;
                    $msg = "BLACKJACK! Você venceu!";
                }
            }

            $dealerShow = $_SESSION['bj_game']['status_jogo'] == 'fim' ? $dealerHand : [$dealerHand[0], ['n'=>'?', 'v'=>'?']];

            echo json_encode([
                'sucesso' => true,
                'maos' => $_SESSION['bj_game']['maos'],
                'mao_atual' => 0,
                'dealer' => $dealerShow,
                'status_jogo' => $_SESSION['bj_game']['status_jogo'],
                'msg' => $msg,
                'novo_saldo' => $saldo
            ]);

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['erro' => $e->getMessage()]);
        }
        exit;
    }

    // Validação de sessão
    if (!isset($_SESSION['bj_game']) || $_SESSION['bj_game']['status_jogo'] == 'fim') {
        echo json_encode(['erro' => 'Nenhum jogo ativo.']);
        exit;
    }

    $game = &$_SESSION['bj_game']; 
    $maoIndex = $game['mao_atual'];
    $maoAtiva = &$game['maos'][$maoIndex];

    // B. DIVIDIR (SPLIT) - COM CUSTO
    if ($acao == 'split') {
        if (count($maoAtiva['cartas']) != 2) { echo json_encode(['erro' => 'Só pode dividir com 2 cartas.']); exit; }
        
        $c1 = $maoAtiva['cartas'][0]['v'];
        $c2 = $maoAtiva['cartas'][1]['v'];
        
        if ($c1 !== $c2) { 
            echo json_encode(['erro' => 'Para dividir, as cartas precisam ser idênticas (Ex: 8-8, Q-Q)!']); 
            exit; 
        }

        // Cobra aposta adicional para a nova mão
        $valorSplit = $maoAtiva['aposta'];
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $user_id]);
            $saldo = $stmt->fetchColumn();

            if ($saldo < $valorSplit) throw new Exception("Saldo insuficiente para dividir!");

            $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :id")->execute([':val' => $valorSplit, ':id' => $user_id]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['erro' => $e->getMessage()]);
            exit;
        }

        $cartaSplit = array_pop($maoAtiva['cartas']); 
        
        $novaMao = [
            'cartas' => [$cartaSplit],
            'aposta' => $maoAtiva['aposta'],
            'status' => 'jogando'
        ];

        $game['maos'][] = $novaMao;

        // Distribui novas cartas
        $maoAtiva['cartas'][] = array_shift($game['deck']);
        $game['maos'][count($game['maos'])-1]['cartas'][] = array_shift($game['deck']);

        echo json_encode([
            'sucesso' => true,
            'maos' => $game['maos'],
            'mao_atual' => $game['mao_atual'],
            'dealer' => [$game['dealer'][0], ['n'=>'?', 'v'=>'?']],
            'status_jogo' => 'jogando'
        ]);
        exit;
    }

    // C. PEDIR CARTA (HIT)
    if ($acao == 'hit') {
        $carta = array_shift($game['deck']);
        $maoAtiva['cartas'][] = $carta;
        
        $pts = calcularPontos($maoAtiva['cartas']);
        
        if ($pts > 21) {
            $maoAtiva['status'] = 'estourou';
            avancarMao($game);
        } else if ($pts == 21) {
            $maoAtiva['status'] = 'stand'; 
            avancarMao($game);
        }

        retornarEstado($game);
        exit;
    }

    // D. PARAR (STAND)
    if ($acao == 'stand') {
        $maoAtiva['status'] = 'stand';
        avancarMao($game);
        retornarEstado($game);
        exit;
    }
}

// --- FUNÇÕES AUXILIARES DE FLUXO ---

function avancarMao(&$game) {
    if ($game['mao_atual'] < count($game['maos']) - 1) {
        $game['mao_atual']++;
    } else {
        $game['status_jogo'] = 'dealer_turn';
        jogarDealer($game);
    }
}

function jogarDealer(&$game) {
    global $pdo, $user_id;

    $todasEstouraram = true;
    foreach($game['maos'] as $m) { if($m['status'] != 'estourou') $todasEstouraram = false; }

    if (!$todasEstouraram) {
        $ptsDealer = calcularPontos($game['dealer']);
        while ($ptsDealer < 17) {
            $game['dealer'][] = array_shift($game['deck']);
            $ptsDealer = calcularPontos($game['dealer']);
        }
    }
    
    $game['status_jogo'] = 'fim';
    
    // PAGAMENTO DE PRÊMIOS
    $premioTotal = 0;
    $ptsDealer = calcularPontos($game['dealer']);

    foreach ($game['maos'] as $mao) {
        if ($mao['status'] == 'blackjack') continue; // Já pago no início (se BJ natural)
        
        $ptsPlayer = calcularPontos($mao['cartas']);
        $aposta = $mao['aposta'];

        if ($mao['status'] != 'estourou') {
            if ($ptsDealer > 21) {
                // Mesa estourou: Ganha (2x)
                $premioTotal += $aposta * 2;
            } elseif ($ptsPlayer > $ptsDealer) {
                // Pontuação maior: Ganha (2x)
                $premioTotal += $aposta * 2;
            } elseif ($ptsPlayer == $ptsDealer) {
                // Empate: Devolve (1x)
                $premioTotal += $aposta;
            }
        }
    }

    if ($premioTotal > 0) {
        $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")->execute([':val' => $premioTotal, ':id' => $user_id]);
    }
}

function retornarEstado($game) {
    $dealerShow = ($game['status_jogo'] == 'fim') ? $game['dealer'] : [$game['dealer'][0], ['n'=>'?', 'v'=>'?']];
    
    $msgFinal = "";
    if ($game['status_jogo'] == 'fim') {
        $ptsDealer = calcularPontos($game['dealer']);
        $resultados = [];
        
        foreach ($game['maos'] as $i => $mao) {
            $ptsPlayer = calcularPontos($mao['cartas']);
            $res = "";
            
            if ($mao['status'] == 'estourou') {
                $res .= "Você estourou ($ptsPlayer).";
            } elseif ($mao['status'] == 'blackjack') {
                $res .= "Você ganhou! Blackjack!";
            } else {
                if ($ptsDealer > 21) $res .= "Você ganhou! Mesa estourou.";
                elseif ($ptsPlayer > $ptsDealer) $res .= "Você ganhou ($ptsPlayer vs $ptsDealer).";
                elseif ($ptsPlayer < $ptsDealer) $res .= "Você perdeu ($ptsPlayer vs $ptsDealer).";
                else $res .= "Empate.";
            }
            $resultados[] = $res;
        }
        $msgFinal = implode("<br>", $resultados);
    }

    echo json_encode([
        'sucesso' => true,
        'maos' => $game['maos'],
        'mao_atual' => $game['mao_atual'],
        'dealer' => $dealerShow,
        'status_jogo' => $game['status_jogo'],
        'msg' => $msgFinal
    ]);
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blackjack - FBA games</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>♠️</text></svg>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .navbar-custom { background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%); border-bottom: 1px solid #333; padding: 15px; }
    .saldo-badge { background-color: #FC082B; color: #000; padding: 5px 15px; border-radius: 20px; font-weight: 800; }

        .bj-table {
            background: radial-gradient(circle, #004d40 0%, #00251a 100%);
            border: 15px solid #3e2723;
            border-radius: 100px;
            min-height: 550px;
            position: relative;
            box-shadow: inset 0 0 50px rgba(0,0,0,0.8), 0 10px 30px rgba(0,0,0,0.5);
            margin: 20px auto;
            max-width: 900px;
            display: flex; flex-direction: column; justify-content: space-between; padding: 40px;
        }

        .table-logo {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            font-family: 'Courier New', monospace; font-weight: bold; color: rgba(255,255,255,0.05);
            font-size: 4rem; pointer-events: none; text-align: center; white-space: nowrap;
        }

        /* ANIMAÇÃO DE CARTA CHEGANDO */
        @keyframes deal-card {
            0% { transform: translateY(-200px) translateX(50px) scale(0.5) rotate(45deg); opacity: 0; }
            100% { transform: translateY(0) translateX(0) scale(1) rotate(0deg); opacity: 1; }
        }
        .anim-deal { animation: deal-card 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards; }

        /* CARTAS */
        .card-bj {
            width: 75px; height: 110px; background: #fff; border-radius: 8px;
            display: inline-flex; flex-direction: column; justify-content: space-between; padding: 5px;
            margin: 0 5px; box-shadow: 2px 2px 8px rgba(0,0,0,0.4);
            font-family: 'Arial', sans-serif; font-weight: bold; font-size: 1.2rem;
            transition: transform 0.2s, box-shadow 0.2s;
            position: relative;
        }
        .card-bj:hover { transform: translateY(-10px); z-index: 10; }
        .suit-red { color: #d32f2f; }
        .suit-black { color: #212121; }
        .card-center { font-size: 2.2rem; align-self: center; }
        
        .card-back {
            background: repeating-linear-gradient(45deg, #b71c1c, #b71c1c 10px, #c62828 10px, #c62828 20px);
            border: 2px solid #fff; display: flex; align-items: center; justify-content: center;
        }
        .card-back::after { content: '♠'; font-size: 2rem; color: rgba(255,255,255,0.2); }

        .dealer-area { min-height: 140px; text-align: center; margin-bottom: 20px; }
        
        .player-hands-container {
            display: flex; justify-content: center; gap: 40px; align-items: flex-end; flex-wrap: wrap;
        }
        .player-hand {
            min-width: 150px; text-align: center; padding: 15px; border-radius: 15px;
            border: 2px solid transparent; transition: 0.3s;
            background: rgba(0,0,0,0.2);
        }
        .player-hand.active {
            border-color: #ffd700; background: rgba(255, 215, 0, 0.1);
            box-shadow: 0 0 20px rgba(255, 215, 0, 0.2); transform: scale(1.05);
        }
        .player-hand.busted { opacity: 0.6; filter: grayscale(0.8); }

        .score-bubble {
            background: rgba(0,0,0,0.7); color: #fff; padding: 3px 12px; border-radius: 15px;
            font-size: 0.9rem; margin-top: 10px; display: inline-block; border: 1px solid #555;
        }
        .active .score-bubble { background: #ffd700; color: #000; border-color: #fff; font-weight: bold; }

        .controls-area { text-align: center; margin-top: 20px; min-height: 80px; }
        .chip-btn {
            width: 65px; height: 65px; border-radius: 50%; border: 4px dashed white;
            font-weight: bold; color: white; display: inline-flex; align-items: center; justify-content: center;
            margin: 0 8px; cursor: pointer; transition: 0.2s; box-shadow: 0 5px 15px rgba(0,0,0,0.3); font-size: 0.9em;
        }
        .chip-btn:hover { transform: scale(1.1) rotate(10deg); }
        .chip-1 { background: #9e9e9e; color: #000; }
        .chip-5 { background: #d32f2f; }
        .chip-10 { background: #1976d2; }
        .chip-15 { background: #fbc02d; color: black; border-style: solid; border-color: #fff; }

        #msg-overlay {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            background: rgba(0,0,0,0.9); padding: 30px; border-radius: 15px;
            color: #fff; font-size: 1.2rem; border: 2px solid #ffd700;
            display: none; z-index: 20; text-align: center; width: 80%;
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

<div class="container mt-4">
    <div class="bj-table">
        <div class="table-logo">BLACKJACK<br><span class="fs-6 opacity-50">VALENDO PONTOS</span></div>
        
        <div id="msg-overlay"></div>

        <!-- DEALER -->
        <div class="dealer-area">
            <div id="dealer-cards"></div>
            <div class="score-bubble mt-2">MESA: <span id="dealer-score">0</span></div>
        </div>

        <!-- JOGADOR (MULTIPLAS MAOS) -->
        <div class="player-hands-container" id="player-hands-area">
            <!-- Renderizado via JS -->
        </div>
    </div>

    <!-- CONTROLES -->
    <div class="controls-area" id="bet-controls">
        <h5 class="text-white-50 mb-3">FAÇA SUA APOSTA (MÁX 15)</h5>
        <div class="d-flex justify-content-center">
            <div class="chip-btn chip-1" onclick="apostar(1)">1</div>
            <div class="chip-btn chip-5" onclick="apostar(5)">5</div>
            <div class="chip-btn chip-10" onclick="apostar(10)">10</div>
            <div class="chip-btn chip-15" onclick="apostar(15)">15</div>
        </div>
    </div>

    <div class="controls-area" id="game-controls" style="display: none;">
        <button class="btn btn-success btn-lg fw-bold px-4 me-2 shadow rounded-pill" onclick="acaoJogo('hit')">
            <i class="bi bi-plus-lg"></i> CARTA
        </button>
        <button class="btn btn-danger btn-lg fw-bold px-4 me-2 shadow rounded-pill" onclick="acaoJogo('stand')">
            <i class="bi bi-hand-thumbs-up-fill"></i> PARAR
        </button>
        <button id="btn-split" class="btn btn-warning btn-lg fw-bold px-4 shadow rounded-pill" onclick="acaoJogo('split')" style="display:none;">
            <i class="bi bi-arrows-angle-expand"></i> DIVIDIR
        </button>
    </div>
    
    <div class="controls-area" id="restart-controls" style="display: none;">
        <button class="btn btn-primary btn-lg fw-bold px-5 rounded-pill shadow" onclick="location.reload()">
            <i class="bi bi-arrow-repeat"></i> JOGAR NOVAMENTE
        </button>
    </div>
</div>

<script>
    const dealerContainer = document.getElementById('dealer-cards');
    const playerContainer = document.getElementById('player-hands-area');
    const dealerScoreEl = document.getElementById('dealer-score');
    const msgOverlay = document.getElementById('msg-overlay');

    function apostar(valor) {
        const fd = new FormData();
        fd.append('acao', 'apostar');
        fd.append('valor', valor);

        fetch('blackjack.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if(data.erro) { alert(data.erro); } 
            else {
                document.getElementById('saldoDisplay').innerText = data.novo_saldo.toLocaleString('pt-BR') + " pts";
                iniciarMesa(data);
            }
        });
    }

    function iniciarMesa(data) {
        document.getElementById('bet-controls').style.display = 'none';
        atualizarMesa(data);
    }

    function acaoJogo(tipo) {
        const fd = new FormData();
        fd.append('acao', tipo);

        fetch('blackjack.php', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if(data.erro) { alert(data.erro); return; }
            atualizarMesa(data);
        });
    }

    function atualizarMesa(data) {
        renderCartas(data.dealer, dealerContainer);
        
        let ptsDealer = 0;
        let oculta = false;
        data.dealer.forEach(c => {
            if(c.v === '?') oculta = true;
            else ptsDealer += getValorCarta(c.v);
        });
        dealerScoreEl.innerText = oculta ? "?" : calcularPontosReais(data.dealer);

        playerContainer.innerHTML = '';
        data.maos.forEach((mao, index) => {
            let activeClass = (index === data.mao_atual && data.status_jogo === 'jogando') ? 'active' : '';
            let bustedClass = (mao.status === 'estourou') ? 'busted' : '';
            let pts = calcularPontosReais(mao.cartas);
            
            let softLabel = (hasSoftAce(mao.cartas) && pts <= 21) ? '<small class="opacity-75">(Soft)</small>' : '';
            
            let handHtml = `
                <div class="player-hand ${activeClass} ${bustedClass}">
                    <div class="cards-container mb-2">
                        ${getCartasHtml(mao.cartas)}
                    </div>
                    <div class="score-bubble">
                        ${mao.status === 'estourou' ? 'ESTOUROU' : pts + ' ' + softLabel}
                    </div>
                </div>
            `;
            playerContainer.innerHTML += handHtml;
        });

        if(data.status_jogo === 'jogando') {
            document.getElementById('game-controls').style.display = 'block';
            let btnSplit = document.getElementById('btn-split');
            let maoAtiva = data.maos[data.mao_atual];
            // Regra rigorosa para botão Split aparecer
            if(maoAtiva.cartas.length === 2 && maoAtiva.cartas[0].v === maoAtiva.cartas[1].v) {
                btnSplit.style.display = 'inline-block';
            } else {
                btnSplit.style.display = 'none';
            }
        } else {
            finalizarJogo(data.msg);
        }
    }

    function finalizarJogo(msg) {
        document.getElementById('game-controls').style.display = 'none';
        document.getElementById('restart-controls').style.display = 'block';
        msgOverlay.innerHTML = msg;
        msgOverlay.style.display = 'block';
        
        // Atualiza saldo se houve vitória/empate
        // Recarregar a página já atualiza, mas podemos fazer um fetch silencioso se quiser
        // A lógica do back já atualizou o saldo.
    }

    function getCartasHtml(cartas) {
        let html = '';
        cartas.forEach((c, i) => {
            if(c.v === '?') {
                html += `<div class="card-bj card-back anim-deal" style="animation-delay: ${i*0.1}s"></div>`;
            } else {
                let suitClass = (c.n === '♥' || c.n === '♦') ? 'suit-red' : 'suit-black';
                html += `
                    <div class="card-bj ${suitClass} anim-deal" style="animation-delay: ${i*0.1}s">
                        <div style="align-self: flex-start; line-height:1;">${c.v}</div>
                        <div class="card-center">${c.n}</div>
                        <div style="align-self: flex-end; transform: rotate(180deg); line-height:1;">${c.v}</div>
                    </div>
                `;
            }
        });
        return html;
    }

    function renderCartas(cartas, el) { el.innerHTML = getCartasHtml(cartas); }

    function getValorCarta(v) {
        if(v === 'A') return 11;
        if(['J','Q','K'].includes(v)) return 10;
        return parseInt(v) || 0;
    }

    function calcularPontosReais(cartas) {
        let pts = 0; let ases = 0;
        cartas.forEach(c => {
            let v = getValorCarta(c.v);
            pts += v;
            if(c.v === 'A') ases++;
        });
        while(pts > 21 && ases > 0) { pts -= 10; ases--; }
        return pts;
    }
    
    function hasSoftAce(cartas) {
        let pts = 0; let ases = 0;
        cartas.forEach(c => {
            pts += getValorCarta(c.v);
            if(c.v === 'A') ases++;
        });
        let somaHard = 0;
        cartas.forEach(c => somaHard += (c.v === 'A' ? 1 : getValorCarta(c.v)));
        return calcularPontosReais(cartas) > somaHard;
    }
</script>

</body>
</html>
