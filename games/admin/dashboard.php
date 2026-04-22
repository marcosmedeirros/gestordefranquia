<?php
// admin/dashboard.php - GERENCIADOR DE APOSTAS
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require '../core/conexao.php';
require '../core/funcoes.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

$stmt = $pdo->prepare("SELECT is_admin, nome, pontos, fba_points FROM usuarios WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['is_admin'] != 1) {
    die("Acesso negado: Área restrita a administradores.");
}

$mensagem = "";
$mensagemType = "success";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    if (isset($_POST['acao']) && $_POST['acao'] == 'criar_evento') {
        $nome_evento = trim($_POST['nome_evento']);
        $data_limite = $_POST['data_limite'];
        $opcoes_nomes = $_POST['opcoes_nomes'];

        if (empty($nome_evento) || empty($data_limite) || count(array_filter($opcoes_nomes)) < 2) {
            $mensagem = "Preencha todos os campos obrigatórios (mínimo 2 opções).";
            $mensagemType = "warning";
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO eventos (nome, data_limite, status) VALUES (:nome, :data, 'aberta')");
                $stmt->execute([':nome' => $nome_evento, ':data' => $data_limite]);
                $evento_id = $pdo->lastInsertId();

                $stmtOpcao = $pdo->prepare("INSERT INTO opcoes (evento_id, descricao, odd) VALUES (:eid, :desc, 1)");
                for ($i = 0; $i < count($opcoes_nomes); $i++) {
                    if (!empty($opcoes_nomes[$i])) {
                        $stmtOpcao->execute([':eid' => $evento_id, ':desc' => $opcoes_nomes[$i]]);
                    }
                }
                $pdo->commit();
                $mensagem = "Aposta criada com sucesso!";
            } catch (Exception $e) {
                $pdo->rollBack();
                $mensagem = "Erro: " . $e->getMessage();
                $mensagemType = "danger";
            }
        }
    }

    if (isset($_POST['acao']) && $_POST['acao'] == 'editar_evento') {
        $id_evento   = $_POST['id_evento'];
        $nome_evento = trim($_POST['nome_evento']);
        $data_limite = $_POST['data_limite'];
        $op_ids   = $_POST['opcoes_ids'] ?? [];
        $op_nomes = $_POST['opcoes_nomes'];
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE eventos SET nome = :nome, data_limite = :data WHERE id = :id");
            $stmt->execute([':nome' => $nome_evento, ':data' => $data_limite, ':id' => $id_evento]);

            $stmtUpd = $pdo->prepare("UPDATE opcoes SET descricao = :desc WHERE id = :oid AND evento_id = :eid");
            $stmtIns = $pdo->prepare("INSERT INTO opcoes (evento_id, descricao, odd) VALUES (:eid, :desc, 1)");

            for ($i = 0; $i < count($op_nomes); $i++) {
                $nome = trim($op_nomes[$i]);
                $oid  = $op_ids[$i] ?? '';
                if (!empty($nome)) {
                    if (!empty($oid)) {
                        $stmtUpd->execute([':desc' => $nome, ':oid' => $oid, ':eid' => $id_evento]);
                    } else {
                        $stmtIns->execute([':eid' => $id_evento, ':desc' => $nome]);
                    }
                }
            }
            $pdo->commit();
            $mensagem = "Alterações salvas com sucesso!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $mensagem = "Erro ao editar: " . $e->getMessage();
            $mensagemType = "danger";
        }
    }

    if (isset($_POST['acao']) && $_POST['acao'] == 'encerrar_evento') {
        $id_evento = $_POST['id_evento'];
        $vencedor_opcao_id = $_POST['vencedor_opcao_id'];

        if (empty($vencedor_opcao_id)) {
            $mensagem = "Selecione quem ganhou!";
            $mensagemType = "warning";
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE eventos SET status = 'encerrada', vencedor_opcao_id = ? WHERE id = ?")
                    ->execute([$vencedor_opcao_id, $id_evento]);

                $payStmt = $pdo->prepare("
                    UPDATE usuarios u
                    JOIN (SELECT DISTINCT id_usuario FROM palpites WHERE opcao_id = ?) p ON p.id_usuario = u.id
                    SET u.fba_points = u.fba_points + 75, u.acertos_eventos = u.acertos_eventos + 1
                ");
                $payStmt->execute([$vencedor_opcao_id]);
                $pagos = $payStmt->rowCount();
                $pdo->commit();
                $mensagem = "Encerrado! $pagos apostas pagas (75 FBA Points por acerto).";
            } catch (Exception $e) {
                $pdo->rollBack();
                $mensagem = "Erro: " . $e->getMessage();
                $mensagemType = "danger";
            }
        }
    }

    if (isset($_POST['acao']) && $_POST['acao'] == 'alterar_vencedor') {
        $id_evento = $_POST['id_evento'];
        $novo_vencedor_opcao_id = $_POST['vencedor_opcao_id'];

        if (empty($novo_vencedor_opcao_id)) {
            $mensagem = "Selecione o novo vencedor!";
            $mensagemType = "warning";
        } else {
            try {
                $pdo->beginTransaction();
                $stmtEvento = $pdo->prepare("SELECT status, vencedor_opcao_id FROM eventos WHERE id = ? FOR UPDATE");
                $stmtEvento->execute([$id_evento]);
                $eventoAtual = $stmtEvento->fetch(PDO::FETCH_ASSOC);

                if (!$eventoAtual) throw new Exception('Evento não encontrado.');
                if ($eventoAtual['status'] != 'encerrada') throw new Exception('Este evento ainda não está encerrado.');

                $vencedor_antigo = $eventoAtual['vencedor_opcao_id'];

                if ((int)$vencedor_antigo === (int)$novo_vencedor_opcao_id) {
                    $pdo->commit();
                    $mensagem = "Nenhuma alteração: vencedor já estava correto.";
                    $mensagemType = "info";
                } else {
                    $revertidos = 0;
                    if (!empty($vencedor_antigo)) {
                        $stmtRevert = $pdo->prepare("
                            UPDATE usuarios u
                            JOIN (SELECT DISTINCT id_usuario FROM palpites WHERE opcao_id = ?) p ON p.id_usuario = u.id
                            SET u.fba_points = u.fba_points - 75, u.acertos_eventos = GREATEST(u.acertos_eventos - 1, 0)
                        ");
                        $stmtRevert->execute([$vencedor_antigo]);
                        $revertidos = $stmtRevert->rowCount();
                    }
                    $stmtPay = $pdo->prepare("
                        UPDATE usuarios u
                        JOIN (SELECT DISTINCT id_usuario FROM palpites WHERE opcao_id = ?) p ON p.id_usuario = u.id
                        SET u.fba_points = u.fba_points + 75, u.acertos_eventos = u.acertos_eventos + 1
                    ");
                    $stmtPay->execute([$novo_vencedor_opcao_id]);
                    $pagos = $stmtPay->rowCount();

                    $pdo->prepare("UPDATE eventos SET vencedor_opcao_id = ? WHERE id = ?")->execute([$novo_vencedor_opcao_id, $id_evento]);
                    $pdo->commit();
                    $mensagem = "Vencedor alterado! $revertidos revertidos, $pagos pagos (75 FBA Points).";
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $mensagem = "Erro: " . $e->getMessage();
                $mensagemType = "danger";
            }
        }
    }
}

$filtro_status = isset($_GET['status']) && $_GET['status'] == 'encerrada' ? 'encerrada' : 'aberta';

$stmtEventos = $pdo->prepare("SELECT * FROM eventos WHERE status = ? ORDER BY data_limite ASC");
$stmtEventos->execute([$filtro_status]);
$eventos = $stmtEventos->fetchAll(PDO::FETCH_ASSOC);

foreach ($eventos as $key => $evt) {
    $stmtOpcoes = $pdo->prepare("SELECT o.*, (SELECT COUNT(*) FROM palpites p WHERE p.opcao_id = o.id) as total_palpites FROM opcoes o WHERE o.evento_id = ?");
    $stmtOpcoes->execute([$evt['id']]);
    $eventos[$key]['opcoes'] = $stmtOpcoes->fetchAll(PDO::FETCH_ASSOC);
    $total_evento = 0;
    foreach ($eventos[$key]['opcoes'] as $op) {
        $total_evento += $op['total_palpites'];
    }
    $eventos[$key]['total_apostas_evento'] = $total_evento;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#fc0025">
  <title>Admin Apostas - FBA Games</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root {
      --red:        #fc0025;
      --red-soft:   rgba(252,0,37,.10);
      --red-glow:   rgba(252,0,37,.18);
      --bg:         #07070a;
      --panel:      #101013;
      --panel-2:    #16161a;
      --panel-3:    #1c1c21;
      --border:     rgba(255,255,255,.06);
      --border-md:  rgba(255,255,255,.10);
      --border-red: rgba(252,0,37,.22);
      --text:       #f0f0f3;
      --text-2:     #868690;
      --text-3:     #48484f;
      --amber:      #f59e0b;
      --green:      #22c55e;
      --blue:       #3b82f6;
      --font:       'Poppins', sans-serif;
      --radius:     14px;
      --radius-sm:  10px;
      --ease:       cubic-bezier(.2,.8,.2,1);
      --t:          200ms;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }

    /* Topbar */
    .topbar {
      position: sticky; top: 0; z-index: 300; height: 58px;
      background: var(--panel); border-bottom: 1px solid var(--border);
      display: flex; align-items: center; padding: 0 24px; gap: 16px;
    }
    .topbar-brand { display: flex; align-items: center; gap: 10px; text-decoration: none; flex-shrink: 0; }
    .topbar-logo {
      width: 32px; height: 32px; border-radius: 8px; background: var(--red);
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 12px; color: #fff;
    }
    .topbar-name { font-weight: 800; font-size: 15px; color: var(--text); }
    .topbar-name span { color: var(--red); }
    .topbar-spacer { flex: 1; }
    .topbar-balances { display: flex; align-items: center; gap: 8px; }
    .balance-chip {
      display: flex; align-items: center; gap: 6px;
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: 999px; padding: 5px 12px;
      font-size: 12px; font-weight: 700; color: var(--text);
    }
    .balance-chip i { color: var(--red); font-size: 13px; }
    .balance-chip.fba i { color: var(--amber); }
    .icon-btn {
      width: 32px; height: 32px; border-radius: 8px;
      background: transparent; border: 1px solid var(--border);
      color: var(--text-2); display: flex; align-items: center; justify-content: center;
      font-size: 14px; cursor: pointer; text-decoration: none;
      transition: all var(--t) var(--ease);
    }
    .icon-btn:hover { background: var(--red-soft); border-color: var(--red); color: var(--red); }

    .main { max-width: 1200px; margin: 0 auto; padding: 28px 20px 60px; }

    .section-label {
      display: flex; align-items: center; gap: 8px;
      font-size: 10px; font-weight: 700; letter-spacing: 1.2px;
      text-transform: uppercase; color: var(--text-3);
      margin-bottom: 16px; margin-top: 28px;
    }
    .section-label i { color: var(--red); font-size: 13px; }

    .fba-alert {
      display: flex; align-items: center; gap: 10px;
      padding: 12px 16px; border-radius: var(--radius-sm);
      font-size: 13px; font-weight: 500; margin-bottom: 20px;
    }
    .fba-alert.success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.2); color: #4ade80; }
    .fba-alert.danger  { background: rgba(252,0,37,.1);  border: 1px solid var(--border-red); color: #ff6680; }
    .fba-alert.warning { background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.2); color: #fbbf24; }
    .fba-alert.info    { background: rgba(59,130,246,.1);  border: 1px solid rgba(59,130,246,.2); color: #60a5fa; }

    .two-col { display: grid; grid-template-columns: 340px 1fr; gap: 20px; align-items: start; }

    /* Form panel */
    .panel-card {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius); overflow: hidden;
      position: sticky; top: 74px;
    }
    .panel-card.editing { border-color: var(--border-red); }
    .panel-head {
      padding: 14px 18px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; gap: 8px;
      font-size: 13px; font-weight: 700;
    }
    .panel-head i { color: var(--red); }
    .panel-head.editing-head { border-bottom-color: var(--border-red); }
    .panel-head.editing-head i { color: var(--amber); }
    .panel-body { padding: 18px; }

    .fba-label {
      font-size: 10px; font-weight: 700; text-transform: uppercase;
      letter-spacing: .6px; color: var(--text-2); display: block; margin-bottom: 6px;
    }
    .fba-input {
      width: 100%; background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: var(--radius-sm); padding: 9px 12px;
      color: var(--text); font-family: var(--font); font-size: 13px;
      outline: none; transition: border-color var(--t) var(--ease);
      margin-bottom: 14px;
    }
    .fba-input:focus { border-color: var(--red); }
    .fba-input::placeholder { color: var(--text-3); }

    .opcao-group { display: flex; gap: 8px; margin-bottom: 8px; align-items: center; }
    .opcao-group .fba-input { margin-bottom: 0; flex: 1; }
    .btn-icon-remove {
      width: 32px; height: 32px; flex-shrink: 0; border-radius: 8px;
      background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.2);
      color: #f87171; font-size: 14px; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      transition: all var(--t) var(--ease);
    }
    .btn-icon-remove:hover { background: rgba(239,68,68,.22); }

    .btn-add-opcao {
      width: 100%; background: transparent; border: 1px dashed var(--border-md);
      border-radius: var(--radius-sm); padding: 8px;
      color: var(--text-2); font-family: var(--font); font-size: 12px;
      font-weight: 600; cursor: pointer; margin-bottom: 16px;
      transition: all var(--t) var(--ease); display: flex; align-items: center;
      justify-content: center; gap: 6px;
    }
    .btn-add-opcao:hover { border-color: var(--red); color: var(--red); }

    .btn-primary-full {
      width: 100%; background: var(--red); color: #fff; border: none;
      border-radius: var(--radius-sm); padding: 11px;
      font-family: var(--font); font-size: 13px; font-weight: 700;
      cursor: pointer; transition: opacity var(--t) var(--ease);
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-primary-full:hover { opacity: .85; }
    .btn-ghost-full {
      width: 100%; background: transparent; border: 1px solid var(--border-md);
      border-radius: var(--radius-sm); padding: 10px;
      font-family: var(--font); font-size: 13px; font-weight: 600;
      color: var(--text-2); cursor: pointer; margin-top: 8px;
      transition: all var(--t) var(--ease); display: none;
    }
    .btn-ghost-full:hover { border-color: var(--text-2); color: var(--text); }
    .btn-ghost-full.visible { display: flex; align-items: center; justify-content: center; gap: 8px; }

    /* Status tabs */
    .tab-bar {
      display: flex; align-items: center; gap: 4px;
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: 999px; padding: 4px; width: fit-content; margin-bottom: 20px;
    }
    .tab-btn {
      padding: 7px 18px; border-radius: 999px; border: none;
      background: transparent; color: var(--text-2);
      font-family: var(--font); font-size: 13px; font-weight: 600;
      cursor: pointer; text-decoration: none;
      transition: all var(--t) var(--ease);
    }
    .tab-btn.active { background: var(--red); color: #fff; box-shadow: 0 2px 12px rgba(252,0,37,.35); }

    /* Event cards */
    .evt-card {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius); margin-bottom: 12px; overflow: hidden;
      transition: border-color var(--t) var(--ease);
    }
    .evt-card:hover { border-color: var(--border-md); }
    .evt-card-head {
      padding: 16px 18px; border-bottom: 1px solid var(--border);
      display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
    }
    .evt-title { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
    .evt-meta { font-size: 11px; color: var(--text-3); display: flex; align-items: center; gap: 6px; }
    .evt-meta i { color: var(--amber); }
    .evt-actions { display: flex; gap: 6px; flex-shrink: 0; }
    .btn-edit-evt {
      background: rgba(245,158,11,.12); border: 1px solid rgba(245,158,11,.2);
      border-radius: 8px; width: 30px; height: 30px; color: #fbbf24;
      display: flex; align-items: center; justify-content: center; font-size: 13px;
      cursor: pointer; transition: all var(--t) var(--ease);
    }
    .btn-edit-evt:hover { background: rgba(245,158,11,.22); }
    .bets-count {
      background: var(--panel-3); border: 1px solid var(--border);
      border-radius: 8px; padding: 4px 10px; font-size: 11px; font-weight: 700;
      color: var(--text-2); display: flex; align-items: center; gap: 4px;
    }

    .evt-options { padding: 14px 18px; display: flex; flex-wrap: wrap; gap: 8px; border-bottom: 1px solid var(--border); }
    .opt-pill {
      background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: 10px; padding: 8px 14px;
      font-size: 12px; font-weight: 600; color: var(--text-2);
      display: flex; flex-direction: column; gap: 3px; min-width: 110px;
    }
    .opt-pill.winner { background: rgba(252,0,37,.08); border-color: var(--border-red); color: var(--text); }
    .opt-pill-name { display: flex; align-items: center; gap: 6px; }
    .opt-pill-name .check { color: var(--green); font-size: 12px; }
    .opt-pill-count { font-size: 10px; color: var(--text-3); }

    .evt-footer { padding: 12px 18px; }
    .close-form { display: flex; gap: 8px; align-items: center; }
    .fba-select-sm {
      flex: 1; background: var(--panel-2); border: 1px solid var(--border-md);
      border-radius: var(--radius-sm); padding: 8px 12px;
      color: var(--text); font-family: var(--font); font-size: 12px; outline: none;
    }
    .btn-close-evt {
      background: var(--red); color: #fff; border: none;
      border-radius: var(--radius-sm); padding: 8px 16px;
      font-family: var(--font); font-size: 12px; font-weight: 700;
      cursor: pointer; white-space: nowrap; flex-shrink: 0;
      transition: opacity var(--t) var(--ease);
    }
    .btn-close-evt:hover { opacity: .85; }
    .btn-alter-evt {
      background: rgba(245,158,11,.12); color: #fbbf24;
      border: 1px solid rgba(245,158,11,.2);
      border-radius: var(--radius-sm); padding: 8px 16px;
      font-family: var(--font); font-size: 12px; font-weight: 700;
      cursor: pointer; white-space: nowrap; flex-shrink: 0;
      transition: all var(--t) var(--ease);
    }
    .btn-alter-evt:hover { background: rgba(245,158,11,.22); }

    .empty-state {
      text-align: center; padding: 56px 20px; color: var(--text-3);
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius);
    }
    .empty-state i { font-size: 32px; margin-bottom: 10px; display: block; }
    .empty-state p { font-size: 13px; margin: 0; }

    @media (max-width: 860px) {
      .two-col { grid-template-columns: 1fr; }
      .panel-card { position: static; }
      .topbar-balances { display: none; }
    }
  </style>
</head>
<body>

<div class="topbar">
  <a href="../index.php" class="topbar-brand">
    <div class="topbar-logo">FBA</div>
    <span class="topbar-name">FBA <span>Admin</span></span>
  </a>
  <div class="topbar-spacer"></div>
  <div class="topbar-balances">
    <div class="balance-chip">
      <i class="bi bi-coin"></i>
      <?= number_format($user['pontos'], 0, ',', '.') ?> moedas
    </div>
    <div class="balance-chip fba">
      <i class="bi bi-gem"></i>
      <?= number_format($user['fba_points'] ?? 0, 0, ',', '.') ?> FBA
    </div>
  </div>
  <div style="display:flex;gap:6px">
    <a href="controlegames.php" class="icon-btn" title="Controle de Jogos"><i class="bi bi-toggles"></i></a>
    <a href="../index.php" class="icon-btn" title="Voltar"><i class="bi bi-arrow-left"></i></a>
  </div>
</div>

<div class="main">

  <?php if ($mensagem): ?>
  <div class="fba-alert <?= $mensagemType ?>">
    <i class="bi bi-<?= $mensagemType === 'success' ? 'check-circle-fill' : ($mensagemType === 'warning' ? 'exclamation-triangle-fill' : ($mensagemType === 'info' ? 'info-circle-fill' : 'x-circle-fill')) ?>"></i>
    <?= htmlspecialchars($mensagem) ?>
  </div>
  <?php endif; ?>

  <div class="two-col">

    <!-- Formulário -->
    <div>
      <div class="section-label" style="margin-top:0"><i class="bi bi-plus-circle-fill"></i>Gerenciar Apostas</div>
      <div class="panel-card" id="cardFormulario">
        <div class="panel-head" id="formTitle">
          <i class="bi bi-plus-circle-fill"></i>
          Criar Nova Aposta
        </div>
        <div class="panel-body">
          <form method="POST" id="mainForm">
            <input type="hidden" name="acao" id="acaoInput" value="criar_evento">
            <input type="hidden" name="id_evento" id="idEventoInput">

            <label class="fba-label">Pergunta / Evento</label>
            <input type="text" name="nome_evento" id="nomeEventoInput" class="fba-input"
                   placeholder="Ex: Quem ganha o jogo?" required>

            <label class="fba-label">Data Limite</label>
            <input type="datetime-local" name="data_limite" id="dataLimiteInput" class="fba-input" required>

            <label class="fba-label" style="margin-top:4px">Opções de Aposta</label>
            <div id="container-opcoes">
              <div class="opcao-group">
                <input type="hidden" name="opcoes_ids[]" value="">
                <input type="text" name="opcoes_nomes[]" class="fba-input" placeholder="Opção A (Ex: Time A)" required>
              </div>
              <div class="opcao-group">
                <input type="hidden" name="opcoes_ids[]" value="">
                <input type="text" name="opcoes_nomes[]" class="fba-input" placeholder="Opção B (Ex: Time B)" required>
              </div>
            </div>
            <button type="button" class="btn-add-opcao" onclick="addCampo()">
              <i class="bi bi-plus-lg"></i> Adicionar Opção
            </button>

            <button type="submit" class="btn-primary-full" id="btnSubmit">
              <i class="bi bi-check-lg"></i> Publicar Aposta
            </button>
            <button type="button" class="btn-ghost-full" id="btnCancelarEdit" onclick="cancelarEdicao()">
              <i class="bi bi-x-lg"></i> Cancelar Edição
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- Listagem -->
    <div>
      <div class="section-label" style="margin-top:0"><i class="bi bi-list-ul"></i>Apostas</div>

      <div class="tab-bar">
        <a href="?status=aberta" class="tab-btn <?= $filtro_status == 'aberta' ? 'active' : '' ?>">
          <i class="bi bi-unlock me-1"></i>Abertas
        </a>
        <a href="?status=encerrada" class="tab-btn <?= $filtro_status == 'encerrada' ? 'active' : '' ?>">
          <i class="bi bi-lock me-1"></i>Encerradas
        </a>
      </div>

      <?php if (empty($eventos)): ?>
      <div class="empty-state">
        <i class="bi bi-inbox"></i>
        <p>Nenhuma aposta nesta categoria.</p>
      </div>
      <?php endif; ?>

      <?php foreach ($eventos as $evt): ?>
      <div class="evt-card">
        <div class="evt-card-head">
          <div>
            <div class="evt-title"><?= htmlspecialchars($evt['nome']) ?></div>
            <div class="evt-meta">
              <i class="bi bi-clock"></i>
              Limite: <?= date('d/m/Y H:i', strtotime($evt['data_limite'])) ?>
            </div>
          </div>
          <div class="evt-actions">
            <button class="btn-edit-evt" title="Editar"
                    onclick='prepararEdicao(<?= json_encode($evt) ?>)'>
              <i class="bi bi-pencil-square"></i>
            </button>
            <?php if ($evt['status'] == 'aberta'): ?>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('Recalcular odds?')">
              <input type="hidden" name="acao" value="recalcular_odds">
              <input type="hidden" name="id_evento" value="<?= $evt['id'] ?>">
              <button type="submit" class="btn-edit-evt" title="Recalcular odds" style="color:#60a5fa;background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.2)">
                <i class="bi bi-calculator"></i>
              </button>
            </form>
            <?php endif; ?>
            <div class="bets-count">
              <i class="bi bi-people-fill"></i> <?= $evt['total_apostas_evento'] ?>
            </div>
          </div>
        </div>

        <div class="evt-options">
          <?php foreach ($evt['opcoes'] as $op):
            $isWinner = $evt['status'] == 'encerrada' && $evt['vencedor_opcao_id'] == $op['id'];
          ?>
          <div class="opt-pill <?= $isWinner ? 'winner' : '' ?>">
            <div class="opt-pill-name">
              <?php if ($isWinner): ?><i class="bi bi-check-circle-fill check"></i><?php endif; ?>
              <?= htmlspecialchars($op['descricao']) ?>
            </div>
            <div class="opt-pill-count"><i class="bi bi-people-fill"></i> <?= (int)$op['total_palpites'] ?> apostas</div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="evt-footer">
          <?php if ($evt['status'] == 'aberta'): ?>
          <form method="POST" class="close-form"
                onsubmit="return confirm('Encerrar? Isso vai pagar os usuários.')">
            <input type="hidden" name="acao" value="encerrar_evento">
            <input type="hidden" name="id_evento" value="<?= $evt['id'] ?>">
            <select name="vencedor_opcao_id" class="fba-select-sm" required>
              <option value="">Selecione o resultado oficial...</option>
              <?php foreach ($evt['opcoes'] as $op): ?>
              <option value="<?= $op['id'] ?>"><?= htmlspecialchars($op['descricao']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-close-evt">
              <i class="bi bi-flag-fill me-1"></i>Encerrar
            </button>
          </form>
          <?php else: ?>
          <form method="POST" class="close-form"
                onsubmit="return confirm('Alterar vencedor irá corrigir pontos já pagos. Continuar?')">
            <input type="hidden" name="acao" value="alterar_vencedor">
            <input type="hidden" name="id_evento" value="<?= $evt['id'] ?>">
            <select name="vencedor_opcao_id" class="fba-select-sm" required>
              <option value="">Alterar vencedor...</option>
              <?php foreach ($evt['opcoes'] as $op): ?>
              <option value="<?= $op['id'] ?>" <?= ($evt['vencedor_opcao_id'] == $op['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($op['descricao']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-alter-evt">
              <i class="bi bi-arrow-repeat me-1"></i>Alterar
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

  </div>
</div>

<script>
function addCampo(id = '', nome = '') {
  const div = document.createElement('div');
  div.className = 'opcao-group';
  div.innerHTML = `
    <input type="hidden" name="opcoes_ids[]" value="${id}">
    <input type="text" name="opcoes_nomes[]" class="fba-input" value="${nome}" placeholder="Nome da opção" required>
    <button type="button" class="btn-icon-remove" onclick="this.parentElement.remove()">
      <i class="bi bi-x-lg"></i>
    </button>
  `;
  document.getElementById('container-opcoes').appendChild(div);
}

function prepararEdicao(evento) {
  const card = document.getElementById('cardFormulario');
  const head = document.getElementById('formTitle');
  card.classList.add('editing');
  head.classList.add('editing-head');
  head.innerHTML = `<i class="bi bi-pencil-square"></i> Editando: ${evento.nome}`;

  document.getElementById('btnSubmit').innerHTML = '<i class="bi bi-save-fill"></i> Salvar Alterações';
  document.getElementById('btnCancelarEdit').classList.add('visible');
  document.getElementById('acaoInput').value = 'editar_evento';
  document.getElementById('idEventoInput').value = evento.id;
  document.getElementById('nomeEventoInput').value = evento.nome;
  document.getElementById('dataLimiteInput').value = evento.data_limite.replace(' ', 'T').substring(0, 16);

  const container = document.getElementById('container-opcoes');
  container.innerHTML = '';
  evento.opcoes.forEach(op => addCampo(op.id, op.descricao));

  document.getElementById('cardFormulario').scrollIntoView({ behavior: 'smooth' });
}

function cancelarEdicao() {
  const card = document.getElementById('cardFormulario');
  const head = document.getElementById('formTitle');
  card.classList.remove('editing');
  head.classList.remove('editing-head');
  head.innerHTML = '<i class="bi bi-plus-circle-fill"></i> Criar Nova Aposta';

  document.getElementById('btnSubmit').innerHTML = '<i class="bi bi-check-lg"></i> Publicar Aposta';
  document.getElementById('btnCancelarEdit').classList.remove('visible');
  document.getElementById('mainForm').reset();
  document.getElementById('acaoInput').value = 'criar_evento';
  document.getElementById('idEventoInput').value = '';

  const container = document.getElementById('container-opcoes');
  container.innerHTML = '';
  addCampo('', ''); addCampo('', '');
}
</script>
</body>
</html>
