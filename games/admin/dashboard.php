<?php
// admin.php - GERENCIADOR DE APOSTAS (DARK MODE PREMIUM 🌑)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require '../core/conexao.php';
require '../core/funcoes.php'; // Traz a função recalcularOdds()

// --- 1. SEGURANÇA ---
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

// Busca dados do admin para o Header
$stmt = $pdo->prepare("SELECT is_admin, nome, pontos FROM usuarios WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['is_admin'] != 1) {
    die("Acesso negado: Área restrita a administradores.");
}

$mensagem = "";

// --- 2. PROCESSAMENTO (POST) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    // A. CADASTRAR NOVO EVENTO
    if (isset($_POST['acao']) && $_POST['acao'] == 'criar_evento') {
        $nome_evento = trim($_POST['nome_evento']);
        $data_limite = $_POST['data_limite'];
        $opcoes_nomes = $_POST['opcoes_nomes']; 
        $opcoes_odds  = $_POST['opcoes_odds'];

        if (empty($nome_evento) || empty($data_limite) || count(array_filter($opcoes_nomes)) < 2) {
            $mensagem = "<div class='alert alert-warning bg-warning bg-opacity-10 border-warning text-warning'><i class='bi bi-exclamation-triangle me-2'></i>Preencha dados obrigatórios (mínimo 2 opções).</div>";
        } else {
            try {
                $pdo->beginTransaction(); 
                
                $sqlEvento = "INSERT INTO eventos (nome, data_limite, status) VALUES (:nome, :data, 'aberta')";
                $stmt = $pdo->prepare($sqlEvento);
                $stmt->execute([':nome' => $nome_evento, ':data' => $data_limite]);
                $evento_id = $pdo->lastInsertId();

                $sqlOpcao = "INSERT INTO opcoes (evento_id, descricao, odd) VALUES (:eid, :desc, :odd)";
                $stmtOpcao = $pdo->prepare($sqlOpcao);

                for ($i = 0; $i < count($opcoes_nomes); $i++) {
                    if (!empty($opcoes_nomes[$i])) {
                        $stmtOpcao->execute([
                            ':eid' => $evento_id, 
                            ':desc' => $opcoes_nomes[$i], 
                            ':odd' => $opcoes_odds[$i]
                        ]);
                    }
                }
                $pdo->commit();
                $mensagem = "<div class='alert alert-success bg-success bg-opacity-10 border-success text-success'><i class='bi bi-check-circle me-2'></i>Aposta criada com sucesso!</div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                $mensagem = "<div class='alert alert-danger bg-danger bg-opacity-10 border-danger text-danger'>Erro: " . $e->getMessage() . "</div>";
            }
        }
    }

    // B. EDITAR EVENTO EXISTENTE
    if (isset($_POST['acao']) && $_POST['acao'] == 'editar_evento') {
        $id_evento   = $_POST['id_evento'];
        $nome_evento = trim($_POST['nome_evento']);
        $data_limite = $_POST['data_limite'];
        
        $op_ids   = $_POST['opcoes_ids'] ?? []; 
        $op_nomes = $_POST['opcoes_nomes'];
        $op_odds  = $_POST['opcoes_odds'];

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE eventos SET nome = :nome, data_limite = :data WHERE id = :id");
            $stmt->execute([':nome' => $nome_evento, ':data' => $data_limite, ':id' => $id_evento]);

            $stmtUpd = $pdo->prepare("UPDATE opcoes SET descricao = :desc, odd = :odd WHERE id = :oid AND evento_id = :eid");
            $stmtIns = $pdo->prepare("INSERT INTO opcoes (evento_id, descricao, odd) VALUES (:eid, :desc, :odd)");

            for ($i = 0; $i < count($op_nomes); $i++) {
                $nome = trim($op_nomes[$i]);
                $odd  = $op_odds[$i];
                $oid  = $op_ids[$i] ?? '';

                if (!empty($nome)) {
                    if (!empty($oid)) {
                        $stmtUpd->execute([':desc' => $nome, ':odd' => $odd, ':oid' => $oid, ':eid' => $id_evento]);
                    } else {
                        $stmtIns->execute([':eid' => $id_evento, ':desc' => $nome, ':odd' => $odd]);
                    }
                }
            }
            
            $pdo->commit();
            $mensagem = "<div class='alert alert-success bg-success bg-opacity-10 border-success text-success'><i class='bi bi-pencil-square me-2'></i>Alterações salvas com sucesso!</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $mensagem = "<div class='alert alert-danger bg-danger bg-opacity-10 border-danger text-danger'>Erro ao editar: " . $e->getMessage() . "</div>";
        }
    }

    // C. RECALCULAR ODDS (MANUAL)
    if (isset($_POST['acao']) && $_POST['acao'] == 'recalcular_odds') {
        $id_evento = $_POST['id_evento'];
        try {
            if (recalcularOdds($pdo, $id_evento)) {
                 $mensagem = "<div class='alert alert-success bg-success bg-opacity-10 border-success text-success'><i class='bi bi-calculator me-2'></i>Odds balanceadas com sucesso!</div>";
            } else {
                 $mensagem = "<div class='alert alert-warning bg-warning bg-opacity-10 border-warning text-warning'>Não foi possível recalcular (verifique se há opções).</div>";
            }
        } catch (Exception $e) {
            $mensagem = "<div class='alert alert-danger bg-danger bg-opacity-10 border-danger text-danger'>Erro: " . $e->getMessage() . "</div>";
        }
    }

    // D. ENCERRAR EVENTO E PAGAR (CORRIGIDO PARA ODD CONGELADA ❄️)
    if (isset($_POST['acao']) && $_POST['acao'] == 'encerrar_evento') {
        $id_evento = $_POST['id_evento'];
        $vencedor_opcao_id = $_POST['vencedor_opcao_id'];

        if (empty($vencedor_opcao_id)) {
            $mensagem = "<div class='alert alert-warning bg-warning bg-opacity-10 border-warning text-warning'>Selecione quem ganhou!</div>";
        } else {
            try {
                $pdo->beginTransaction();
                
                // 1. Atualiza status do evento
                $pdo->prepare("UPDATE eventos SET status = 'encerrada', vencedor_opcao_id = ? WHERE id = ?")
                    ->execute([$vencedor_opcao_id, $id_evento]);

                // 2. Busca palpites vencedores usando a ODD REGISTRADA NO MOMENTO DA APOSTA
                // Nota: 'odd_registrada' é a coluna nova que você criou. Se não tiver, usa 'odd' da tabela opcoes como fallback inseguro.
                $sqlPagamento = "SELECT id_usuario, valor, odd_registrada FROM palpites WHERE opcao_id = ?";
                $busca = $pdo->prepare($sqlPagamento);
                $busca->execute([$vencedor_opcao_id]);
                
                $pagos = 0;
                $payStmt = $pdo->prepare("UPDATE usuarios SET pontos = pontos + ? WHERE id = ?");

                while ($row = $busca->fetch(PDO::FETCH_ASSOC)) {
                    // Usa a odd congelada. Se for nula (aposta antiga), usa 1.0 (ou trate como erro)
                    $odd_final = $row['odd_registrada'] ?? 1.0;
                    
                    $premio = $row['valor'] * $odd_final;
                    $payStmt->execute([$premio, $row['id_usuario']]);
                    $pagos++;
                }
                $pdo->commit();
                $mensagem = "<div class='alert alert-success bg-success bg-opacity-10 border-success text-success'><i class='bi bi-trophy-fill me-2'></i>Encerrado! $pagos apostas pagas corretamente.</div>";
            } catch (Exception $e) {
                $pdo->rollBack();
                $mensagem = "<div class='alert alert-danger bg-danger bg-opacity-10 border-danger text-danger'>Erro: " . $e->getMessage() . "</div>";
            }
        }
    }
}

// --- 3. BUSCAR DADOS ---
$filtro_status = isset($_GET['status']) && $_GET['status'] == 'encerrada' ? 'encerrada' : 'aberta';

$stmtEventos = $pdo->prepare("SELECT * FROM eventos WHERE status = ? ORDER BY data_limite ASC");
$stmtEventos->execute([$filtro_status]);
$eventos = $stmtEventos->fetchAll(PDO::FETCH_ASSOC);

foreach ($eventos as $key => $evt) {
    $sqlOpcoes = "SELECT o.*, 
                 (SELECT COUNT(*) FROM palpites p WHERE p.opcao_id = o.id) as total_palpites,
                 (SELECT SUM(valor) FROM palpites p WHERE p.opcao_id = o.id) as total_valor
                 FROM opcoes o WHERE o.evento_id = ?";
    
    $stmtOpcoes = $pdo->prepare($sqlOpcoes);
    $stmtOpcoes->execute([$evt['id']]);
    $eventos[$key]['opcoes'] = $stmtOpcoes->fetchAll(PDO::FETCH_ASSOC);
    
    $total_evento = 0;
    foreach($eventos[$key]['opcoes'] as $op) {
        $total_evento += $op['total_palpites'];
    }
    $eventos[$key]['total_apostas_evento'] = $total_evento;
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Gerenciar Apostas</title>
    
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>⚙️</text></svg>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <style>
        /* --- ESTILO DARK PREMIUM --- */
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
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
        
        /* Cards */
        .card-dark { 
            background-color: #1e1e1e; 
            border: 1px solid #333; 
            color: #e0e0e0;
            transition: box-shadow 0.3s;
        }
        .card-dark:hover {
            box-shadow: 0 10px 20px rgba(0,0,0,0.3);
        }
        
        .card-header-admin { 
            background: linear-gradient(45deg, #1e1e1e, #2c2c2c); 
            color: #fff; 
            border-bottom: 1px solid #444; 
            padding: 15px;
        }
        
        .card-header-edit { 
            background: linear-gradient(45deg, #ff6d00, #ff9100); 
            color: #000; 
            border-bottom: none;
        }
        
        /* Inputs */
        .form-control, .form-select { 
            background-color: #2b2b2b; 
            border: 1px solid #444; 
            color: #fff; 
        }
        .form-control::placeholder { color: #888; }
        .form-control:focus, .form-select:focus { 
            background-color: #2b2b2b; 
            border-color: #00e676; 
            color: #fff; 
            box-shadow: 0 0 0 0.25rem rgba(0, 230, 118, 0.25); 
        }
        
        /* Abas */
        .nav-pills { border-bottom: 1px solid #333; padding-bottom: 10px; margin-bottom: 20px; }
        .nav-pills .nav-link { color: #aaa; border-radius: 50px; padding: 8px 20px; transition: 0.3s; }
        .nav-pills .nav-link:hover { color: #fff; background-color: #333; }
        .nav-pills .nav-link.active { background-color: #00e676; color: #000; font-weight: bold; box-shadow: 0 0 10px rgba(0, 230, 118, 0.4); }
        
        /* Badges de Odds */
        .badge-odd { 
            background-color: #2b2b2b; 
            border: 1px solid #444; 
            font-size: 0.9em; 
            padding: 10px 15px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .badge-odd:hover { background-color: #333; border-color: #555; }
        .badge-winner { background-color: rgba(0, 230, 118, 0.15); border-color: #00e676; color: #00e676; }
        
    </style>
</head>
<body>

<!-- Header Padronizado -->
<div class="navbar-custom d-flex justify-content-between align-items-center shadow-lg sticky-top mb-4">
    <div class="d-flex align-items-center gap-3">
        <span class="fs-5 text-white">Olá Admin, <strong><?= htmlspecialchars($user['nome']) ?></strong></span>
        <!-- MENU ADMIN -->
        <a href="cafe.php" class="btn btn-outline-warning btn-sm fw-bold"><i class="bi bi-bank me-1"></i>Banco do Café</a>
    </div>
    
    <div class="d-flex align-items-center gap-3">
        <a href="../index.php" class="btn btn-outline-secondary btn-sm border-0"><i class="bi bi-arrow-left"></i> Voltar ao Site</a>
        <span class="saldo-badge me-2"><?= number_format($user['pontos'], 0, ',', '.') ?> pts</span>
    </div>
</div>

<div class="container pb-5">
    <?= $mensagem ?>

    <div class="row g-4">
        
        <!-- FORMULÁRIO (Esquerda) -->
        <div class="col-lg-4">
            <div class="card card-dark shadow-lg border-0 sticky-top" style="top: 100px; z-index: 100;" id="cardFormulario">
                <!-- Título muda via JS -->
                <div class="card-header card-header-admin fw-bold" id="formTitle"><i class="bi bi-plus-circle me-2"></i>Criar Nova Aposta</div>
                <div class="card-body">
                    <form method="POST" id="mainForm">
                        <input type="hidden" name="acao" id="acaoInput" value="criar_evento">
                        <input type="hidden" name="id_evento" id="idEventoInput">
                        
                        <div class="mb-3">
                            <label class="form-label text-secondary small text-uppercase fw-bold">Pergunta / Evento</label>
                            <input type="text" name="nome_evento" id="nomeEventoInput" class="form-control form-control-lg" placeholder="Ex: Quem ganha o jogo?" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label text-secondary small text-uppercase fw-bold">Data Limite</label>
                            <input type="datetime-local" name="data_limite" id="dataLimiteInput" class="form-control" required>
                        </div>

                        <hr class="border-secondary my-4">

                        <label class="form-label fw-bold text-info"><i class="bi bi-list-check me-2"></i>Opções de Aposta</label>
                        <div id="container-opcoes">
                            <!-- Campos iniciais -->
                            <div class="input-group mb-2">
                                <input type="hidden" name="opcoes_ids[]" value="">
                                <input type="text" name="opcoes_nomes[]" class="form-control" placeholder="Nome (Ex: Time A)" required>
                                <input type="number" step="0.01" name="opcoes_odds[]" class="form-control text-center" placeholder="Odd" style="max-width: 90px;" required>
                            </div>
                            <div class="input-group mb-2">
                                <input type="hidden" name="opcoes_ids[]" value="">
                                <input type="text" name="opcoes_nomes[]" class="form-control" placeholder="Nome (Ex: Time B)" required>
                                <input type="number" step="0.01" name="opcoes_odds[]" class="form-control text-center" placeholder="Odd" style="max-width: 90px;" required>
                            </div>
                        </div>

                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mb-3 dashed-border" onclick="addCampo()">
                            <i class="bi bi-plus-lg me-1"></i>Adicionar Opção
                        </button>
                        
                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-success fw-bold text-dark py-2" id="btnSubmit">
                                <i class="bi bi-check-lg me-2"></i>Publicar Aposta
                            </button>
                            <button type="button" class="btn btn-outline-danger d-none" id="btnCancelarEdit" onclick="cancelarEdicao()">
                                <i class="bi bi-x-lg me-2"></i>Cancelar Edição
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- LISTAGEM (Direita) -->
        <div class="col-lg-8">
            <ul class="nav nav-pills justify-content-center">
                <li class="nav-item me-2"><a class="nav-link <?= $filtro_status == 'aberta' ? 'active' : '' ?>" href="?status=aberta"><i class="bi bi-unlock me-2"></i>Abertas</a></li>
                <li class="nav-item"><a class="nav-link <?= $filtro_status == 'encerrada' ? 'active' : '' ?>" href="?status=encerrada"><i class="bi bi-lock me-2"></i>Encerradas</a></li>
            </ul>

            <?php if(count($eventos) == 0): ?>
                <div class="alert alert-dark border-secondary text-center text-muted p-5 mt-4 rounded-4">
                    <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                    <h5>Nada por aqui!</h5>
                    <p>Nenhuma aposta encontrada nesta categoria.</p>
                </div>
            <?php endif; ?>

            <?php foreach($eventos as $evt): ?>
                <div class="card card-dark mb-4 shadow-sm">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h4 class="card-title fw-bold text-white mb-1"><?= htmlspecialchars($evt['nome']) ?></h4>
                                <span class="text-secondary small">
                                    <i class="bi bi-clock me-1 text-warning"></i>Limite: <?= date('d/m/Y H:i', strtotime($evt['data_limite'])) ?>
                                </span>
                            </div>
                            
                            <!-- Botões de Ação -->
                             <div class="btn-group shadow-sm">
                                <!-- Botão Editar -->
                                <button class="btn btn-outline-warning btn-sm" title="Editar Aposta" 
                                        onclick='prepararEdicao(<?= json_encode($evt) ?>)'>
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                
                                <!-- Botão BALANCEAR ODDS -->
                                <?php if($evt['status'] == 'aberta'): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Recalcular odds com base no volume de apostas?');">
                                        <input type="hidden" name="acao" value="recalcular_odds">
                                        <input type="hidden" name="id_evento" value="<?= $evt['id'] ?>">
                                        <button type="submit" class="btn btn-outline-info btn-sm" title="Balancear Odds">
                                            <i class="bi bi-calculator"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <span class="btn btn-outline-secondary btn-sm disabled bg-dark text-light border-secondary">
                                    <?= $evt['total_apostas_evento'] ?> <i class="bi bi-people-fill ms-1"></i>
                                </span>
                             </div>
                        </div>

                        <!-- Lista de Opções -->
                        <div class="d-flex flex-wrap gap-2 mb-4">
                            <?php foreach($evt['opcoes'] as $op): ?>
                                <?php 
                                    $classe = "";
                                    if($evt['status'] == 'encerrada' && $evt['vencedor_opcao_id'] == $op['id']) {
                                        $classe = "badge-winner";
                                    }
                                    $volume = $op['total_valor'] ?? 0;
                                ?>
                                <div class="badge badge-odd <?= $classe ?> d-flex flex-column align-items-start text-start" style="min-width: 130px;">
                                    <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                                        <span class="fw-normal"><?= htmlspecialchars($op['descricao']) ?></span>
                                        <?php if($evt['status'] == 'encerrada' && $evt['vencedor_opcao_id'] == $op['id']): ?>
                                            <i class="bi bi-check-circle-fill text-success"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex w-100 justify-content-between align-items-center">
                                        <strong class="fs-5 text-warning"><?= number_format($op['odd'], 2) ?></strong>
                                        <?php if($volume > 0): ?>
                                            <small class="text-secondary" style="font-size: 0.7em;">$<?= number_format($volume, 0, ',', '.') ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Botão Encerrar -->
                        <?php if($evt['status'] == 'aberta'): ?>
                            <div class="bg-dark bg-opacity-50 p-3 rounded border border-secondary">
                                <form method="POST" class="d-flex gap-2 align-items-center" onsubmit="return confirm('Tem certeza? Isso vai pagar os usuários.');">
                                    <input type="hidden" name="acao" value="encerrar_evento">
                                    <input type="hidden" name="id_evento" value="<?= $evt['id'] ?>">
                                    
                                    <div class="flex-grow-1">
                                        <select name="vencedor_opcao_id" class="form-select form-select-sm bg-dark text-white border-secondary" required>
                                            <option value="">Selecione o Resultado Oficial...</option>
                                            <?php foreach($evt['opcoes'] as $op): ?>
                                                <option value="<?= $op['id'] ?>"><?= $op['descricao'] ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn btn-danger btn-sm fw-bold px-3">
                                        <i class="bi bi-flag-fill me-1"></i>Encerrar
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function addCampo(id = '', nome = '', odd = '') {
    const div = document.createElement('div');
    div.className = 'input-group mb-2';
    div.innerHTML = `
        <input type="hidden" name="opcoes_ids[]" value="${id}">
        <input type="text" name="opcoes_nomes[]" class="form-control" value="${nome}" placeholder="Nome da Opção" required>
        <input type="number" step="0.01" name="opcoes_odds[]" class="form-control text-center" value="${odd}" placeholder="Odd" style="max-width: 90px;" required>
        <button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove()"><i class="bi bi-x-lg"></i></button>
    `;
    document.getElementById('container-opcoes').appendChild(div);
}

function prepararEdicao(evento) {
    document.getElementById('formTitle').innerHTML = "<i class='bi bi-pencil-square me-2'></i>Editando: " + evento.nome;
    document.getElementById('formTitle').className = "card-header card-header-edit fw-bold";
    document.getElementById('btnSubmit').className = "btn btn-light fw-bold w-100 text-dark";
    document.getElementById('btnSubmit').innerText = "Salvar Alterações";
    document.getElementById('btnCancelarEdit').classList.remove('d-none'); 

    document.getElementById('acaoInput').value = 'editar_evento';
    document.getElementById('idEventoInput').value = evento.id;
    document.getElementById('nomeEventoInput').value = evento.nome;
    
    // Converte MySQL datetime (YYYY-MM-DD HH:MM:SS) para HTML datetime-local (YYYY-MM-DDTHH:MM)
    let dataFormatada = evento.data_limite.replace(' ', 'T').substring(0, 16);
    document.getElementById('dataLimiteInput').value = dataFormatada;

    const container = document.getElementById('container-opcoes');
    container.innerHTML = ''; 

    evento.opcoes.forEach(op => {
        addCampo(op.id, op.descricao, op.odd);
    });

    document.getElementById('cardFormulario').scrollIntoView({ behavior: 'smooth' });
}

function cancelarEdicao() {
    document.getElementById('formTitle').innerHTML = "<i class='bi bi-plus-circle me-2'></i>Criar Nova Aposta";
    document.getElementById('formTitle').className = "card-header card-header-admin fw-bold";
    document.getElementById('btnSubmit').className = "btn btn-success fw-bold text-dark";
    document.getElementById('btnSubmit').innerHTML = "<i class='bi bi-check-lg me-2'></i>Publicar Aposta";
    document.getElementById('btnCancelarEdit').classList.add('d-none');

    document.getElementById('mainForm').reset();
    document.getElementById('acaoInput').value = 'criar_evento';
    document.getElementById('idEventoInput').value = '';

    const container = document.getElementById('container-opcoes');
    container.innerHTML = '';
    addCampo();
    addCampo();
}
</script>
</body>
</html>
