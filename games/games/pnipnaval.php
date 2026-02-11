<?php
// batalhanaval.php - BATALHA NAVAL COM LOBBY (MODELO XADREZ 🚢💥)
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require '../core/conexao.php';

// 1. Segurança
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];

// 2. Configuração do Banco de Dados
try {
    // Cria a tabela se não existir
    $pdo->exec("CREATE TABLE IF NOT EXISTS naval_salas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        status VARCHAR(20) DEFAULT 'aguardando',
        id_jog1 INT,
        id_jog2 INT,
        valor_aposta INT DEFAULT 10,
        vez_de INT,
        vencedor_id INT DEFAULT NULL,
        data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP,
        ultimo_update DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // [CORREÇÃO] Adiciona a coluna valor_aposta se ela não existir (Migração Automática)
    try {
        $pdo->exec("ALTER TABLE naval_salas ADD COLUMN valor_aposta INT DEFAULT 10");
    } catch (Exception $e) {
        // Ignora erro se a coluna já existir
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS naval_tabuleiros (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_sala INT,
        id_usuario INT,
        navios TEXT,
        tiros_recebidos TEXT,
        pronto TINYINT(1) DEFAULT 0
    )");

    $stmtMe = $pdo->prepare("SELECT nome, pontos FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);

    // 3. BUSCA RANKING DE VITÓRIAS (NOVO 🏆)
    $stmtRank = $pdo->query("
        SELECT u.nome, COUNT(s.id) as vitorias
        FROM naval_salas s
        JOIN usuarios u ON s.vencedor_id = u.id
        WHERE s.status = 'fim'
        GROUP BY s.vencedor_id
        ORDER BY vitorias DESC
        LIMIT 5
    ");
    $ranking_naval = $stmtRank->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erro DB: " . $e->getMessage());
}

// --- API AJAX ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');
    $acao = $_POST['acao'];

    try {
        // A. ATUALIZAR LOBBY (HTML)
        if ($acao == 'atualizar_lobby') {
            header('Content-Type: text/html; charset=utf-8');
            
            $stmt = $pdo->prepare("
                SELECT s.*, u.nome as criador 
                FROM naval_salas s
                JOIN usuarios u ON s.id_jog1 = u.id
                WHERE s.status = 'aguardando'
                ORDER BY s.id DESC
            ");
            $stmt->execute();
            $salas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($salas)) {
                echo '<tr><td colspan="4" class="text-center text-muted py-5"><i class="bi bi-radar display-4 d-block mb-3 opacity-25"></i><span class="small">Nenhum sinal no radar.</span><br><span class="small text-info">Inicie uma operação!</span></td></tr>';
            } else {
                foreach ($salas as $s) {
                    $valor = $s['valor_aposta'] ?? 10;
                    
                    if ($s['id_jog1'] == $user_id) {
                        // Quem criou a sala pode desistir e pegar de volta os pontos
                        $btn = '<div class="d-flex gap-2">
                            <span class="badge bg-secondary bg-opacity-50 border border-secondary text-light"><i class="bi bi-hourglass-split me-1"></i>Aguardando</span>
                            <button onclick="desistirSala('.$s['id'].', '.$valor.')" class="btn btn-sm btn-outline-danger fw-bold"><i class="bi bi-flag-fill me-1"></i>Desistir</button>
                        </div>';
                    } else {
                        // Outros podem entrar na sala
                        $btn = '<button onclick="entrarSala('.$s['id'].', '.$valor.')" class="btn btn-sm btn-success fw-bold shadow-sm px-3"><i class="bi bi-crosshair me-1"></i>COMBATER</button>';
                    }
                    
                    echo "<tr class='align-middle'>
                        <td class='ps-3'>
                            <div class='d-flex align-items-center'>
                                <div class='rounded-circle bg-dark border border-secondary d-flex align-items-center justify-content-center me-2' style='width: 32px; height: 32px;'><i class='bi bi-person-fill text-secondary'></i></div>
                                <span class='fw-bold text-light'>{$s['criador']}</span>
                            </div>
                        </td>
                        <td class='text-warning fw-bold'><i class='bi bi-coin me-1'></i>{$valor}</td>
                        <td><span class='badge bg-info bg-opacity-25 text-info border border-info'>No Lobby</span></td>
                        <td class='text-end pe-3'>$btn</td>
                    </tr>";
                }
            }
            exit;
        }

        // B. CRIAR SALA
        if ($acao == 'criar_sala') {
            $valor = (int)$_POST['valor'];
            if ($valor < 10) throw new Exception("Aposta mínima é 10.");

            $pdo->beginTransaction();
            
            // Verifica Saldo
            $stmtS = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
            $stmtS->execute([':id' => $user_id]);
            if ($stmtS->fetchColumn() < $valor) throw new Exception("Saldo insuficiente.");

            // Cria Sala
            $stmtIns = $pdo->prepare("INSERT INTO naval_salas (id_jog1, valor_aposta, status) VALUES (:uid, :val, 'aguardando')");
            $stmtIns->execute([':uid' => $user_id, ':val' => $valor]);
            $sala_id = $pdo->lastInsertId();

            // Debita do Criador
            $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :uid")->execute([':val' => $valor, ':uid' => $user_id]);

            // Prepara Tabuleiro do Criador
            $pdo->prepare("INSERT INTO naval_tabuleiros (id_sala, id_usuario, navios, tiros_recebidos) VALUES (:sid, :uid, '[]', '[]')")
                ->execute([':sid' => $sala_id, ':uid' => $user_id]);

            $pdo->commit();
            echo json_encode(['sucesso' => true, 'sala_id' => $sala_id]);
            exit;
        }

        // B.5 DESISTIR DA SALA (REEMBOLSAR)
        if ($acao == 'desistir_sala') {
            $sala_id = (int)$_POST['sala_id'];
            $valor = (int)$_POST['valor'];

            $pdo->beginTransaction();

            // Verifica se é o criador da sala
            $stmtS = $pdo->prepare("SELECT * FROM naval_salas WHERE id = :id FOR UPDATE");
            $stmtS->execute([':id' => $sala_id]);
            $sala = $stmtS->fetch(PDO::FETCH_ASSOC);

            if (!$sala) throw new Exception("Sala não existe.");
            if ($sala['id_jog1'] != $user_id) throw new Exception("Você não criou esta sala.");
            if ($sala['status'] != 'aguardando') throw new Exception("Sala não está mais disponível.");

            // Reembolsa os pontos ao criador
            $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :uid")
                ->execute([':val' => $valor, ':uid' => $user_id]);

            // Deleta a sala
            $pdo->prepare("DELETE FROM naval_salas WHERE id = :id")->execute([':id' => $sala_id]);
            $pdo->prepare("DELETE FROM naval_tabuleiros WHERE id_sala = :id")->execute([':id' => $sala_id]);

            $pdo->commit();
            echo json_encode(['sucesso' => true, 'mensagem' => 'Sala encerrada. Pontos reembolsados!']);
            exit;
        }

        // C. ENTRAR NA SALA (DESAFIAR)
        if ($acao == 'entrar_sala') {
            $sala_id = $_POST['sala_id'];
            
            $pdo->beginTransaction();
            
            // Verifica Sala
            $stmtCheck = $pdo->prepare("SELECT * FROM naval_salas WHERE id = :id FOR UPDATE");
            $stmtCheck->execute([':id' => $sala_id]);
            $sala = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$sala || $sala['status'] !== 'aguardando') throw new Exception("Esta batalha já começou ou não existe.");
            if ($sala['id_jog1'] == $user_id) throw new Exception("Você não pode jogar contra si mesmo.");

            // Garante valor da aposta (fallback)
            $aposta = $sala['valor_aposta'] ?? 10;

            // Verifica Saldo
            $stmtS = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id FOR UPDATE");
            $stmtS->execute([':id' => $user_id]);
            if ($stmtS->fetchColumn() < $aposta) throw new Exception("Saldo insuficiente.");

            // Atualiza Sala
            $pdo->prepare("UPDATE naval_salas SET id_jog2 = :uid, status = 'posicionando', ultimo_update = NOW() WHERE id = :id")
                ->execute([':uid' => $user_id, ':id' => $sala_id]);

            // Debita do Desafiante
            $pdo->prepare("UPDATE usuarios SET pontos = pontos - :val WHERE id = :uid")
                ->execute([':val' => $aposta, ':uid' => $user_id]);

            // Prepara Tabuleiro do Desafiante
            $pdo->prepare("INSERT INTO naval_tabuleiros (id_sala, id_usuario, navios, tiros_recebidos) VALUES (:sid, :uid, '[]', '[]')")
                ->execute([':sid' => $sala_id, ':uid' => $user_id]);

            $pdo->commit();
            echo json_encode(['sucesso' => true, 'sala_id' => $sala_id]);
            exit;
        }

        // D. BUSCAR ESTADO (POLLING)
        if ($acao == 'buscar_estado') {
            $sala_id = $_POST['sala_id'];
            
            // Busca Sala e Nomes dos Jogadores
            $stmtS = $pdo->prepare("
                SELECT s.*, u1.nome as nome1, u2.nome as nome2 
                FROM naval_salas s
                LEFT JOIN usuarios u1 ON s.id_jog1 = u1.id
                LEFT JOIN usuarios u2 ON s.id_jog2 = u2.id
                WHERE s.id = :id
            ");
            $stmtS->execute([':id' => $sala_id]);
            $sala = $stmtS->fetch(PDO::FETCH_ASSOC);

            if (!$sala) die(json_encode(['erro' => 'Sala encerrada ou inexistente']));

            // Identifica quem é quem
            $sou_jog1 = ($sala['id_jog1'] == $user_id);
            $nome_oponente = $sou_jog1 ? ($sala['nome2'] ?? 'Aguardando...') : $sala['nome1'];
            
            // Busca Meu Tabuleiro
            $stmtMe = $pdo->prepare("SELECT * FROM naval_tabuleiros WHERE id_sala = :sid AND id_usuario = :uid");
            $stmtMe->execute([':sid' => $sala_id, ':uid' => $user_id]);
            $meuTab = $stmtMe->fetch(PDO::FETCH_ASSOC);

            // Busca Oponente (se existir)
            $oponente_id = $sou_jog1 ? $sala['id_jog2'] : $sala['id_jog1'];
            $tabOponentePublico = [];
            
            if ($oponente_id) {
                $stmtOp = $pdo->prepare("SELECT tiros_recebidos, pronto FROM naval_tabuleiros WHERE id_sala = :sid AND id_usuario = :oid");
                $stmtOp->execute([':sid' => $sala_id, ':oid' => $oponente_id]);
                $dadosOp = $stmtOp->fetch(PDO::FETCH_ASSOC);
                
                if ($dadosOp) {
                    $tabOponentePublico = [
                        'tiros' => json_decode($dadosOp['tiros_recebidos'] ?? '[]', true),
                        'pronto' => $dadosOp['pronto']
                    ];
                }
            }

            echo json_encode([
                'status' => $sala['status'],
                'vez_de' => $sala['vez_de'],
                'vencedor_id' => $sala['vencedor_id'],
                'meus_navios' => json_decode($meuTab['navios'] ?? '[]'),
                'tiros_em_mim' => json_decode($meuTab['tiros_recebidos'] ?? '[]'),
                'oponente' => $tabOponentePublico,
                'nome_oponente' => $nome_oponente,
                'sou_eu' => $user_id,
                'valor_aposta' => $sala['valor_aposta'] ?? 10
            ]);
            exit;
        }

        // E. CONFIRMAR NAVIOS
        if ($acao == 'confirmar_navios') {
            $sala_id = $_POST['sala_id'];
            $navios_json = $_POST['navios']; 
            
            $pdo->prepare("UPDATE naval_tabuleiros SET navios = :n, pronto = 1 WHERE id_sala = :sid AND id_usuario = :uid")
                ->execute([':n' => $navios_json, ':sid' => $sala_id, ':uid' => $user_id]);

            // Se ambos prontos, começa o jogo
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM naval_tabuleiros WHERE id_sala = :sid AND pronto = 1");
            $stmtCheck->execute([':sid' => $sala_id]);
            
            // Precisamos garantir que tem 2 jogadores na sala antes de iniciar
            $stmtJogadores = $pdo->prepare("SELECT id_jog1, id_jog2 FROM naval_salas WHERE id = :id");
            $stmtJogadores->execute([':id' => $sala_id]);
            $salaInfos = $stmtJogadores->fetch();

            if ($stmtCheck->fetchColumn() == 2 && $salaInfos['id_jog1'] && $salaInfos['id_jog2']) {
                $pdo->prepare("UPDATE naval_salas SET status = 'jogando', vez_de = :vez WHERE id = :id")
                    ->execute([':vez' => $salaInfos['id_jog1'], ':id' => $sala_id]);
            }
            
            echo json_encode(['sucesso' => true]);
            exit;
        }

        // F. ATIRAR
        if ($acao == 'atirar') {
            $sala_id = $_POST['sala_id'];
            $x = (int)$_POST['x'];
            $y = (int)$_POST['y'];

            $pdo->beginTransaction();

            $stmtS = $pdo->prepare("SELECT * FROM naval_salas WHERE id = :id FOR UPDATE");
            $stmtS->execute([':id' => $sala_id]);
            $sala = $stmtS->fetch(PDO::FETCH_ASSOC);

            if ($sala['vez_de'] != $user_id) throw new Exception("Não é sua vez!");
            if ($sala['status'] != 'jogando') throw new Exception("Jogo não está ativo.");

            $oponente_id = ($sala['id_jog1'] == $user_id) ? $sala['id_jog2'] : $sala['id_jog1'];

            $stmtTab = $pdo->prepare("SELECT * FROM naval_tabuleiros WHERE id_sala = :sid AND id_usuario = :oid");
            $stmtTab->execute([':sid' => $sala_id, ':oid' => $oponente_id]);
            $tabOponente = $stmtTab->fetch(PDO::FETCH_ASSOC);

            $naviosOponente = json_decode($tabOponente['navios'], true);
            $tirosRecebidos = json_decode($tabOponente['tiros_recebidos'], true);

            foreach ($tirosRecebidos as $t) {
                if ($t['x'] == $x && $t['y'] == $y) throw new Exception("Já atirou aqui!");
            }

            $acertou = false;
            foreach ($naviosOponente as $n) {
                if ($n['x'] == $x && $n['y'] == $y) {
                    $acertou = true;
                    break;
                }
            }

            $tirosRecebidos[] = ['x' => $x, 'y' => $y, 'hit' => $acertou];
            $pdo->prepare("UPDATE naval_tabuleiros SET tiros_recebidos = :tr WHERE id = :id")
                ->execute([':tr' => json_encode($tirosRecebidos), ':id' => $tabOponente['id']]);

            // Verifica Vitória
            $totalPartes = count($naviosOponente);
            $totalAcertos = 0;
            foreach ($tirosRecebidos as $t) { if ($t['hit']) $totalAcertos++; }

            if ($totalAcertos >= $totalPartes) {
                $pdo->prepare("UPDATE naval_salas SET status = 'fim', vencedor_id = :vid WHERE id = :id")
                    ->execute([':vid' => $user_id, ':id' => $sala_id]);
                
                // Paga Prêmio (Total das apostas)
                $premio = ($sala['valor_aposta'] ?? 10) * 2;
                $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")->execute([':val' => $premio, ':id' => $user_id]);
            } else {
                if (!$acertou) {
                    $pdo->prepare("UPDATE naval_salas SET vez_de = :vez WHERE id = :id")
                        ->execute([':vez' => $oponente_id, ':id' => $sala_id]);
                }
            }
            
            $pdo->prepare("UPDATE naval_salas SET ultimo_update = NOW() WHERE id = :id")->execute([':id' => $sala_id]);

            $pdo->commit();
            echo json_encode(['sucesso' => true, 'hit' => $acertou]);
            exit;
        }

        // G. DESISTIR
        if ($acao == 'desistir') {
            $sala_id = $_POST['sala_id'];

            $pdo->beginTransaction();

            $stmtS = $pdo->prepare("SELECT * FROM naval_salas WHERE id = :id FOR UPDATE");
            $stmtS->execute([':id' => $sala_id]);
            $sala = $stmtS->fetch(PDO::FETCH_ASSOC);

            if (!$sala) throw new Exception("Sala inexistente ou já encerrada.");

            // Determina o oponente
            $oponente_id = ($sala['id_jog1'] == $user_id) ? $sala['id_jog2'] : $sala['id_jog1'];

            if ($oponente_id) {
                // Marca vitória para o oponente e paga prêmio
                $pdo->prepare("UPDATE naval_salas SET status = 'fim', vencedor_id = :vid WHERE id = :id")
                    ->execute([':vid' => $oponente_id, ':id' => $sala_id]);

                $premio = ($sala['valor_aposta'] ?? 10) * 2;
                $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")
                    ->execute([':val' => $premio, ':id' => $oponente_id]);

                $pdo->commit();
                echo json_encode(['sucesso' => true, 'mensagem' => 'Você desistiu. O oponente venceu.']);
                exit;
            } else {
                // Sem oponente: encerra sala e reembolsa o criador
                // Se o usuário que criou é quem desistiu, reembolsa
                if ($sala['id_jog1'] == $user_id) {
                    $pdo->prepare("UPDATE usuarios SET pontos = pontos + :val WHERE id = :id")
                        ->execute([':val' => ($sala['valor_aposta'] ?? 10), ':id' => $user_id]);
                }
                $pdo->prepare("UPDATE naval_salas SET status = 'fim' WHERE id = :id")->execute([':id' => $sala_id]);
                $pdo->commit();
                echo json_encode(['sucesso' => true, 'mensagem' => 'Sala encerrada e aposta reembolsada.']);
                exit;
            }
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['erro' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batalha Naval - Lobby</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #020617; color: #e2e8f0; font-family: 'Segoe UI', sans-serif; }
        
        /* Navbar */
        .navbar-custom { background: #0f172a; border-bottom: 1px solid #1e293b; padding: 15px; }
        
        /* Cards do Hub */
        .card-hub { background: #1e293b; border: 1px solid #334155; border-radius: 12px; transition: transform 0.2s; }
        .card-hub:hover { border-color: #3b82f6; transform: translateY(-2px); }
        .card-header-hub { background: rgba(15, 23, 42, 0.5); border-bottom: 1px solid #334155; padding: 15px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        
        /* Ranking de Almirantes */
        .rank-list-item { border-bottom: 1px solid #334155; padding: 12px 0; display: flex; justify-content: space-between; align-items: center; }
        .rank-list-item:last-child { border-bottom: none; }
        .medal { font-size: 1.2em; min-width: 30px; text-align: center; }
        
        /* Jogo */
        .grid-box { background: #1e293b; padding: 15px; border-radius: 10px; border: 2px solid #334155; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5); }
        .battle-grid { display: grid; grid-template-columns: repeat(10, 32px); grid-template-rows: repeat(10, 32px); gap: 2px; background: #0f172a; border: 2px solid #3b82f6; padding: 2px; }
        .cell { width: 32px; height: 32px; background: #334155; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .cell:hover { background: #475569; }
        .cell.ship { background: #64748b; border: 2px solid #94a3b8; } 
        .cell.water { background: #3b82f6 !important; opacity: 0.5; } 
        .cell.hit { background: #ef4444 !important; } 
        .cell.hit::after { content: '💥'; font-size: 18px; }
        .cell.water::after { content: '🌊'; font-size: 14px; opacity: 0.7; }
        
        /* Setup */
        .ship-selector { display: flex; gap: 10px; margin-bottom: 15px; justify-content: center; flex-wrap: wrap; }
        .ship-btn { padding: 5px 10px; border: 1px solid #475569; border-radius: 5px; cursor: pointer; background: #1e293b; transition: 0.2s; }
        .ship-btn.active { background: #3b82f6; border-color: #60a5fa; color: white; transform: scale(1.05); }
        .ship-btn.disabled { opacity: 0.3; cursor: not-allowed; }

        /* Tabela Dark */
        .table-dark-custom { --bs-table-bg: transparent; --bs-table-color: #e2e8f0; --bs-table-border-color: #334155; }
        .table-dark-custom th { border-top: none; font-weight: 600; color: #94a3b8; font-size: 0.85em; text-transform: uppercase; }
        .table-dark-custom tr:hover td { color: #fff; background-color: rgba(59, 130, 246, 0.1); }
    </style>
</head>
<body>

<div class="navbar-custom d-flex justify-content-between align-items-center mb-5 shadow-sm">
    <div class="d-flex align-items-center gap-3">
    <span class="fs-5 fw-bold text-info"><i class="bi bi-tsunami me-2"></i>Batalha Naval</span>
        <span class="text-secondary small border-start border-secondary ps-3">Capitão <?= htmlspecialchars($meu_perfil['nome']) ?></span>
    </div>
    <div class="d-flex align-items-center gap-3">
        <a href="../index.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Sair</a>
        <span class="badge bg-primary fs-6 shadow-sm"><i class="bi bi-coin me-1"></i> <?= number_format($meu_perfil['pontos'], 0, ',', '.') ?></span>
    </div>
</div>

<div class="container">

    <!-- 1. LOBBY SECTION -->
    <div id="lobby-section">
        <div class="row g-4">
            
            <!-- Coluna da Esquerda: Operações (Ranking + Criar) -->
            <div class="col-md-4">
                
                <!-- Criar Sala -->
                <div class="card card-hub mb-4 shadow">
                    <div class="card-header-hub text-warning">
                        <i class="bi bi-plus-circle-fill me-2"></i>Iniciar Operação
                    </div>
                    <div class="card-body">
                        <p class="text-secondary small mb-3">Defina a aposta e aguarde um desafiante no radar.</p>
                        <div class="input-group mb-3">
                            <span class="input-group-text bg-dark border-secondary text-secondary">$</span>
                            <input type="number" id="betAmount" class="form-control bg-dark text-white border-secondary" value="10" min="10">
                        </div>
                        <button onclick="criarSala()" class="btn btn-warning w-100 fw-bold shadow-sm">
                            <i class="bi bi-broadcast me-1"></i> CRIAR SALA
                        </button>
                    </div>
                </div>

                <!-- Ranking -->
                <div class="card card-hub shadow">
                    <div class="card-header-hub text-info">
                        <i class="bi bi-trophy-fill me-2"></i>Almirantes do Mar
                    </div>
                    <div class="card-body pt-0">
                        <?php if(empty($ranking_naval)): ?>
                            <div class="text-center py-3 text-muted small">Sem dados de vitórias.</div>
                        <?php else: ?>
                            <?php foreach($ranking_naval as $idx => $r): ?>
                                <div class="rank-list-item">
                                    <div class="d-flex align-items-center">
                                        <div class="medal">
                                            <?php 
                                            if($idx == 0) echo '🥇';
                                            elseif($idx == 1) echo '🥈';
                                            elseif($idx == 2) echo '🥉';
                                            else echo '<span class="text-secondary small">#'.($idx+1).'</span>';
                                            ?>
                                        </div>
                                        <div class="fw-bold text-light ms-2"><?= htmlspecialchars($r['nome']) ?></div>
                                    </div>
                                    <span class="badge bg-dark border border-secondary text-info rounded-pill"><?= $r['vitorias'] ?> vitórias</span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- Coluna da Direita: Lista de Salas (Radar) -->
            <div class="col-md-8">
                <div class="card card-hub h-100 shadow-lg">
                    <div class="card-header-hub text-success d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-radar me-2"></i>Radar de Batalhas</span>
                        <div class="spinner-grow text-success spinner-grow-sm" role="status"></div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark-custom table-hover mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th class="ps-4">Capitão</th>
                                        <th>Aposta</th>
                                        <th>Status</th>
                                        <th class="text-end pe-4">Ação</th>
                                    </tr>
                                </thead>
                                <tbody id="lobby-list">
                                    <tr><td colspan="4" class="text-center py-5 text-muted">Escaneando frequências...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. GAME SECTION -->
    <div id="game-section" style="display: none;">
        
        <!-- HEADER DO JOGO -->
        <div class="text-center mb-4">
            <h2 id="game-status-text" class="text-white fw-bold mb-3">PREPARAÇÃO</h2>
            <div class="d-inline-flex justify-content-center gap-3 align-items-center bg-dark px-4 py-2 rounded-pill border border-secondary shadow-lg">
                <div class="text-success fw-bold fs-5"><i class="bi bi-person-fill"></i> Você</div>
                <div class="text-secondary fw-bold small">VS</div>
                <div class="text-danger fw-bold fs-5" id="enemy-name"><i class="bi bi-incognito"></i> Oponente</div>
            </div>
            <div class="mt-3">
                <button class="btn btn-sm btn-outline-danger" onclick="desistir()"><i class="bi bi-flag-fill me-1"></i> Desistir</button>
            </div>
        </div>

        <!-- AREA DE SETUP -->
        <div id="setup-phase" class="text-center">
            <p class="text-secondary mb-3">Posicione sua frota estrategicamente.</p>
            <div class="ship-selector">
                <div id="ship-carrier" class="ship-btn active" onclick="selectShip('carrier',5)">Porta-Aviões (5)</div>
                <div id="ship-battleship" class="ship-btn" onclick="selectShip('battleship',4)">Encouraçado (4)</div>
                <div id="ship-submarine" class="ship-btn" onclick="selectShip('submarine',3)">Submarino (3)</div>
                <div id="ship-cruiser" class="ship-btn" onclick="selectShip('cruiser',3)">Cruzador (3)</div>
                <div id="ship-destroyer" class="ship-btn" onclick="selectShip('destroyer',2)">Destróier (2)</div>
            </div>
            <button class="btn btn-outline-info btn-sm mb-3 rounded-pill px-3" onclick="rotateShip()"><i class="bi bi-arrow-repeat me-1"></i> Girar (R)</button>
            
            <div class="d-flex justify-content-center mb-4">
                <div class="grid-box">
                    <div class="battle-grid" id="setup-grid"></div>
                </div>
            </div>
            
            <div class="d-flex justify-content-center gap-2">
                <button class="btn btn-outline-danger px-4 rounded-pill" onclick="resetSetup()">Limpar</button>
                <button class="btn btn-success fw-bold px-5 rounded-pill shadow" id="btn-confirm" onclick="confirmarNavios()" disabled>
                    <i class="bi bi-check-lg me-1"></i> CONFIRMAR FROTA
                </button>
            </div>
        </div>

        <!-- AREA DE BATALHA -->
        <div id="battle-phase" class="row justify-content-center g-5" style="display: none;">
            <!-- MEU TABULEIRO -->
            <div class="col-auto">
                <div class="grid-box">
                    <div class="text-info fw-bold mb-3 text-center small tracking-wider">MINHA FROTA</div>
                    <div class="battle-grid" id="my-grid"></div>
                </div>
            </div>
            <!-- TABULEIRO INIMIGO -->
            <div class="col-auto">
                <div class="grid-box border-danger">
                    <div class="text-danger fw-bold mb-3 text-center small tracking-wider">RADAR INIMIGO</div>
                    <div class="battle-grid" id="enemy-grid"></div>
                </div>
            </div>
        </div>

    </div>

</div>

<script>
    let salaId = null;
    let myId = <?= $user_id ?>;
    let pollInterval = null;
    
    // Setup Vars
    let placedShips = []; 
    // ships: track by id so each type can only be placed once
    let shipSelectedId = 'carrier';
    let currentShipSize = 5;
    let isVertical = false;
    let shipsPlacedCount = 0;
    const availableShips = {
        carrier: { size: 5, placed: false, btnId: 'ship-carrier' },
        battleship: { size: 4, placed: false, btnId: 'ship-battleship' },
        submarine: { size: 3, placed: false, btnId: 'ship-submarine' },
        cruiser: { size: 3, placed: false, btnId: 'ship-cruiser' },
        destroyer: { size: 2, placed: false, btnId: 'ship-destroyer' }
    };

    // --- LOBBY LOGIC ---
    function updateLobby() {
        if(salaId) return; // Se já estou em jogo, não atualiza lobby
        const fd = new FormData(); fd.append('acao', 'atualizar_lobby');
    fetch('batalhanaval.php', { method: 'POST', body: fd })
            .then(r => r.text())
            .then(html => { document.getElementById('lobby-list').innerHTML = html; });
    }
    
    // Polling do Lobby
    setInterval(updateLobby, 3000);
    updateLobby();

    function criarSala() {
        let val = document.getElementById('betAmount').value;
        if(!confirm('Criar sala apostando '+val+' pontos?')) return;
        
        const fd = new FormData(); fd.append('acao', 'criar_sala'); fd.append('valor', val);
    fetch('batalhanaval.php', { method: 'POST', body: fd })
            .then(r => {
                if(!r.ok) throw new Error('Resposta do servidor: ' + r.status);
                return r.json();
            })
            .then(d => {
                if(d.erro) alert('Erro: ' + d.erro);
                else if(d.sucesso) enterGame(d.sala_id);
                else alert('Erro desconhecido ao criar sala.');
            })
            .catch(err => alert('Erro ao conectar: ' + err.message));
    }

    function entrarSala(id, val) {
        if(!confirm('Entrar na batalha por '+val+' pontos?')) return;
        
        const fd = new FormData(); fd.append('acao', 'entrar_sala'); fd.append('sala_id', id);
    fetch('batalhanaval.php', { method: 'POST', body: fd })
            .then(r => {
                if(!r.ok) throw new Error('Resposta do servidor: ' + r.status);
                return r.json();
            })
            .then(d => {
                if(d.erro) alert('Erro: ' + d.erro);
                else if(d.sucesso) enterGame(d.sala_id);
                else alert('Erro desconhecido ao entrar na sala.');
            })
            .catch(err => alert('Erro ao conectar: ' + err.message));
    }

    function enterGame(id) {
        salaId = id;
        document.getElementById('lobby-section').style.display = 'none';
        document.getElementById('game-section').style.display = 'block';
        initSetupGrid();
        pollInterval = setInterval(gameLoop, 2000);
    }

    function desistirSala(salaId, valor) {
        if(!confirm('Desistir da sala e recuperar '+valor+' pontos?')) return;
        
        const fd = new FormData(); 
        fd.append('acao', 'desistir_sala'); 
        fd.append('sala_id', salaId);
        fd.append('valor', valor);
        
    fetch('batalhanaval.php', { method: 'POST', body: fd })
            .then(r => {
                if(!r.ok) throw new Error('Resposta do servidor: ' + r.status);
                return r.json();
            })
            .then(d => {
                if(d.erro) alert('Erro: ' + d.erro);
                else {
                    alert(d.mensagem || 'Sala encerrada.');
                    updateLobby(); // Atualiza o radar de batalhas
                }
            })
            .catch(err => alert('Erro ao desistir: ' + err.message));
    }    // --- GAME LOGIC ---
    function gameLoop() {
        if(!salaId) return;
        const fd = new FormData(); fd.append('acao', 'buscar_estado'); fd.append('sala_id', salaId);
    fetch('batalhanaval.php', { method: 'POST', body: fd })
            .then(r => {
                if(!r.ok) throw new Error('Resposta do servidor: ' + r.status);
                return r.json();
            })
            .then(d => {
                if(d.erro) { alert(d.erro); clearInterval(pollInterval); location.reload(); return; }

                // Atualiza Nome do Inimigo
                document.getElementById('enemy-name').innerHTML = '<i class="bi bi-crosshair"></i> ' + d.nome_oponente;

                // Logica de Estados
                if(d.status == 'aguardando') {
                    document.getElementById('game-status-text').innerText = "AGUARDANDO OPONENTE...";
                    document.getElementById('setup-phase').style.display = 'none'; 
                    document.getElementById('setup-phase').style.display = 'block';
                }
                else if(d.status == 'posicionando') {
                    document.getElementById('game-status-text').innerText = "POSICIONAMENTO";
                    document.getElementById('setup-phase').style.display = 'block';
                    document.getElementById('battle-phase').style.display = 'none';
                } 
                else if(d.status == 'jogando') {
                    document.getElementById('setup-phase').style.display = 'none';
                    document.getElementById('battle-phase').style.display = 'flex'; 
                    
                    let msg = (d.vez_de == myId) ? "SUA VEZ! ATAQUE!" : "VEZ DO INIMIGO...";
                    let color = (d.vez_de == myId) ? "text-success" : "text-warning";
                    document.getElementById('game-status-text').innerHTML = `<span class="${color}">${msg}</span>`;
                    
                    renderBattle(d);
                }
                else if(d.status == 'fim') {
                    clearInterval(pollInterval);
                    if(d.vencedor_id == myId) alert('VITÓRIA! Você ganhou a aposta.');
                    else alert('DERROTA! Mais sorte na próxima.');
                    location.reload();
                }
            })
            .catch(err => {
                console.error('Erro ao buscar estado:', err);
                clearInterval(pollInterval);
            });
    }

    // --- SETUP FUNCTIONS ---
    function initSetupGrid() {
        const grid = document.getElementById('setup-grid');
        grid.innerHTML = '';
        for(let y=0; y<10; y++) {
            for(let x=0; x<10; x++) {
                let cell = document.createElement('div');
                cell.className = 'cell';
                cell.dataset.x = x; cell.dataset.y = y;
                cell.onclick = () => placeShip(x, y);
                cell.onmouseover = () => previewShip(x, y);
                grid.appendChild(cell);
            }
        }
    }

    function selectShip(id, size) {
        // If already placed, prevent re-selection
        if (availableShips[id] && availableShips[id].placed) {
            alert('Este navio já foi posicionado.');
            return;
        }
        shipSelectedId = id;
        currentShipSize = size;
        // visual active toggle
        document.querySelectorAll('.ship-btn').forEach(b => b.classList.remove('active'));
        const btn = document.getElementById(availableShips[id].btnId);
        if (btn) btn.classList.add('active');
    }
    function rotateShip() { isVertical = !isVertical; }

    function previewShip(tx, ty) {
        document.querySelectorAll('#setup-grid .cell').forEach(c => {
            if(!c.classList.contains('ship')) c.style.background = '#334155';
        });
        let cells = getShipCells(tx, ty, currentShipSize, isVertical);
        if(isValidPlacement(cells)) {
            cells.forEach(c => {
                let el = document.querySelector(`#setup-grid .cell[data-x="${c.x}"][data-y="${c.y}"]`);
                if(el) el.style.background = '#4ade80';
            });
        }
    }

    function placeShip(tx, ty) {
        // Prevent placing more than 5 ships
        if (shipsPlacedCount >= 5) { alert('Você já posicionou o número máximo de 5 frotas.'); return; }

        // Prevent placing the same ship twice
        if (!availableShips[shipSelectedId]) { alert('Selecione um navio válido.'); return; }
        if (availableShips[shipSelectedId].placed) { alert('Este navio já foi posicionado.'); return; }

        let cells = getShipCells(tx, ty, currentShipSize, isVertical);
        if(!isValidPlacement(cells)) return;

        // store ship as group (object with id and cells)
        placedShips.push({ id: shipSelectedId, cells: cells });
        shipsPlacedCount++;
        availableShips[shipSelectedId].placed = true;

        cells.forEach(c => {
            let el = document.querySelector(`#setup-grid .cell[data-x="${c.x}"][data-y="${c.y}"]`);
            if(el) el.className = 'cell ship';
        });

        // disable button visually
        const btn = document.getElementById(availableShips[shipSelectedId].btnId);
        if (btn) btn.classList.add('disabled');

        if(shipsPlacedCount >= 5) {
            document.getElementById('btn-confirm').disabled = false;
        }
    }

    function getShipCells(x, y, size, vert) {
        let cells = [];
        for(let i=0; i<size; i++) cells.push({ x: vert ? x : x+i, y: vert ? y+i : y });
        return cells;
    }

    function isValidPlacement(cells) {
        for(let c of cells) if(c.x > 9 || c.y > 9) return false;
        for(let c of cells) if(placedShips.some(s => s.x == c.x && s.y == c.y)) return false;
        return true;
    }

    function resetSetup() {
        placedShips = []; shipsPlacedCount = 0;
        // reset available ships
        for (let k in availableShips) { availableShips[k].placed = false; const b = document.getElementById(availableShips[k].btnId); if (b) b.classList.remove('disabled'); }
        // reset active to carrier
        selectShip('carrier', availableShips['carrier'].size);
        initSetupGrid();
        document.getElementById('btn-confirm').disabled = true;
    }

    function confirmarNavios() {
        if (shipsPlacedCount < 5) { alert('Posicione todas as 5 frotas antes de confirmar.'); return; }

        // convert placedShips (groups) to a flat list of cells to store
        let flatCells = [];
        placedShips.forEach(s => { s.cells.forEach(c => flatCells.push(c)); });

        const fd = new FormData();
        fd.append('acao', 'confirmar_navios');
        fd.append('sala_id', salaId);
        fd.append('navios', JSON.stringify(flatCells)); 
    fetch('batalhanaval.php', { method: 'POST', body: fd })
            .then(r => {
                if(!r.ok) throw new Error('Resposta do servidor: ' + r.status);
                return r.json();
            })
            .then(d => {
                if(d.erro) { alert('Erro: ' + d.erro); return; }
                document.getElementById('setup-phase').innerHTML = "<h3 class='text-info animate__animated animate__pulse animate__infinite'>Frota Confirmada! Aguardando oponente...</h3>";
            })
            .catch(err => alert('Erro ao confirmar frota: ' + err.message));
    }

    // --- BATTLE RENDER ---
    function renderBattle(data) {
        renderGrid('my-grid', data.meus_navios, data.tiros_em_mim, false);
        renderGrid('enemy-grid', [], data.oponente.tiros, true);
    }

    function renderGrid(elementId, ships, shots, isEnemy) {
        const grid = document.getElementById(elementId);
        if(grid.childElementCount === 0) {
            for(let y=0; y<10; y++) {
                for(let x=0; x<10; x++) {
                    let cell = document.createElement('div');
                    cell.className = 'cell';
                    cell.id = `${elementId}-${x}-${y}`;
                    if(isEnemy) cell.onclick = () => atirar(x, y);
                    grid.appendChild(cell);
                }
            }
        }

        ships.forEach(s => {
            let c = document.getElementById(`${elementId}-${s.x}-${s.y}`);
            if(c && !c.classList.contains('hit')) c.classList.add('ship');
        });

        if(shots) {
            shots.forEach(s => {
                let c = document.getElementById(`${elementId}-${s.x}-${s.y}`);
                if(c) {
                    c.className = 'cell ' + (s.hit ? 'hit' : 'water');
                    c.onclick = null;
                }
            });
        }
    }

    function atirar(x, y) {
        let cell = document.getElementById(`enemy-grid-${x}-${y}`);
        if(cell.classList.contains('hit') || cell.classList.contains('water')) return;

        const fd = new FormData(); 
        fd.append('acao', 'atirar'); 
        fd.append('sala_id', salaId);
        fd.append('x', x); fd.append('y', y);

    fetch('batalhanaval.php', { method: 'POST', body: fd })
            .then(r => {
                if(!r.ok) throw new Error('Resposta do servidor: ' + r.status);
                return r.json();
            })
            .then(d => {
                if(d.erro) alert('Erro: ' + d.erro);
            })
            .catch(err => alert('Erro ao atirar: ' + err.message));
    }

    document.addEventListener('keydown', (e) => { if(e.key === 'r' || e.key === 'R') rotateShip(); });

    // Desistir da partida
    function desistir() {
        if(!salaId) return alert('Você não está em uma sala.');
        if(!confirm('Deseja desistir da partida? O oponente será declarado vencedor.')) return;
        const fd = new FormData(); fd.append('acao', 'desistir'); fd.append('sala_id', salaId);
    fetch('batalhanaval.php', { method: 'POST', body: fd })
            .then(r => {
                if(!r.ok) throw new Error('Resposta do servidor: ' + r.status);
                return r.json();
            })
            .then(d => {
                if(d.erro) alert('Erro: ' + d.erro);
                else alert(d.mensagem || 'Você desistiu.');
                location.reload();
            })
            .catch(err => {
                alert('Erro ao desistir: ' + err.message);
                location.reload();
            });
    }
</script>
</body>
</html>
