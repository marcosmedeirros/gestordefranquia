<?php
// xadrez.php - XADREZ COM LOBBY AUTOMÁTICO (REALTIME ⚡) E RANKING
ini_set('display_errors', 1);
error_reporting(E_ALL);
// session_start já foi chamado em games/index.php
require '../core/conexao.php';

// 1. Segurança e Dados do Usuário
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];

try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro perfil: " . $e->getMessage());
}

// --- 1. LÃ“GICA DE API (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json'); // Padrão JSON, exceto para HTML parcial
    $acao = $_POST['acao'];
    $agora = time(); 

    try {
        // G. ATUALIZAR LOBBY (NOVO! ⚡)
        // Retorna o HTML da tabela atualizado para não precisar dar F5
        if ($acao == 'atualizar_lobby') {
            header('Content-Type: text/html; charset=utf-8'); // Muda header para HTML
            
            $stmtGames = $pdo->prepare("
                SELECT p.*, u1.nome as desafiante_nome, u2.nome as desafiado_nome 
                FROM xadrez_partidas p
                JOIN usuarios u1 ON p.id_desafiante = u1.id
                JOIN usuarios u2 ON p.id_desafiado = u2.id
                WHERE (p.id_desafiante = :id OR p.id_desafiado = :id)
                AND p.status IN ('pendente', 'andamento')
                ORDER BY p.id DESC
            ");
            $stmtGames->execute([':id' => $user_id]);
            $partidas = $stmtGames->fetchAll(PDO::FETCH_ASSOC);

            if (empty($partidas)) {
                echo '<tr><td colspan="4" class="text-center py-4 text-muted"><i class="bi bi-inbox fs-4 d-block mb-2"></i>Nenhuma partida encontrada.</td></tr>';
            } else {
                foreach ($partidas as $p) {
                    $contra = ($p['id_desafiante'] == $user_id) ? $p['desafiado_nome'] : $p['desafiante_nome'];
                    $valor = number_format($p['valor_aposta'], 0, ',', '.');
                    $status_class = ($p['status'] == 'andamento') ? 'bg-andamento' : 'bg-pendente';
                    $status_text = ucfirst($p['status']);
                    
                    // Lógica do Botão
                    $botao = '';
                    if ($p['status'] == 'pendente' && $p['id_desafiado'] == $user_id) {
                        $botao = '<button onclick="aceitarDesafio('.$p['id'].')" class="btn btn-sm btn-success fw-bold shadow-sm"><i class="bi bi-check-lg me-1"></i>Aceitar</button>';
                    } elseif ($p['status'] == 'andamento') {
                        $botao = '<a href="?id='.$p['id'].'" class="btn btn-sm btn-primary fw-bold shadow-sm"><i class="bi bi-play-fill me-1"></i>Jogar</a>';
                    } else {
                        $botao = '<small class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Aguardando...</small>';
                    }

                    echo "
                    <tr>
                        <td class='ps-3 fw-bold'><i class='bi bi-person me-1 text-secondary'></i> $contra</td>
                        <td class='fw-bold text-success'>$valor</td>
                        <td><span class='status-badge $status_class shadow-sm'>$status_text</span></td>
                        <td class='text-end pe-3'>$botao</td>
                    </tr>";
                }
            }
            exit; // Encerra aqui para não retornar JSON
        }

        // A. CRIAR DESAFIO
        if ($acao == 'desafiar') {
            $oponente_id = $_POST['oponente'];
            $valor = (int)$_POST['valor'];

            if ($valor <= 0) die(json_encode(['erro' => 'Valor inválido.']));
            if ($oponente_id == $user_id) die(json_encode(['erro' => 'Não pode jogar contra si mesmo.']));

            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $user_id]);
            if ($stmt->fetchColumn() < $valor) throw new Exception("Saldo insuficiente.");

            $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :id")->execute([':val' => $valor, ':id' => $user_id]);

            $stmtIns = $pdo->prepare("INSERT INTO xadrez_partidas (id_desafiante, id_desafiado, valor_aposta, vez_de, status, tempo_brancas, tempo_pretas) VALUES (:id1, :id2, :val, :id1, 'pendente', 600, 600)");
            $stmtIns->execute([':id1' => $user_id, ':id2' => $oponente_id, ':val' => $valor]);

            $pdo->commit();
            echo json_encode(['sucesso' => true]);
        }

        // B. ACEITAR DESAFIO
        elseif ($acao == 'aceitar') {
            $partida_id = $_POST['id_partida'];
            
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM xadrez_partidas WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $partida_id]);
            $partida = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$partida || $partida['id_desafiado'] != $user_id || $partida['status'] != 'pendente') {
                throw new Exception("Partida inválida.");
            }

            $stmtUser = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id");
            $stmtUser->execute([':id' => $user_id]);
            if ($stmtUser->fetchColumn() < $partida['valor_aposta']) throw new Exception("Pontos insuficientes.");

            $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :id")->execute([':val' => $partida['valor_aposta'], ':id' => $user_id]);

            $pdo->prepare("UPDATE xadrez_partidas SET status = 'andamento', ultimo_movimento = :t WHERE id = :id")
                ->execute([':t' => $agora, ':id' => $partida_id]);

            $pdo->commit();
            echo json_encode(['sucesso' => true]);
        }

        // C. REALIZAR MOVIMENTO
        elseif ($acao == 'mover') {
            $partida_id = $_POST['id_partida'];
            $nova_fen = $_POST['fen'];
            $novo_pgn = $_POST['pgn']; 
            $game_over = $_POST['game_over'] === 'true';
            $draw = $_POST['draw'] === 'true';
            
            $stmt = $pdo->prepare("SELECT * FROM xadrez_partidas WHERE id = :id");
            $stmt->execute([':id' => $partida_id]);
            $partida = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($partida['vez_de'] != $user_id) die(json_encode(['erro' => 'Não é sua vez!']));
            if ($partida['status'] != 'andamento') die(json_encode(['erro' => 'Jogo finalizado.']));

            // Cálculo do tempo
            $tempo_gasto = $agora - $partida['ultimo_movimento'];
            $tempo_gasto = max(0, $tempo_gasto);

            $update_tempo = "";
            $tempo_restante_atual = 0;

            if ($partida['id_desafiante'] == $user_id) { // Brancas
                $tempo_restante_atual = $partida['tempo_brancas'] - $tempo_gasto;
                $update_tempo = "tempo_brancas = :tr";
            } else { // Pretas
                $tempo_restante_atual = $partida['tempo_pretas'] - $tempo_gasto;
                $update_tempo = "tempo_pretas = :tr";
            }

            if ($tempo_restante_atual <= 0) {
                $game_over = true;
                $draw = false;
                $vencedor = ($partida['id_desafiante'] == $user_id) ? $partida['id_desafiado'] : $partida['id_desafiante'];
            } else {
                $vencedor = null;
            }

            $proximo_jogador = ($partida['id_desafiante'] == $user_id) ? $partida['id_desafiado'] : $partida['id_desafiante'];
            $novo_status = 'andamento';

            $pdo->beginTransaction();

            if ($game_over) {
                if ($draw) {
                    $novo_status = 'empate';
                    $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id IN (:p1, :p2)")
                        ->execute([':val' => $partida['valor_aposta'], ':p1' => $partida['id_desafiante'], ':p2' => $partida['id_desafiado']]);
                } else {
                    $novo_status = 'finalizada';
                    if (!$vencedor) $vencedor = $user_id; 
                    
                    $premio = $partida['valor_aposta'] * 2;
                    $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")
                        ->execute([':val' => $premio, ':id' => $vencedor]);
                }
            }

            $sql = "UPDATE xadrez_partidas SET fen = :fen, pgn = :pgn, vez_de = :vez, status = :st, vencedor = :vinc, ultimo_movimento = :agora, $update_tempo WHERE id = :id";
            $stmtUpd = $pdo->prepare($sql);
            $stmtUpd->execute([
                ':fen' => $nova_fen, 
                ':pgn' => $novo_pgn,
                ':vez' => $proximo_jogador, 
                ':st' => $novo_status,
                ':vinc' => $vencedor,
                ':agora' => $agora,
                ':tr' => max(0, $tempo_restante_atual),
                ':id' => $partida_id
            ]);
            
            $pdo->commit();
            echo json_encode(['sucesso' => true]);
        }

        // D. REIVINDICAR TEMPO
        elseif ($acao == 'reivindicar_tempo') {
            $partida_id = $_POST['id_partida'];
            
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM xadrez_partidas WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $partida_id]);
            $partida = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($partida['status'] != 'andamento') die(json_encode(['erro' => 'Jogo já acabou.']));

            $tempo_passado = $agora - $partida['ultimo_movimento'];
            $perdeu = false;
            
            if ($partida['vez_de'] == $partida['id_desafiante']) { 
                if (($partida['tempo_brancas'] - $tempo_passado) <= 0) $perdeu = true;
            } else { 
                if (($partida['tempo_pretas'] - $tempo_passado) <= 0) $perdeu = true;
            }

            if ($perdeu) {
                $vencedor = ($partida['vez_de'] == $partida['id_desafiante']) ? $partida['id_desafiado'] : $partida['id_desafiante'];
                $premio = $partida['valor_aposta'] * 2;
                
                $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")->execute([':val' => $premio, ':id' => $vencedor]);
                $pdo->prepare("UPDATE xadrez_partidas SET status = 'finalizada', vencedor = :v WHERE id = :id")->execute([':v' => $vencedor, ':id' => $partida_id]);
                
                $pdo->commit();
                echo json_encode(['sucesso' => true, 'msg' => 'Tempo esgotado! Vitória decretada.']);
            } else {
                $pdo->rollBack();
                echo json_encode(['erro' => 'O tempo ainda não acabou no servidor.']);
            }
        }

        // E. DESISTIR
        elseif ($acao == 'desistir') {
            $partida_id = $_POST['id_partida'];
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM xadrez_partidas WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $partida_id]);
            $partida = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($partida['status'] != 'andamento') die(json_encode(['erro' => 'Jogo não está em andamento.']));
            if ($user_id != $partida['id_desafiante'] && $user_id != $partida['id_desafiado']) die(json_encode(['erro' => 'Você não está neste jogo.']));

            $vencedor = ($user_id == $partida['id_desafiante']) ? $partida['id_desafiado'] : $partida['id_desafiante'];
            $premio = $partida['valor_aposta'] * 2;

            $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")->execute([':val' => $premio, ':id' => $vencedor]);
            $pdo->prepare("UPDATE xadrez_partidas SET status = 'finalizada', vencedor = :v WHERE id = :id")->execute([':v' => $vencedor, ':id' => $partida_id]);

            $pdo->commit();
            echo json_encode(['sucesso' => true]);
        }

        // F. POLLING JOGO
        elseif ($acao == 'buscar_estado') {
            $partida_id = $_POST['id_partida'];
            $stmt = $pdo->prepare("SELECT fen, pgn, vez_de, status, vencedor, tempo_brancas, tempo_pretas, ultimo_movimento, id_desafiante FROM xadrez_partidas WHERE id = :id");
            $stmt->execute([':id' => $partida_id]);
            $dados = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $tempo_corrido = $agora - $dados['ultimo_movimento'];
            if ($dados['status'] == 'andamento') {
                if ($dados['vez_de'] == $dados['id_desafiante']) {
                    $dados['tempo_brancas'] = max(0, $dados['tempo_brancas'] - $tempo_corrido);
                } else {
                    $dados['tempo_pretas'] = max(0, $dados['tempo_pretas'] - $tempo_corrido);
                }
            }
            echo json_encode($dados);
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['erro' => $e->getMessage()]);
    }
    exit;
}

// --- 2. DADOS DA PÃGINA (INICIAL) ---
$stmtUsers = $pdo->prepare("SELECT id, nome FROM usuarios WHERE id != :id");
$stmtUsers->execute([':id' => $user_id]);
$usuarios = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// Busca Minhas Partidas
$stmtGames = $pdo->prepare("
    SELECT p.*, u1.nome as desafiante_nome, u2.nome as desafiado_nome 
    FROM xadrez_partidas p
    JOIN usuarios u1 ON p.id_desafiante = u1.id
    JOIN usuarios u2 ON p.id_desafiado = u2.id
    WHERE (p.id_desafiante = :id OR p.id_desafiado = :id)
    AND p.status IN ('pendente', 'andamento')
    ORDER BY p.id DESC
");
$stmtGames->execute([':id' => $user_id]);
$minhas_partidas = $stmtGames->fetchAll(PDO::FETCH_ASSOC);

// 3. BUSCA RANKING DE VITÓRIAS NO XADREZ (NOVO 🏆)
try {
    $stmtRankChess = $pdo->query("
        SELECT u.nome, COUNT(p.id) as vitorias 
        FROM xadrez_partidas p 
        JOIN usuarios u ON p.vencedor = u.id 
        WHERE p.status = 'finalizada' 
        GROUP BY p.vencedor 
        ORDER BY vitorias DESC 
        LIMIT 5
    ");
    $ranking_xadrez = $stmtRankChess->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $ranking_xadrez = []; // Evita erro se tabela estiver vazia
}

$jogo_ativo = null;
if (isset($_GET['id'])) {
    $stmtAtivo = $pdo->prepare("SELECT p.*, u1.nome as desafiante_nome, u2.nome as desafiado_nome 
                                FROM xadrez_partidas p
                                JOIN usuarios u1 ON p.id_desafiante = u1.id
                                JOIN usuarios u2 ON p.id_desafiado = u2.id 
                                WHERE p.id = :id");
    $stmtAtivo->execute([':id' => $_GET['id']]);
    $jogo_ativo = $stmtAtivo->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Xadrez - FBA games</title>
    
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>♟️</text></svg>">

    <link rel="stylesheet" href="https://unpkg.com/@chrisoakman/chessboardjs@1.0.0/dist/chessboard-1.0.0.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        /* PADRÃƒO DARK MODE */
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; }
        
        /* Navbar Padronizada */
        .navbar-custom { 
            background: linear-gradient(180deg, #1e1e1e 0%, #121212 100%);
            border-bottom: 1px solid #333;
            padding: 15px; 
        }
        .saldo-badge { 
            background-color: #FC082B; color: #000; padding: 8px 15px; 
            border-radius: 20px; font-weight: 800; font-size: 1.1em;
            box-shadow: 0 0 10px rgba(252, 8, 43, 0.3);
        }
        .admin-btn { 
            background-color: #ff6d00; color: white; padding: 5px 15px; 
            border-radius: 20px; text-decoration: none; font-weight: bold; font-size: 0.9em; transition: 0.3s; 
        }
        .admin-btn:hover { background-color: #e65100; color: white; box-shadow: 0 0 8px #ff6d00; }

        /* ESTILOS DO XADREZ */
        .board-container { width: 100%; max-width: 500px; margin: 0 auto; border: 5px solid #333; border-radius: 5px; }
        .status-badge { font-size: 0.9em; padding: 5px 10px; border-radius: 10px; }
        .bg-pendente { background-color: #f39c12; color: white; }
        .bg-andamento { background-color: #27ae60; color: white; }
        
        .timer-box { 
            font-family: 'Courier New', monospace; font-size: 1.8rem; font-weight: bold; 
            background: #1e1e1e; padding: 5px 15px; border-radius: 8px; border: 2px solid #444; color: #ccc;
        }
        .timer-box.active { border-color: #e74c3c; color: #f1c40f; box-shadow: 0 0 10px #e74c3c; }
        
        .captured-pieces { 
            height: 35px; background: rgba(255,255,255,0.05); border-radius: 6px; 
            padding: 2px 8px; display: flex; align-items: center; overflow: hidden; border: 1px solid #333;
        }
        .captured-img { width: 25px; margin-right: -8px; }

        /* Cards Dark Mode */
        .card-dark { background-color: #1e1e1e; border: 1px solid #333; color: #e0e0e0; }
        .card-header-dark { background-color: #252525; border-bottom: 1px solid #333; color: #fff; }
        .form-control-dark, .form-select-dark {
            background-color: #121212; border-color: #444; color: #fff;
        }
        .form-control-dark:focus, .form-select-dark:focus {
            background-color: #121212; border-color: #FC082B; color: #fff; box-shadow: 0 0 0 0.25rem rgba(252, 8, 43, 0.25);
        }
        
        /* Tabela Dark */
        .table-dark-custom { --bs-table-bg: #1e1e1e; --bs-table-color: #e0e0e0; --bs-table-border-color: #333; }
        .table-dark-custom th { background-color: #252525; color: #fff; }
    </style>
</head>
<body>

<!-- Header Padronizado -->
<div class="navbar-custom d-flex justify-content-between align-items-center shadow-lg sticky-top mb-4">
    <div class="d-flex align-items-center gap-3">
        <span class="fs-5">Olá, <strong><?= htmlspecialchars($meu_perfil['nome']) ?></strong></span>
        <?php if (!empty($meu_perfil['is_admin']) && $meu_perfil['is_admin'] == 1): ?>
            <a href="../admin/dashboard.php" class="admin-btn"><i class="bi bi-gear-fill me-1"></i> Admin</a>
        <?php endif; ?>
    </div>
    
    <div class="d-flex align-items-center gap-3">
        <a href="../index.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar ao Painel</a>
        <span class="saldo-badge me-2"><?= number_format($meu_perfil['pontos'], 0, ',', '.') ?> pts</span>
    </div>
</div>

<div class="container py-4">

    <?php if ($jogo_ativo): ?>
        <!-- ... (LÃ“GICA DO JOGO MANTIDA, SEM ALTERAÃ‡Ã•ES) ... -->
        <?php 
            $sou_brancas = ($jogo_ativo['id_desafiante'] == $user_id);
            $orientacao = $sou_brancas ? 'white' : 'black';
            $oponente = $sou_brancas ? $jogo_ativo['desafiado_nome'] : $jogo_ativo['desafiante_nome'];
            
            $tempo_gasto_turno = ($jogo_ativo['status'] == 'andamento') ? (time() - $jogo_ativo['ultimo_movimento']) : 0;
            $t_brancas = $jogo_ativo['tempo_brancas'];
            $t_pretas = $jogo_ativo['tempo_pretas'];
            
            if ($jogo_ativo['status'] == 'andamento') {
                if ($jogo_ativo['vez_de'] == $jogo_ativo['id_desafiante']) $t_brancas = max(0, $t_brancas - $tempo_gasto_turno);
                else $t_pretas = max(0, $t_pretas - $tempo_gasto_turno);
            }
        ?>
        <div class="row justify-content-center">
            <div class="col-md-8 text-center">
                <div class="d-flex justify-content-between align-items-end mb-2 px-2">
                    <div class="text-start">
                        <h5 class="m-0"><i class="bi bi-person-fill"></i> <?= htmlspecialchars($oponente) ?></h5>
                        <div id="capturedTop" class="captured-pieces mt-1"></div>
                    </div>
                    <div id="timerTop" class="timer-box"><?= gmdate("i:s", $sou_brancas ? $t_pretas : $t_brancas) ?></div>
                </div>
                <div class="board-container shadow-lg">
                    <div id="myBoard" style="width: 100%"></div>
                </div>
                <div class="d-flex justify-content-between align-items-start mt-2 px-2">
                    <div class="text-start">
                        <h5 class="m-0 text-success">Você</h5>
                        <div id="capturedBottom" class="captured-pieces mt-1"></div>
                    </div>
                    <div id="timerBottom" class="timer-box"><?= gmdate("i:s", $sou_brancas ? $t_brancas : $t_pretas) ?></div>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-3 gap-2">
                    <div id="statusGame" class="alert alert-dark border-secondary py-2 fw-bold m-0 flex-grow-1 shadow-sm">Carregando...</div>
                    <?php if($jogo_ativo['status'] == 'andamento'): ?>
                        <button onclick="desistir()" class="btn btn-outline-danger fw-bold shadow-sm">🏳️ Desistir</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-dark h-100 mt-3 mt-md-0 shadow-lg">
                    <div class="card-header card-header-dark fw-bold"><i class="bi bi-clock-history me-2"></i>HistÃ³rico da Partida</div>
                    <div class="card-body p-2 bg-dark bg-opacity-50" style="height: 400px; overflow-y: auto; font-family: monospace; font-size: 0.9em;" id="pgnDisplay"></div>
                    <div class="card-footer card-header-dark text-muted small text-center">
                        <i class="bi bi-coin me-1 text-warning"></i>Valendo: <span class="text-success fw-bold"><?= number_format($jogo_ativo['valor_aposta'], 0, ',', '.') ?> pts</span>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- --- LOBBY (Dark Mode) --- -->
        <h3 class="mb-4 text-white fw-bold"><i class="bi bi-joystick me-2"></i>Lobby de Xadrez</h3>

        <div class="row g-4">
            <!-- Coluna Esquerda: Novo Desafio + Ranking -->
            <div class="col-md-4">
                <!-- Card Novo Desafio -->
                <div class="card card-dark shadow-lg mb-4">
                    <div class="card-header card-header-dark fw-bold text-warning"><i class="bi bi-plus-circle-fill me-2"></i>Novo Desafio (10 min)</div>
                    <div class="card-body">
                        <form id="formDesafio">
                            <input type="hidden" name="acao" value="desafiar">
                            <div class="mb-3">
                                <label class="fw-bold mb-1">Oponente</label>
                                <select name="oponente" class="form-select form-select-dark" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($usuarios as $u): ?>
                                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nome']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="fw-bold mb-1">Aposta (Pontos)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-dark border-secondary text-secondary">$</span>
                                    <input type="number" name="valor" class="form-control form-control-dark" min="10" placeholder="MÃ­nimo 10" required>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-success w-100 fw-bold shadow-sm">
                                <i class="bi bi-lightning-fill me-1"></i>Desafiar
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Card Ranking de Xadrez (NOVO 🏆) -->
                <div class="card card-dark shadow-lg">
                    <div class="card-header card-header-dark fw-bold text-info"><i class="bi bi-trophy-fill me-2"></i>Mestres do Xadrez</div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php if(!empty($ranking_xadrez)): ?>
                                <?php foreach($ranking_xadrez as $i => $r): ?>
                                    <li class="list-group-item bg-transparent text-white d-flex justify-content-between align-items-center border-secondary">
                                        <div class="d-flex align-items-center">
                                            <span class="me-2 fw-bold" style="width: 25px;">
                                                <?php 
                                                    if($i==0) echo '🥇';
                                                    elseif($i==1) echo '🥈';
                                                    elseif($i==2) echo '🥉';
                                                    else echo '<span class="text-secondary small">#'.($i+1).'</span>';
                                                ?>
                                            </span>
                                            <span class="text-truncate" style="max-width: 150px;"><?= htmlspecialchars($r['nome']) ?></span>
                                        </div>
                                        <span class="badge bg-dark border border-secondary text-info rounded-pill"><?= $r['vitorias'] ?> vitÃ³rias</span>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="list-group-item bg-transparent text-muted text-center py-3 border-secondary">
                                    <small>Nenhuma vitÃ³ria registrada ainda.</small>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Card Minhas Partidas -->
            <div class="col-md-8">
                <div class="card card-dark shadow-lg h-100">
                    <div class="card-header card-header-dark fw-bold"><i class="bi bi-list-ul me-2"></i>Minhas Partidas</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark-custom table-hover mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th class="ps-3">Contra</th>
                                        <th>Valor</th>
                                        <th>Status</th>
                                        <th class="text-end pe-3">Ação</th>
                                    </tr>
                                </thead>
                                <tbody id="listaPartidas"> <!-- ID ADICIONADO PARA O AJAX -->
                                    <?php if(empty($minhas_partidas)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted">
                                                <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                                                Nenhuma partida encontrada.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($minhas_partidas as $p): ?>
                                        <tr>
                                            <td class="ps-3 fw-bold">
                                                <i class="bi bi-person me-1 text-secondary"></i>
                                                <?= ($p['id_desafiante'] == $user_id) ? $p['desafiado_nome'] : $p['desafiante_nome'] ?>
                                            </td>
                                            <td class="fw-bold text-success"><?= number_format($p['valor_aposta'], 0, ',', '.') ?></td>
                                            <td><span class="status-badge bg-<?= $p['status'] ?> shadow-sm"><?= ucfirst($p['status']) ?></span></td>
                                            <td class="text-end pe-3">
                                                <?php if ($p['status'] == 'pendente' && $p['id_desafiado'] == $user_id): ?>
                                                    <button onclick="aceitarDesafio(<?= $p['id'] ?>)" class="btn btn-sm btn-success fw-bold shadow-sm">
                                                        <i class="bi bi-check-lg me-1"></i>Aceitar
                                                    </button>
                                                <?php elseif ($p['status'] == 'andamento'): ?>
                                                    <a href="?id=<?= $p['id'] ?>" class="btn btn-sm btn-primary fw-bold shadow-sm">
                                                        <i class="bi bi-play-fill me-1"></i>Jogar
                                                    </a>
                                                <?php else: ?>
                                                    <small class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Aguardando...</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://unpkg.com/@chrisoakman/chessboardjs@1.0.0/dist/chessboard-1.0.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/chess.js/0.10.3/chess.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // --- LÃ“GICA DO LOBBY ---
    $('#formDesafio').submit(function(e) {
        e.preventDefault();
        $.post('index.php?game=xadrez', $(this).serialize(), function(data) {
            if(data.erro) alert(data.erro); else { alert('Desafio enviado!'); location.reload(); }
        }, 'json');
    });

    function aceitarDesafio(id) {
        if(!confirm('Apostar pontos e iniciar partida?')) return;
        $.post('index.php?game=xadrez', {acao: 'aceitar', id_partida: id}, function(data) {
            if(data.erro) alert(data.erro); else location.reload();
        }, 'json');
    }

    <?php if (!$jogo_ativo): ?>
    // --- AUTO UPDATE DO LOBBY (Polling a cada 3s) ---
    setInterval(function() {
        $.post('index.php?game=xadrez', {acao: 'atualizar_lobby'}, function(html) {
            $('#listaPartidas').html(html);
        });
    }, 3000);
    <?php endif; ?>


    // --- LÃ“GICA DO JOGO ---
    <?php if ($jogo_ativo): ?>
    const gameId = <?= $jogo_ativo['id'] ?>;
    const orientation = '<?= $orientacao ?>';
    const game = new Chess();
    
    <?php if(!empty($jogo_ativo['pgn'])): ?>
        game.load_pgn(`<?= $jogo_ativo['pgn'] ?>`);
    <?php else: ?>
        game.load('<?= $jogo_ativo['fen'] ?>');
    <?php endif; ?>

    let board = null;
    let timerInterval = null;
    let timeWhite = <?= $t_brancas ?>;
    let timeBlack = <?= $t_pretas ?>;
    let gameActive = <?= ($jogo_ativo['status'] == 'andamento') ? 'true' : 'false' ?>;

    function onDragStart (source, piece) {
        if (game.game_over() || !gameActive) return false;
        if ((game.turn() === 'w' && piece.search(/^b/) !== -1) || (game.turn() === 'b' && piece.search(/^w/) !== -1)) return false;
        if ((orientation === 'white' && piece.search(/^b/) !== -1) || (orientation === 'black' && piece.search(/^w/) !== -1)) return false;
        if (orientation === 'white' && game.turn() === 'b') return false;
        if (orientation === 'black' && game.turn() === 'w') return false;
    }

    function onDrop (source, target) {
        var move = game.move({ from: source, to: target, promotion: 'q' });
        if (move === null) return 'snapback';

        updateUI();
        
        $.post('index.php?game=xadrez', {
            acao: 'mover', id_partida: gameId, fen: game.fen(), pgn: game.pgn(),
            game_over: game.game_over(), draw: game.in_draw()
        }, function(data) {
            if(data.erro) { alert(data.erro); game.undo(); board.position(game.fen()); }
            else if(game.game_over()) { alert('Xeque-mate! Fim de jogo.'); window.location.href = 'index.php?game=xadrez'; }
        }, 'json');
    }

    function updateUI () {
        var status = '';
        var moveColor = (game.turn() === 'b') ? 'Pretas' : 'Brancas';
        if (game.in_checkmate()) status = 'Fim: ' + moveColor + ' em xeque-mate.';
        else if (game.in_draw()) status = 'Fim: Empate.';
        else { status = 'Vez das ' + moveColor; if (game.in_check()) status += ' (XEQUE!)'; }
        $('#statusGame').text(status);
        
        var pgn = game.pgn();
        var formattedPgn = pgn.replace(/([0-9]+\.)/g, '<br><strong>$1</strong>'); 
        $('#pgnDisplay').html(formattedPgn);
        
        updateMaterial(); // Atualiza peças comidas

        if (game.turn() === 'w') {
            $('#timerBottom').toggleClass('active', orientation === 'white');
            $('#timerTop').toggleClass('active', orientation === 'black');
        } else {
            $('#timerBottom').toggleClass('active', orientation === 'black');
            $('#timerTop').toggleClass('active', orientation === 'white');
        }
    }

    function updateMaterial() {
        const start = { w: {p:8, n:2, b:2, r:2, q:1}, b: {p:8, n:2, b:2, r:2, q:1} };
        const boardState = game.board();
        const current = { w: {p:0, n:0, b:0, r:0, q:0}, b: {p:0, n:0, b:0, r:0, q:0} };

        for (let row of boardState) {
            for (let square of row) {
                if (square && square.type !== 'k') {
                    current[square.color][square.type]++;
                }
            }
        }

        let wCapturedHtml = ''; // Peças brancas capturadas (exibe pro Preto)
        let bCapturedHtml = ''; // Peças pretas capturadas (exibe pro Branco)

        ['q', 'r', 'b', 'n', 'p'].forEach(type => {
            let wCount = start.w[type] - current.w[type];
            if (wCount > 0) {
                for(let i=0; i<wCount; i++) wCapturedHtml += `<img src="https://chessboardjs.com/img/chesspieces/wikipedia/w${type.toUpperCase()}.png" class="captured-img">`;
            }
            let bCount = start.b[type] - current.b[type];
            if (bCount > 0) {
                for(let i=0; i<bCount; i++) bCapturedHtml += `<img src="https://chessboardjs.com/img/chesspieces/wikipedia/b${type.toUpperCase()}.png" class="captured-img">`;
            }
        });

        if (orientation === 'white') {
            $('#capturedBottom').html(bCapturedHtml); // Mostra o que EU comi (Pretas)
            $('#capturedTop').html(wCapturedHtml);    // Mostra o que ELE comeu (Brancas)
        } else {
            $('#capturedBottom').html(wCapturedHtml); // Mostra o que EU comi (Brancas)
            $('#capturedTop').html(bCapturedHtml);    // Mostra o que ELE comeu (Pretas)
        }
    }

    function desistir() {
        if(!confirm('Tem certeza que deseja desistir? Você perderá os pontos apostados.')) return;
        $.post('index.php?game=xadrez', {acao: 'desistir', id_partida: gameId}, function(data) {
            if(data.erro) alert(data.erro);
            else { alert('Você desistiu da partida.'); window.location.href = 'index.php?game=xadrez'; }
        }, 'json');
    }

    var config = {
        draggable: true, position: game.fen(), orientation: orientation,
        onDragStart: onDragStart, onDrop: onDrop, onSnapEnd: () => board.position(game.fen()),
        pieceTheme: 'https://chessboardjs.com/img/chesspieces/wikipedia/{piece}.png'
    }
    board = Chessboard('myBoard', config);
    updateUI();

    function startClock() {
        if(timerInterval) clearInterval(timerInterval);
        timerInterval = setInterval(() => {
            if(!gameActive) return;
            if(game.turn() === 'w') timeWhite--; else timeBlack--;

            let mW = Math.floor(timeWhite / 60).toString().padStart(2, '0');
            let sW = (timeWhite % 60).toString().padStart(2, '0');
            let mB = Math.floor(timeBlack / 60).toString().padStart(2, '0');
            let sB = (timeBlack % 60).toString().padStart(2, '0');

            if(orientation === 'white') {
                $('#timerBottom').text(`${mW}:${sW}`); $('#timerTop').text(`${mB}:${sB}`);
            } else {
                $('#timerBottom').text(`${mB}:${sB}`); $('#timerTop').text(`${mW}:${sW}`);
            }

            if(timeWhite <= 0 || timeBlack <= 0) {
                clearInterval(timerInterval); gameActive = false;
                $.post('index.php?game=xadrez', {acao: 'reivindicar_tempo', id_partida: gameId}, function(data){
                    if(data.sucesso) { alert(data.msg); window.location.href = 'index.php?game=xadrez'; }
                }, 'json');
            }
        }, 1000);
    }
    startClock();

    setInterval(function() {
        if(!gameActive) return;
        $.post('index.php?game=xadrez', {acao: 'buscar_estado', id_partida: gameId}, function(data) {
            timeWhite = parseInt(data.tempo_brancas);
            timeBlack = parseInt(data.tempo_pretas);
            if (data.fen && data.fen !== game.fen()) {
                game.load(data.fen);
                if(data.pgn) game.load_pgn(data.pgn);
                board.position(data.fen);
                updateUI();
            }
            if (data.status === 'finalizada' || data.status === 'empate') {
                gameActive = false; alert('Partida finalizada!'); window.location.href = 'index.php?game=xadrez';
            }
        }, 'json');
    }, 2000);

    $(window).resize(board.resize);
    <?php endif; ?>
</script>

</body>
</html>
