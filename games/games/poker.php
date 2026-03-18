<?php
// poker.php - TEXAS HOLD'EM MULTIPLAYER COMPLETO
ini_set('display_errors', 1);
error_reporting(E_ALL);
// session_start já foi chamado em games/index.php
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];
$sala_id = 1; 

try {
    $stmtMe = $pdo->prepare("SELECT id, nome, pontos, fba_points FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro DB: " . $e->getMessage());
}

// --- FUNÇÕES AUXILIARES DE LÓGICA ---
function criar_baralho() {
    $naipes = ['h', 'd', 'c', 's'];
    $valores = ['2', '3', '4', '5', '6', '7', '8', '9', 'T', 'J', 'Q', 'K', 'A'];
    $deck = [];
    foreach ($naipes as $n) {
        foreach ($valores as $v) {
            $deck[] = $v . $n;
        }
    }
    shuffle($deck);
    return $deck;
}

function proximo_turno($pdo, $sala_id, $pos_atual) {
    $stmt = $pdo->prepare("SELECT posicao, bet_round FROM poker_jogadores WHERE id_sala = :s AND status IN ('ativo', 'all-in') ORDER BY posicao ASC");
    $stmt->execute([':s' => $sala_id]);
    $jogadores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($jogadores) <= 1) return null; // Só sobrou um, jogo acaba
    
    // Pega a próxima posição
    $proxima_pos = -1;
    foreach ($jogadores as $j) {
        if ($j['posicao'] > $pos_atual) { $proxima_pos = $j['posicao']; break; }
    }
    if ($proxima_pos == -1) $proxima_pos = $jogadores[0]['posicao']; // Volta pro início da mesa
    
    return $proxima_pos;
}

function checar_fim_de_rodada($pdo, $sala_id, $bet_atual) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as ativos, SUM(CASE WHEN bet_round = :b THEN 1 ELSE 0 END) as igualados FROM poker_jogadores WHERE id_sala = :s AND status = 'ativo'");
    $stmt->execute([':b' => $bet_atual, ':s' => $sala_id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Se todos os ativos igualaram a aposta
    return ($res['ativos'] > 0 && $res['ativos'] == $res['igualados']);
}

// --- API AJAX ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');
    $acao = $_POST['acao'];

    try {
        // 1. BUSCAR MESA
        if ($acao == 'buscar_mesa') {
            $stmtSala = $pdo->prepare("SELECT * FROM poker_salas WHERE id = :id");
            $stmtSala->execute([':id' => $sala_id]);
            $sala = $stmtSala->fetch(PDO::FETCH_ASSOC);

            $stmtJogadores = $pdo->prepare("SELECT * FROM poker_jogadores WHERE id_sala = :id ORDER BY posicao ASC");
            $stmtJogadores->execute([':id' => $sala_id]);
            $jogadores = $stmtJogadores->fetchAll(PDO::FETCH_ASSOC);

            $dados = ['sala' => $sala, 'jogadores' => [], 'meu_lugar' => null, 'ativos' => 0];

            foreach ($jogadores as $j) {
                if ($j['id_usuario'] != $user_id && $sala['stage'] != 'showdown' && $j['cards'] != '') {
                    $j['cards'] = '??,??';
                }
                $dados['jogadores'][$j['posicao']] = $j;
                if ($j['id_usuario'] == $user_id) $dados['meu_lugar'] = $j['posicao'];
                if ($j['status'] != 'ausente') $dados['ativos']++;
            }
            echo json_encode(['sucesso' => true, 'dados' => $dados]);
            exit;
        }

        // 2. SENTAR/LEVANTAR
        if ($acao == 'sentar') {
            $pos = (int)$_POST['posicao'];
            $buy_in = 500; 
            if ($meu_perfil['pontos'] < $buy_in) throw new Exception("Saldo insuficiente.");

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :id")->execute([':val' => $buy_in, ':id' => $user_id]);
            $pdo->prepare("INSERT INTO poker_jogadores (id_sala, id_usuario, nome, chips, status, posicao) VALUES (:sala, :uid, :nome, :chips, 'ativo', :pos)")
                ->execute([':sala' => $sala_id, ':uid' => $user_id, ':nome' => $meu_perfil['nome'], ':chips' => $buy_in, ':pos' => $pos]);
            $pdo->commit();
            echo json_encode(['sucesso' => true]); exit;
        }

        if ($acao == 'levantar') {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT chips FROM poker_jogadores WHERE id_sala = :sala AND id_usuario = :uid FOR UPDATE");
            $stmt->execute([':sala' => $sala_id, ':uid' => $user_id]);
            $meuAssento = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($meuAssento) {
                $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")->execute([':val' => $meuAssento['chips'], ':id' => $user_id]);
                $pdo->prepare("DELETE FROM poker_jogadores WHERE id_sala = :sala AND id_usuario = :uid")->execute([':sala' => $sala_id, ':uid' => $user_id]);
            }
            $pdo->commit();
            echo json_encode(['sucesso' => true]); exit;
        }

        // 3. INICIAR JOGO
        if ($acao == 'iniciar') {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM poker_jogadores WHERE id_sala = :id");
            $stmt->execute([':id' => $sala_id]);
            $jogadores = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($jogadores) < 2) throw new Exception("Mínimo de 2 jogadores para iniciar.");

            $deck = criar_baralho();
            
            // Dá as cartas
            foreach ($jogadores as $j) {
                $c1 = array_pop($deck); $c2 = array_pop($deck);
                $pdo->prepare("UPDATE poker_jogadores SET cards = :c, status = 'ativo', bet_round = 0 WHERE id = :id")
                    ->execute([':c' => "$c1,$c2", ':id' => $j['id']]);
            }

            $primeiro_turno = $jogadores[0]['posicao'];

            $pdo->prepare("UPDATE poker_salas SET status = 'jogando', stage = 'pre-flop', pote = 0, bet_atual = 0, community_cards = '', deck = :deck, turno_posicao = :turno, vencedor_info = NULL WHERE id = :id")
                ->execute([':deck' => implode(',', $deck), ':turno' => $primeiro_turno, ':id' => $sala_id]);

            $pdo->commit();
            echo json_encode(['sucesso' => true]); exit;
        }

        // 4. AÇÃO POKER (FOLD, CALL, RAISE)
        if ($acao == 'acao_poker') {
            $tipo = $_POST['tipo'];
            $valor_raise = isset($_POST['valor']) ? (int)$_POST['valor'] : 0;

            $pdo->beginTransaction();
            
            $stmtSala = $pdo->prepare("SELECT * FROM poker_salas WHERE id = :id FOR UPDATE");
            $stmtSala->execute([':id' => $sala_id]);
            $sala = $stmtSala->fetch(PDO::FETCH_ASSOC);

            $stmtEu = $pdo->prepare("SELECT * FROM poker_jogadores WHERE id_sala = :sala AND id_usuario = :uid FOR UPDATE");
            $stmtEu->execute([':sala' => $sala_id, ':uid' => $user_id]);
            $eu = $stmtEu->fetch(PDO::FETCH_ASSOC);

            if ($sala['turno_posicao'] != $eu['posicao']) throw new Exception("Não é o seu turno.");

            $pote_add = 0;
            $novo_bet_round = $eu['bet_round'];
            $nova_bet_atual = $sala['bet_atual'];

            if ($tipo == 'fold') {
                $pdo->prepare("UPDATE poker_jogadores SET status = 'fold' WHERE id = :id")->execute([':id' => $eu['id']]);
            } 
            else if ($tipo == 'call') {
                $pagar = $sala['bet_atual'] - $eu['bet_round'];
                if ($eu['chips'] < $pagar) $pagar = $eu['chips']; // All-in
                
                $pote_add = $pagar;
                $novo_bet_round += $pagar;
                
                $pdo->prepare("UPDATE poker_jogadores SET chips = chips - :val, bet_round = :br WHERE id = :id")
                    ->execute([':val' => $pagar, ':br' => $novo_bet_round, ':id' => $eu['id']]);
            }
            else if ($tipo == 'raise') {
                $total_pagar = ($sala['bet_atual'] - $eu['bet_round']) + $valor_raise;
                if ($eu['chips'] < $total_pagar) throw new Exception("Fichas insuficientes.");
                
                $pote_add = $total_pagar;
                $novo_bet_round += $total_pagar;
                $nova_bet_atual = $novo_bet_round;
                
                $pdo->prepare("UPDATE poker_jogadores SET chips = chips - :val, bet_round = :br WHERE id = :id")
                    ->execute([':val' => $total_pagar, ':br' => $novo_bet_round, ':id' => $eu['id']]);
            }

            // Atualiza Sala com o pote
            $novo_pote = $sala['pote'] + $pote_add;
            $pdo->prepare("UPDATE poker_salas SET pote = :p, bet_atual = :b WHERE id = :id")->execute([':p' => $novo_pote, ':b' => $nova_bet_atual, ':id' => $sala_id]);

            // Lógica de avanço de jogo
            $prox_turno = proximo_turno($pdo, $sala_id, $eu['posicao']);
            
            if ($prox_turno == null) {
                // Todos deram fold, menos 1 (Venceu a mão)
                $stmtWin = $pdo->prepare("SELECT id, nome, chips FROM poker_jogadores WHERE id_sala = :s AND status != 'fold'");
                $stmtWin->execute([':s' => $sala_id]);
                $vencedor = $stmtWin->fetch(PDO::FETCH_ASSOC);
                
                $pdo->prepare("UPDATE poker_jogadores SET chips = chips + :pote WHERE id = :id")->execute([':pote' => $novo_pote, ':id' => $vencedor['id']]);
                $pdo->prepare("UPDATE poker_salas SET status = 'esperando', stage = 'showdown', vencedor_info = :info WHERE id = :id")->execute([':info' => "{$vencedor['nome']} venceu (Desistência).", ':id' => $sala_id]);
            } 
            else {
                // Checa se a rodada de apostas acabou
                if (checar_fim_de_rodada($pdo, $sala_id, $nova_bet_atual)) {
                    $deck = explode(',', $sala['deck']);
                    $board = $sala['community_cards'] ? explode(',', $sala['community_cards']) : [];
                    $novo_stage = $sala['stage'];

                    if ($sala['stage'] == 'pre-flop') {
                        $novo_stage = 'flop';
                        $board = [array_pop($deck), array_pop($deck), array_pop($deck)];
                    } else if ($sala['stage'] == 'flop') {
                        $novo_stage = 'turn';
                        $board[] = array_pop($deck);
                    } else if ($sala['stage'] == 'turn') {
                        $novo_stage = 'river';
                        $board[] = array_pop($deck);
                    } else if ($sala['stage'] == 'river') {
                        $novo_stage = 'showdown';
                    }

                    // Reset de apostas da rodada
                    $pdo->prepare("UPDATE poker_jogadores SET bet_round = 0 WHERE id_sala = :id")->execute([':id' => $sala_id]);
                    
                    if ($novo_stage == 'showdown') {
                        // SHOWDOWN (Lógica Simplificada)
                        // ATENÇÃO: Aqui você precisará de uma biblioteca PHP para avaliar mãos reais.
                        // Esta simulação entrega o pote para o primeiro jogador ativo encontrado para concluir o loop.
                        $stmtAct = $pdo->prepare("SELECT id, nome FROM poker_jogadores WHERE id_sala = :s AND status = 'ativo' LIMIT 1");
                        $stmtAct->execute([':s' => $sala_id]);
                        $vencedor = $stmtAct->fetch(PDO::FETCH_ASSOC);

                        $pdo->prepare("UPDATE poker_jogadores SET chips = chips + :pote WHERE id = :id")->execute([':pote' => $novo_pote, ':id' => $vencedor['id']]);
                        $pdo->prepare("UPDATE poker_salas SET status = 'esperando', stage = 'showdown', vencedor_info = :info WHERE id = :id")->execute([':info' => "{$vencedor['nome']} venceu no Showdown!", ':id' => $sala_id]);
                    } else {
                        // Próxima fase
                        $pdo->prepare("UPDATE poker_salas SET stage = :st, community_cards = :cc, deck = :dk, bet_atual = 0, turno_posicao = :tp WHERE id = :id")
                            ->execute([':st' => $novo_stage, ':cc' => implode(',', $board), ':dk' => implode(',', $deck), ':tp' => $prox_turno, ':id' => $sala_id]);
                    }
                } else {
                    // Continua a rodada
                    $pdo->prepare("UPDATE poker_salas SET turno_posicao = :tp WHERE id = :id")->execute([':tp' => $prox_turno, ':id' => $sala_id]);
                }
            }

            $pdo->commit();
            echo json_encode(['sucesso' => true]); exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['erro' => $e->getMessage()]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Texas Hold'em - FBA games</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; overflow-x: hidden; }
        .navbar-custom { background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%); border-bottom: 1px solid #333; padding: 15px; }
        .saldo-badge { background-color: #FC082B; color: #000; padding: 5px 15px; border-radius: 20px; font-weight: 800; }

        .poker-room { display: flex; justify-content: center; align-items: center; min-height: 65vh; padding: 20px; position: relative; margin-top: 50px;}
        
        .poker-table {
            background: radial-gradient(circle, #00695c 0%, #00362c 100%);
            border: 15px solid #3e2723;
            border-radius: 200px; width: 900px; height: 450px; position: relative;
            box-shadow: inset 0 0 60px rgba(0,0,0,0.9), 0 20px 40px rgba(0,0,0,0.6);
        }

        .table-logo {
            position: absolute; top: 30%; left: 50%; transform: translate(-50%, -50%);
            font-family: 'Courier New', monospace; font-weight: bold; color: rgba(255,255,255,0.08);
            font-size: 3rem; pointer-events: none; text-align: center;
        }

        .table-center { position: absolute; top: 55%; left: 50%; transform: translate(-50%, -50%); text-align: center; width: 100%; }
        
        .pot-display {
            display: inline-block; background: rgba(0,0,0,0.8); padding: 5px 20px; border-radius: 20px;
            font-weight: bold; color: #ffd700; font-size: 1.2rem; margin-bottom: 15px;
            border: 1px solid #555; box-shadow: 0 4px 10px rgba(0,0,0,0.5);
        }

        .community-cards { display: flex; gap: 8px; justify-content: center; height: 80px; }
        .card-poker { 
            width: 55px; height: 80px; background: #fff; border-radius: 5px; color: #000; 
            font-weight: bold; display: flex; flex-direction: column; justify-content: space-between; padding: 2px 4px;
            box-shadow: 2px 2px 8px rgba(0,0,0,0.5); font-size: 1.2rem; transition: 0.3s;
        }
        .card-poker.red { color: #d32f2f; }
        .card-empty { background: rgba(255,255,255,0.1); border: 1px dashed rgba(255,255,255,0.3); box-shadow: none; }
        .card-back { background: repeating-linear-gradient(45deg, #b71c1c, #b71c1c 5px, #c62828 5px, #c62828 10px); border: 2px solid #fff; }

        .seat {
            position: absolute; width: 110px; height: 110px; background: rgba(0,0,0,0.8); border: 3px solid #444; border-radius: 50%;
            display: flex; flex-direction: column; justify-content: center; align-items: center; z-index: 10;
        }
        .seat.active { border-color: #00e676; box-shadow: 0 0 20px rgba(0, 230, 118, 0.4); }
        .seat.me { border-color: #FC082B; background: rgba(252, 8, 43, 0.1); }
        .seat.empty { border: 2px dashed #777; background: rgba(0,0,0,0.3); cursor: pointer; }
        .seat.fold { opacity: 0.5; filter: grayscale(1); }

        .player-name { font-size: 0.85rem; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 90%; text-align: center; }
        .player-chips { font-size: 0.95rem; color: #ffd700; font-weight: bold; }
        
        .hole-cards { display: flex; gap: 2px; position: absolute; bottom: -20px; }
        .hole-cards .card-poker { width: 35px; height: 50px; font-size: 0.8rem; }

        .pos-1 { bottom: -55px; left: 15%; }
        .pos-2 { top: 50%; left: -55px; transform: translateY(-50%); }
        .pos-3 { top: -55px; left: 15%; }
        .pos-4 { top: -55px; right: 15%; }
        .pos-5 { top: 50%; right: -55px; transform: translateY(-50%); }
        .pos-6 { bottom: -55px; right: 15%; }

        .bet-bubble {
            position: absolute; background: rgba(255,255,255,0.9); color: #000; font-size: 0.85rem;
            padding: 3px 10px; border-radius: 15px; font-weight: bold; border: 2px solid #333; display: none;
        }
        .pos-1 .bet-bubble { top: -35px; } .pos-2 .bet-bubble { right: -60px; } .pos-3 .bet-bubble { bottom: -35px; }
        .pos-4 .bet-bubble { bottom: -35px; } .pos-5 .bet-bubble { left: -60px; } .pos-6 .bet-bubble { top: -35px; }

        .controls-area { 
            background: #1e1e1e; border-top: 1px solid #333; padding: 20px; position: fixed; bottom: 0; width: 100%; text-align: center;
            z-index: 100; display: flex; justify-content: center; gap: 15px;
        }
        
        #vencedorAviso { position: absolute; top: 10%; left: 50%; transform: translateX(-50%); background: #ffd700; color: #000; padding: 10px 30px; border-radius: 10px; font-weight: bold; font-size: 1.2rem; display: none; z-index: 50; }
    </style>
</head>
<body>

<div class="navbar-custom d-flex justify-content-between align-items-center shadow-lg">
    <div class="d-flex align-items-center gap-3">
        <span class="fs-5">Olá, <strong><?= htmlspecialchars($meu_perfil['nome']) ?></strong></span>
    </div>
    <div class="d-flex align-items-center gap-3">
        <button onclick="iniciarJogo()" class="btn btn-outline-success btn-sm border-0 d-none" id="btnIniciar"><i class="bi bi-play-fill"></i> Dar Cartas</button>
        <button onclick="levantar()" class="btn btn-outline-danger btn-sm border-0 d-none" id="btnSairMesa"><i class="bi bi-box-arrow-right"></i> Sair da Mesa</button>
        <a href="../index.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar</a>
        <span class="saldo-badge" id="saldoGeral"><i class="bi bi-coin me-1"></i><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?> moedas</span>
        <span class="saldo-badge"><i class="bi bi-gem me-1"></i><?= number_format($meu_perfil['fba_points'] ?? 0, 0, ',', '.') ?> FBA POINTS</span>
    </div>
</div>

<div class="poker-room">
    <div id="vencedorAviso"></div>
    <div class="poker-table">
        <div class="table-logo">TEXAS HOLD'EM</div>
        
        <div class="table-center">
            <div class="pot-display" id="mesaPot">POT: 0 moedas</div>
            <div class="community-cards" id="mesaCartas"></div>
        </div>

        <div id="assentosContainer"></div>
    </div>
</div>

<div class="controls-area d-none" id="controlesJogo">
    <button class="btn btn-danger btn-lg fw-bold px-5 rounded-pill shadow" onclick="acaoPoker('fold')">FOLD</button>
    <button class="btn btn-warning btn-lg fw-bold px-5 rounded-pill shadow" id="btnCall" onclick="acaoPoker('call')">CALL</button>
    <button class="btn btn-success btn-lg fw-bold px-5 rounded-pill shadow" onclick="abrirRaise()">RAISE</button>
</div>

<script>
    const MEU_ID = <?= $user_id ?>;
    let valorParaPagar = 0;
    
    function criarCarta(str) {
        if (!str || str === '??') return '<div class="card-poker card-back"></div>';
        const valor = str.slice(0, -1);
        const naipe = str.slice(-1);
        let simbolo = ''; let cor = '';
        if(naipe === 'h') { simbolo = '♥'; cor = 'red'; }
        if(naipe === 'd') { simbolo = '♦'; cor = 'red'; }
        if(naipe === 'c') { simbolo = '♣'; cor = ''; }
        if(naipe === 's') { simbolo = '♠'; cor = ''; }
        return `<div class="card-poker ${cor}"><div style="line-height:1;">${valor}</div><div style="text-align:center; font-size:1.5rem; line-height:1;">${simbolo}</div></div>`;
    }

    function atualizarMesa() {
        $.post('index.php?game=poker', { acao: 'buscar_mesa' }, function(res) {
            if(!res.sucesso) return;
            const data = res.dados;
            
            $('#mesaPot').text('POT: ' + data.sala.pote + ' moedas');

            // Winner Display
            if (data.sala.stage === 'showdown' && data.sala.vencedor_info) {
                $('#vencedorAviso').text(data.sala.vencedor_info).fadeIn();
            } else {
                $('#vencedorAviso').fadeOut();
            }

            // Cartas Mesa
            let htmlCartas = '';
            let cartasBoard = data.sala.community_cards ? data.sala.community_cards.split(',') : [];
            for(let i=0; i<5; i++) {
                if(cartasBoard[i]) htmlCartas += criarCarta(cartasBoard[i]);
                else htmlCartas += '<div class="card-poker card-empty"></div>';
            }
            $('#mesaCartas').html(htmlCartas);

            // Gerencia UI de controles
            if(data.meu_lugar !== null) {
                $('#btnSairMesa').removeClass('d-none');
                
                if (data.sala.status === 'esperando' && data.ativos >= 2) {
                    $('#btnIniciar').removeClass('d-none');
                } else {
                    $('#btnIniciar').addClass('d-none');
                }

                if(data.sala.turno_posicao == data.meu_lugar && data.sala.status === 'jogando') {
                    $('#controlesJogo').removeClass('d-none');
                    let eu = data.jogadores[data.meu_lugar];
                    valorParaPagar = data.sala.bet_atual - eu.bet_round;
                    $('#btnCall').text(valorParaPagar > 0 ? 'CALL (' + valorParaPagar + ')' : 'CHECK');
                } else {
                    $('#controlesJogo').addClass('d-none');
                }
            }

            // Renderiza Assentos
            let htmlAssentos = '';
            for(let i=1; i<=6; i++) {
                const jogador = data.jogadores[i];
                if(jogador) {
                    let isMe = jogador.id_usuario == MEU_ID ? 'me' : '';
                    let isActive = (data.sala.turno_posicao == i && data.sala.status === 'jogando') ? 'active' : '';
                    let isFold = jogador.status === 'fold' ? 'fold' : '';
                    let betDisplay = jogador.bet_round > 0 ? `style="display:block;"` : '';
                    
                    let holeCardsHtml = '';
                    if(jogador.cards) {
                        let c = jogador.cards.split(',');
                        holeCardsHtml = `<div class="hole-cards">${criarCarta(c[0])}${criarCarta(c[1])}</div>`;
                    }

                    htmlAssentos += `
                        <div class="seat pos-${i} ${isMe} ${isActive} ${isFold}">
                            <div class="bet-bubble" ${betDisplay}>${jogador.bet_round}</div>
                            <span class="player-name">${jogador.nome}</span>
                            <span class="player-chips">${jogador.chips}</span>
                            ${holeCardsHtml}
                        </div>
                    `;
                } else {
                    let onclick = data.meu_lugar === null ? `onclick="sentar(${i})"` : '';
                    htmlAssentos += `<div class="seat empty pos-${i}" ${onclick}><span class="player-name text-muted"><i class="bi bi-plus-lg"></i> Sentar</span></div>`;
                }
            }
            $('#assentosContainer').html(htmlAssentos);
        }, 'json');
    }

    function sentar(pos) {
        if(confirm('Entrar na mesa com 500 moedas?')) {
            $.post('index.php?game=poker', { acao: 'sentar', posicao: pos }, function(res) {
                if(res.erro) alert(res.erro); atualizarMesa();
            }, 'json');
        }
    }

    function levantar() {
        if(confirm('Deseja sair da mesa e recolher suas fichas?')) {
            $.post('index.php?game=poker', { acao: 'levantar' }, function(res) {
                if(res.erro) alert(res.erro); atualizarMesa();
            }, 'json');
        }
    }

    function iniciarJogo() {
        $.post('index.php?game=poker', { acao: 'iniciar' }, function(res) {
            if(res.erro) alert(res.erro); atualizarMesa();
        }, 'json');
    }

    function acaoPoker(tipo, valor = 0) {
        $.post('index.php?game=poker', { acao: 'acao_poker', tipo: tipo, valor: valor }, function(res) {
            if(res.erro) alert(res.erro); atualizarMesa();
        }, 'json');
    }

    function abrirRaise() {
        let amt = prompt("Quanto a MAIS você quer apostar?", "50");
        if (amt && !isNaN(amt)) acaoPoker('raise', amt);
    }

    atualizarMesa();
    setInterval(atualizarMesa, 2000);
</script>
</body>
</html>