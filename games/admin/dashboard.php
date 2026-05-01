<?php
session_start();
require '../core/conexao.php';
require '../core/funcoes.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

$stmt = $pdo->prepare("SELECT is_admin, nome, pontos, fba_points, COALESCE(numero_tapas, 0) as numero_tapas FROM usuarios WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['is_admin'] != 1) {
    die("Acesso negado: Área restrita a administradores.");
}

$mensagem = "";
$mensagemType = "success";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    if (isset($_POST['acao']) && $_POST['acao'] == 'criar_evento') {
        $nome_evento  = trim($_POST['nome_evento']);
        $data_limite  = $_POST['data_limite'];
        $opcoes_nomes = $_POST['opcoes_nomes'];

        if (empty($nome_evento) || empty($data_limite) || count(array_filter($opcoes_nomes)) < 2) {
            $mensagem = "Preencha todos os campos (mínimo 2 opções).";
            $mensagemType = "warning";
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO eventos (nome, data_limite, status) VALUES (:nome, :data, 'aberta')");
                $stmt->execute([':nome' => $nome_evento, ':data' => $data_limite]);
                $evento_id = $pdo->lastInsertId();
                $stmtOpcao = $pdo->prepare("INSERT INTO opcoes (evento_id, descricao, odd) VALUES (:eid, :desc, 1)");
                foreach ($opcoes_nomes as $op) {
                    if (!empty(trim($op))) $stmtOpcao->execute([':eid' => $evento_id, ':desc' => trim($op)]);
                }
                $pdo->commit();
                $mensagem = "Aposta publicada com sucesso!";
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
        $op_ids      = $_POST['opcoes_ids'] ?? [];
        $op_nomes    = $_POST['opcoes_nomes'];
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE eventos SET nome=:nome, data_limite=:data WHERE id=:id")
                ->execute([':nome'=>$nome_evento,':data'=>$data_limite,':id'=>$id_evento]);
            $stmtUpd = $pdo->prepare("UPDATE opcoes SET descricao=:desc WHERE id=:oid AND evento_id=:eid");
            $stmtIns = $pdo->prepare("INSERT INTO opcoes (evento_id,descricao,odd) VALUES (:eid,:desc,1)");
            for ($i = 0; $i < count($op_nomes); $i++) {
                $nome = trim($op_nomes[$i]);
                $oid  = $op_ids[$i] ?? '';
                if (!empty($nome)) {
                    if (!empty($oid)) $stmtUpd->execute([':desc'=>$nome,':oid'=>$oid,':eid'=>$id_evento]);
                    else              $stmtIns->execute([':eid'=>$id_evento,':desc'=>$nome]);
                }
            }
            $pdo->commit();
            $mensagem = "Aposta editada com sucesso!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $mensagem = "Erro: " . $e->getMessage();
            $mensagemType = "danger";
        }
    }

    if (isset($_POST['acao']) && $_POST['acao'] == 'encerrar_evento') {
        $id_evento         = $_POST['id_evento'];
        $vencedor_opcao_id = $_POST['vencedor_opcao_id'];
        if (empty($vencedor_opcao_id)) {
            $mensagem = "Selecione o resultado!";
            $mensagemType = "warning";
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE eventos SET status='encerrada', vencedor_opcao_id=? WHERE id=?")
                    ->execute([$vencedor_opcao_id, $id_evento]);
                $payStmt = $pdo->prepare("
                    UPDATE usuarios u
                    JOIN (SELECT DISTINCT id_usuario FROM palpites WHERE opcao_id=?) p ON p.id_usuario=u.id
                    SET u.fba_points=u.fba_points+75, u.acertos_eventos=u.acertos_eventos+1
                ");
                $payStmt->execute([$vencedor_opcao_id]);
                $pagos = $payStmt->rowCount();
                $pdo->commit();
                $mensagem = "Encerrado! $pagos apostas pagas (+75 FBA Points cada).";
            } catch (Exception $e) {
                $pdo->rollBack();
                $mensagem = "Erro: " . $e->getMessage();
                $mensagemType = "danger";
            }
        }
    }

    if (isset($_POST['acao']) && $_POST['acao'] == 'alterar_vencedor') {
        $id_evento             = $_POST['id_evento'];
        $novo_vencedor_opcao_id= $_POST['vencedor_opcao_id'];
        if (empty($novo_vencedor_opcao_id)) {
            $mensagem = "Selecione o novo vencedor!";
            $mensagemType = "warning";
        } else {
            try {
                $pdo->beginTransaction();
                $stmtEvento = $pdo->prepare("SELECT status, vencedor_opcao_id FROM eventos WHERE id=? FOR UPDATE");
                $stmtEvento->execute([$id_evento]);
                $eventoAtual = $stmtEvento->fetch(PDO::FETCH_ASSOC);
                if (!$eventoAtual) throw new Exception('Evento não encontrado.');
                if ($eventoAtual['status'] != 'encerrada') throw new Exception('Evento ainda não encerrado.');
                $vencedor_antigo = $eventoAtual['vencedor_opcao_id'];
                if ((int)$vencedor_antigo === (int)$novo_vencedor_opcao_id) {
                    $pdo->commit();
                    $mensagem = "Vencedor já estava correto.";
                    $mensagemType = "info";
                } else {
                    if (!empty($vencedor_antigo)) {
                        $pdo->prepare("
                            UPDATE usuarios u
                            JOIN (SELECT DISTINCT id_usuario FROM palpites WHERE opcao_id=?) p ON p.id_usuario=u.id
                            SET u.fba_points=u.fba_points-75, u.acertos_eventos=GREATEST(u.acertos_eventos-1,0)
                        ")->execute([$vencedor_antigo]);
                    }
                    $pdo->prepare("
                        UPDATE usuarios u
                        JOIN (SELECT DISTINCT id_usuario FROM palpites WHERE opcao_id=?) p ON p.id_usuario=u.id
                        SET u.fba_points=u.fba_points+75, u.acertos_eventos=u.acertos_eventos+1
                    ")->execute([$novo_vencedor_opcao_id]);
                    $pdo->prepare("UPDATE eventos SET vencedor_opcao_id=? WHERE id=?")->execute([$novo_vencedor_opcao_id, $id_evento]);
                    $pdo->commit();
                    $mensagem = "Vencedor corrigido com sucesso.";
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $mensagem = "Erro: " . $e->getMessage();
                $mensagemType = "danger";
            }
        }
    }
}

$filtro_status = (isset($_GET['status']) && $_GET['status'] == 'encerrada') ? 'encerrada' : 'aberta';
$stmtEventos = $pdo->prepare("SELECT * FROM eventos WHERE status=? ORDER BY data_limite ASC");
$stmtEventos->execute([$filtro_status]);
$eventos = $stmtEventos->fetchAll(PDO::FETCH_ASSOC);

foreach ($eventos as $key => $evt) {
    $stmtOpcoes = $pdo->prepare("SELECT o.*, (SELECT COUNT(*) FROM palpites p WHERE p.opcao_id=o.id) as total_palpites FROM opcoes o WHERE o.evento_id=?");
    $stmtOpcoes->execute([$evt['id']]);
    $eventos[$key]['opcoes'] = $stmtOpcoes->fetchAll(PDO::FETCH_ASSOC);
    $total = 0;
    foreach ($eventos[$key]['opcoes'] as $op) $total += $op['total_palpites'];
    $eventos[$key]['total_apostas_evento'] = $total;
}

$totalAbertas   = $pdo->query("SELECT COUNT(*) FROM eventos WHERE status='aberta'")->fetchColumn();
$totalEncerradas= $pdo->query("SELECT COUNT(*) FROM eventos WHERE status='encerrada'")->fetchColumn();
$totalPalpites  = $pdo->query("SELECT COUNT(*) FROM palpites")->fetchColumn();

$acTeams   = $pdo->query("SELECT DISTINCT name FROM teams ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$acPlayers = $pdo->query("SELECT DISTINCT name FROM players ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$acTeamSet = array_flip($acTeams);
$acSuggestions = $acTeams;
foreach ($acPlayers as $n) { if (!isset($acTeamSet[$n])) $acSuggestions[] = $n; }
sort($acSuggestions);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Apostas — FBA Games</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
  --red:#fc0025; --red-soft:rgba(252,0,37,.10); --red-glow:rgba(252,0,37,.18);
  --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
  --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10); --border-red:rgba(252,0,37,.22);
  --text:#f0f0f3; --text-2:#868690; --text-3:#48484f;
  --amber:#f59e0b; --green:#22c55e; --blue:#3b82f6;
  --font:'Poppins',sans-serif; --radius:14px; --radius-sm:10px;
  --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
  --form-w:360px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

/* ── Shell ── */
.shell{display:flex;min-height:100vh}

/* ── Sidebar nav ── */
.sb{width:200px;flex-shrink:0;background:var(--panel);border-right:1px solid var(--border);
    display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:200;overflow-y:auto}
.sb-logo-wrap{padding:16px 14px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.sb-logo{width:28px;height:28px;border-radius:7px;background:var(--red);display:flex;align-items:center;
         justify-content:center;font-weight:800;font-size:11px;color:#fff;flex-shrink:0}
.sb-brand{font-weight:800;font-size:13px;color:var(--text)}
.sb-brand span{color:var(--red)}
.sb-user{padding:12px 14px;border-bottom:1px solid var(--border)}
.sb-avatar{width:36px;height:36px;border-radius:50%;background:var(--red-soft);border:2px solid var(--border-red);
           display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:var(--red);margin-bottom:6px}
.sb-name{font-size:12px;font-weight:700;color:#fff}
.sb-role{font-size:10px;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px}
.sb-nav{flex:1;padding:8px 0}
.sb-section{font-size:9px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:8px 14px 4px}
.sb-link{display:flex;align-items:center;gap:9px;padding:9px 14px;text-decoration:none;
         font-size:12px;font-weight:500;color:var(--text-2);transition:all var(--t) var(--ease);border-left:3px solid transparent}
.sb-link i{width:15px;text-align:center;font-size:13px}
.sb-link:hover{background:var(--panel-2);color:var(--text);border-left-color:var(--border-md)}
.sb-link.active{background:var(--red-soft);color:var(--red);border-left-color:var(--red);font-weight:700}
.sb-footer{padding:10px 14px;border-top:1px solid var(--border)}
.sb-logout{display:flex;align-items:center;gap:8px;width:100%;padding:8px 10px;border-radius:8px;
           border:1px solid var(--border);background:transparent;color:var(--text-2);text-decoration:none;
           font-family:var(--font);font-size:12px;font-weight:600;transition:all var(--t) var(--ease)}
.sb-logout:hover{background:rgba(252,0,37,.1);border-color:var(--border-red);color:var(--red)}

/* ── Page body ── */
.page{margin-left:200px;display:flex;min-height:100vh;flex-direction:column}

/* ── Topbar ── */
.topbar{position:sticky;top:0;z-index:100;height:52px;background:var(--panel);border-bottom:1px solid var(--border);
        display:flex;align-items:center;padding:0 20px;gap:14px}
.topbar-title{font-size:15px;font-weight:800;color:var(--text);flex:1}
.topbar-title span{color:var(--red)}
.chip{display:flex;align-items:center;gap:5px;background:var(--panel-2);border:1px solid var(--border);
      border-radius:999px;padding:4px 11px;font-size:11px;font-weight:700;color:var(--text)}
.chip i{font-size:11px}

/* ── Main layout: fixed form + scrollable list ── */
.body-wrap{display:flex;flex:1;overflow:hidden}

/* Form panel — fixed */
.form-panel{width:var(--form-w);flex-shrink:0;background:var(--panel);border-right:1px solid var(--border);
            position:sticky;top:52px;height:calc(100vh - 52px);overflow-y:auto;display:flex;flex-direction:column}
.form-panel::-webkit-scrollbar{width:4px}
.form-panel::-webkit-scrollbar-track{background:transparent}
.form-panel::-webkit-scrollbar-thumb{background:var(--border-md);border-radius:4px}

.form-head{padding:16px 18px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-shrink:0}
.form-head-icon{width:30px;height:30px;border-radius:8px;background:var(--red-soft);border:1px solid var(--border-red);
                display:flex;align-items:center;justify-content:center;color:var(--red);font-size:14px;flex-shrink:0}
.form-head-title{font-size:13px;font-weight:700;color:var(--text);flex:1;line-height:1.2}
.form-head-sub{font-size:10px;color:var(--text-3);font-weight:400}
.form-body{padding:16px 18px;flex:1}

.f-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-2);display:block;margin-bottom:5px}
.f-input{width:100%;background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);
         padding:9px 12px;color:var(--text);font-family:var(--font);font-size:13px;outline:none;
         transition:border-color var(--t) var(--ease);margin-bottom:13px}
.f-input:focus{border-color:var(--red)}
.f-input::placeholder{color:var(--text-3)}
.f-input.editing{border-color:rgba(245,158,11,.4)}

.opcoes-list{display:flex;flex-direction:column;gap:7px;margin-bottom:10px}
.opcao-row{display:flex;gap:7px;align-items:center}
.opcao-row .f-input{margin-bottom:0;flex:1}
.btn-rm{width:30px;height:30px;flex-shrink:0;border-radius:8px;background:rgba(239,68,68,.1);
        border:1px solid rgba(239,68,68,.2);color:#f87171;font-size:13px;cursor:pointer;
        display:flex;align-items:center;justify-content:center;transition:all var(--t) var(--ease)}
.btn-rm:hover{background:rgba(239,68,68,.2)}

.btn-add{width:100%;background:transparent;border:1px dashed var(--border-md);border-radius:var(--radius-sm);
         padding:8px;color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:600;
         cursor:pointer;margin-bottom:14px;transition:all var(--t) var(--ease);
         display:flex;align-items:center;justify-content:center;gap:6px}
.btn-add:hover{border-color:var(--red);color:var(--red)}

.btn-submit{width:100%;background:var(--red);color:#fff;border:none;border-radius:var(--radius-sm);
            padding:11px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;
            transition:opacity var(--t) var(--ease);display:flex;align-items:center;justify-content:center;gap:8px}
.btn-submit:hover{opacity:.85}
.btn-cancel{width:100%;background:transparent;border:1px solid var(--border-md);border-radius:var(--radius-sm);
            padding:9px;font-family:var(--font);font-size:12px;font-weight:600;color:var(--text-2);
            cursor:pointer;margin-top:8px;transition:all var(--t) var(--ease);
            display:none;align-items:center;justify-content:center;gap:7px}
.btn-cancel.show{display:flex}
.btn-cancel:hover{border-color:var(--text-2);color:var(--text)}

/* ── List panel ── */
.list-panel{flex:1;overflow-y:auto;padding:20px 24px 48px;min-width:0}

/* Stats strip */
.stats-strip{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.stat-chip{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:10px 16px;
           display:flex;align-items:center;gap:10px;flex:1;min-width:100px}
.stat-chip-icon{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.stat-chip-val{font-size:16px;font-weight:800;color:var(--text);line-height:1}
.stat-chip-label{font-size:10px;color:var(--text-3);margin-top:2px}

/* Alert */
.alert-bar{display:flex;align-items:center;gap:9px;padding:11px 15px;border-radius:var(--radius-sm);
           font-size:13px;font-weight:500;margin-bottom:16px}
.alert-bar.success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);color:#4ade80}
.alert-bar.danger{background:rgba(252,0,37,.1);border:1px solid var(--border-red);color:#ff6680}
.alert-bar.warning{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2);color:#fbbf24}
.alert-bar.info{background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);color:#60a5fa}

/* Toolbar */
.toolbar{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.tab-bar{display:flex;gap:3px;background:var(--panel-2);border:1px solid var(--border);border-radius:999px;padding:3px}
.tab-btn{padding:6px 16px;border-radius:999px;border:none;background:transparent;color:var(--text-2);
         font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;
         transition:all var(--t) var(--ease)}
.tab-btn.active{background:var(--red);color:#fff;box-shadow:0 2px 10px rgba(252,0,37,.3)}
.search-bar{flex:1;min-width:160px;display:flex;align-items:center;gap:7px;background:var(--panel-2);
            border:1px solid var(--border-md);border-radius:var(--radius-sm);padding:7px 11px;
            transition:border-color var(--t) var(--ease)}
.search-bar:focus-within{border-color:var(--red)}
.search-bar i{color:var(--text-3);font-size:13px;flex-shrink:0}
.search-bar input{flex:1;background:none;border:none;outline:none;color:var(--text);font-family:var(--font);font-size:13px}
.search-bar input::placeholder{color:var(--text-3)}

/* Event cards */
.evt-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
          margin-bottom:10px;overflow:hidden;transition:border-color var(--t) var(--ease)}
.evt-card:hover{border-color:var(--border-md)}
.evt-card.editing-target{border-color:var(--amber) !important;box-shadow:0 0 0 2px rgba(245,158,11,.1)}

.evt-head{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:10px}
.evt-badge{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:5px}
.evt-badge.aberta{background:var(--green)}
.evt-badge.encerrada{background:var(--text-3)}
.evt-info{flex:1;min-width:0}
.evt-title{font-size:13px;font-weight:700;color:var(--text);margin-bottom:3px;line-height:1.3}
.evt-meta{font-size:11px;color:var(--text-3);display:flex;align-items:center;gap:5px}
.evt-meta i{color:var(--amber);font-size:10px}
.evt-actions{display:flex;gap:5px;flex-shrink:0}
.btn-sm-icon{width:28px;height:28px;border-radius:7px;border:1px solid;display:flex;align-items:center;
             justify-content:center;font-size:12px;cursor:pointer;transition:all var(--t) var(--ease);background:transparent}
.btn-sm-icon.edit{border-color:rgba(245,158,11,.25);color:#fbbf24}
.btn-sm-icon.edit:hover{background:rgba(245,158,11,.15)}
.bets-pill{background:var(--panel-3);border:1px solid var(--border);border-radius:7px;
           padding:3px 9px;font-size:11px;font-weight:700;color:var(--text-2);display:flex;align-items:center;gap:4px}

.evt-opts{padding:12px 16px;display:flex;flex-wrap:wrap;gap:7px;border-bottom:1px solid var(--border)}
.opt-chip{background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;
          padding:7px 12px;font-size:12px;font-weight:600;color:var(--text-2);display:flex;flex-direction:column;gap:2px}
.opt-chip.win{background:rgba(34,197,94,.08);border-color:rgba(34,197,94,.25);color:var(--text)}
.opt-chip-name{display:flex;align-items:center;gap:5px}
.opt-chip-name .ic{color:var(--green);font-size:11px}
.opt-chip-count{font-size:10px;color:var(--text-3)}

.evt-footer{padding:12px 16px}
.result-row{display:flex;gap:8px;align-items:center}
.f-select{flex:1;background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);
          padding:8px 11px;color:var(--text);font-family:var(--font);font-size:12px;outline:none;
          transition:border-color var(--t) var(--ease)}
.f-select:focus{border-color:var(--red)}
.btn-close-evt{background:var(--red);color:#fff;border:none;border-radius:var(--radius-sm);
               padding:8px 15px;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;
               white-space:nowrap;flex-shrink:0;transition:opacity var(--t) var(--ease)}
.btn-close-evt:hover{opacity:.85}
.btn-alter-evt{background:rgba(245,158,11,.1);color:#fbbf24;border:1px solid rgba(245,158,11,.2);
               border-radius:var(--radius-sm);padding:8px 15px;font-family:var(--font);font-size:12px;
               font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0;transition:all var(--t) var(--ease)}
.btn-alter-evt:hover{background:rgba(245,158,11,.2)}

.empty-state{text-align:center;padding:52px 20px;color:var(--text-3);background:var(--panel);
             border:1px solid var(--border);border-radius:var(--radius)}
.empty-state i{font-size:28px;margin-bottom:8px;display:block}
.empty-state p{font-size:13px}

.evt-card.hidden{display:none}
.no-results{text-align:center;padding:30px 20px;color:var(--text-3);font-size:13px;display:none}

/* ── Autocomplete ── */
.ac-wrap{position:relative;flex:1}
.ac-wrap .f-input{width:100%;margin-bottom:0}
.ac-drop{position:absolute;top:calc(100% + 4px);left:0;right:0;background:var(--panel-2);
         border:1px solid var(--border-md);border-radius:var(--radius-sm);z-index:500;
         max-height:200px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.4);display:none}
.ac-drop::-webkit-scrollbar{width:4px}
.ac-drop::-webkit-scrollbar-thumb{background:var(--border-md);border-radius:4px}
.ac-item{padding:8px 12px;font-size:12px;font-weight:500;color:var(--text-2);cursor:pointer;
         display:flex;align-items:center;gap:7px;transition:background var(--t) var(--ease)}
.ac-item:hover,.ac-item.ac-active{background:var(--panel-3);color:var(--text)}
.ac-item i{font-size:11px;color:var(--text-3);flex-shrink:0}

/* ── Mobile ── */
.mob-bar{display:none;align-items:center;gap:10px;height:52px;padding:0 14px;
         background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.mob-ham{background:none;border:1px solid var(--border);border-radius:8px;color:var(--text);
         width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px}
.mob-title{font-size:14px;font-weight:800;color:var(--text);flex:1}
.mob-title span{color:var(--red)}
.sb-close{display:none;background:none;border:none;color:var(--text-2);font-size:18px;cursor:pointer;padding:4px}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:199;backdrop-filter:blur(2px)}
.sb-overlay.open{display:block}

@media(max-width:900px){
  .sb{transform:translateX(-100%);transition:transform 280ms var(--ease)}
  .sb.open{transform:translateX(0)}
  .sb-close{display:flex;align-items:center;justify-content:center}
  .page{margin-left:0}
  .topbar{display:none}
  .mob-bar{display:flex}
  .body-wrap{flex-direction:column}
  .form-panel{width:100%;position:static;height:auto;border-right:none;border-bottom:1px solid var(--border)}
  .list-panel{padding:16px}
}
@media(max-width:500px){
  .stats-strip{gap:7px}
  .stat-chip{padding:8px 12px}
}
</style>
</head>
<body>

<div class="shell">
<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<!-- Sidebar nav -->
<aside class="sb" id="sidebar">
  <div class="sb-logo-wrap">
    <div class="sb-logo">FBA</div>
    <span class="sb-brand">FBA <span>Admin</span></span>
    <button class="sb-close" onclick="closeSidebar()"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?= strtoupper(substr($user['nome'] ?? 'A', 0, 1)) ?></div>
    <div class="sb-name"><?= htmlspecialchars($user['nome'] ?? '') ?></div>
    <div class="sb-role">Administrador</div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Menu</div>
    <a href="../index.php"             class="sb-link"><i class="bi bi-lightning-charge"></i>Apostas</a>
    <a href="../games.php"             class="sb-link"><i class="bi bi-joystick"></i>Games</a>
    <a href="../user/ranking-geral.php"class="sb-link"><i class="bi bi-trophy"></i>Ranking</a>
    <div class="sb-section">Admin</div>
    <a href="controlegames.php"        class="sb-link"><i class="bi bi-gear-fill"></i>Controle de Jogos</a>
    <a href="dashboard.php"            class="sb-link active"><i class="bi bi-receipt-cutoff"></i>Apostas</a>
  </nav>
  <div class="sb-footer">
    <a href="../auth/logout.php" class="sb-logout"><i class="bi bi-box-arrow-right"></i>Sair</a>
  </div>
</aside>

<!-- Main page -->
<div class="page">

  <!-- Topbar (desktop) -->
  <div class="topbar">
    <div class="topbar-title">Controle de <span>Apostas</span></div>
    <div class="chip"><i class="bi bi-coin" style="color:var(--amber)"></i><?= number_format($user['pontos'], 0, ',', '.') ?></div>
    <div class="chip"><i class="bi bi-gem" style="color:#a78bfa"></i><?= number_format($user['fba_points'], 0, ',', '.') ?></div>
  </div>

  <!-- Topbar (mobile) -->
  <div class="mob-bar">
    <button class="mob-ham" onclick="openSidebar()"><i class="bi bi-list"></i></button>
    <span class="mob-title">Admin <span>Apostas</span></span>
    <div class="chip" style="font-size:11px"><i class="bi bi-coin" style="color:var(--amber)"></i><?= number_format($user['pontos'], 0, ',', '.') ?></div>
  </div>

  <div class="body-wrap">

    <!-- ── FORM PANEL (fixed) ── -->
    <div class="form-panel">
      <div class="form-head">
        <div class="form-head-icon" id="formIcon"><i class="bi bi-plus-lg"></i></div>
        <div>
          <div class="form-head-title" id="formTitle">Nova Aposta</div>
          <div class="form-head-sub" id="formSub">Preencha e publique</div>
        </div>
      </div>
      <div class="form-body">
        <form method="POST" id="mainForm">
          <input type="hidden" name="acao"      id="acaoInput"    value="criar_evento">
          <input type="hidden" name="id_evento" id="idEventoInput">

          <label class="f-label">Pergunta / Evento</label>
          <input type="text" name="nome_evento" id="nomeInput" class="f-input"
                 placeholder="Ex: Quem ganha o jogo?" required>

          <label class="f-label">Data limite</label>
          <input type="datetime-local" name="data_limite" id="dataInput" class="f-input" required>

          <label class="f-label" style="margin-top:2px">Opções</label>
          <div class="opcoes-list" id="listaOpcoes">
            <div class="opcao-row">
              <input type="hidden" name="opcoes_ids[]" value="">
              <div class="ac-wrap">
                <input type="text" name="opcoes_nomes[]" class="f-input" placeholder="Time, jogador ou texto livre" required>
                <div class="ac-drop"></div>
              </div>
            </div>
            <div class="opcao-row">
              <input type="hidden" name="opcoes_ids[]" value="">
              <div class="ac-wrap">
                <input type="text" name="opcoes_nomes[]" class="f-input" placeholder="Time, jogador ou texto livre" required>
                <div class="ac-drop"></div>
              </div>
            </div>
          </div>

          <button type="button" class="btn-add" onclick="addOpcao()">
            <i class="bi bi-plus-lg"></i> Adicionar opção
          </button>

          <button type="submit" class="btn-submit" id="btnSubmit">
            <i class="bi bi-send-fill"></i> Publicar Aposta
          </button>
          <button type="button" class="btn-cancel" id="btnCancelar" onclick="cancelarEdicao()">
            <i class="bi bi-x-lg"></i> Cancelar edição
          </button>
        </form>
      </div>
    </div>

    <!-- ── LIST PANEL ── -->
    <div class="list-panel">

      <?php if ($mensagem): ?>
      <div class="alert-bar <?= $mensagemType ?>">
        <i class="bi bi-<?= $mensagemType==='success'?'check-circle-fill':($mensagemType==='warning'?'exclamation-triangle-fill':($mensagemType==='info'?'info-circle-fill':'x-circle-fill')) ?>"></i>
        <?= htmlspecialchars($mensagem) ?>
      </div>
      <?php endif; ?>

      <!-- Stats -->
      <div class="stats-strip">
        <div class="stat-chip">
          <div class="stat-chip-icon" style="background:rgba(34,197,94,.1)"><i class="bi bi-unlock-fill" style="color:var(--green)"></i></div>
          <div><div class="stat-chip-val"><?= $totalAbertas ?></div><div class="stat-chip-label">Abertas</div></div>
        </div>
        <div class="stat-chip">
          <div class="stat-chip-icon" style="background:rgba(255,255,255,.05)"><i class="bi bi-lock-fill" style="color:var(--text-3)"></i></div>
          <div><div class="stat-chip-val"><?= $totalEncerradas ?></div><div class="stat-chip-label">Encerradas</div></div>
        </div>
        <div class="stat-chip">
          <div class="stat-chip-icon" style="background:rgba(245,158,11,.1)"><i class="bi bi-people-fill" style="color:var(--amber)"></i></div>
          <div><div class="stat-chip-val"><?= $totalPalpites ?></div><div class="stat-chip-label">Palpites</div></div>
        </div>
      </div>

      <!-- Toolbar -->
      <div class="toolbar">
        <div class="tab-bar">
          <a href="?status=aberta"    class="tab-btn <?= $filtro_status==='aberta'    ?'active':'' ?>"><i class="bi bi-unlock me-1"></i>Abertas</a>
          <a href="?status=encerrada" class="tab-btn <?= $filtro_status==='encerrada' ?'active':'' ?>"><i class="bi bi-lock me-1"></i>Encerradas</a>
        </div>
        <div class="search-bar">
          <i class="bi bi-search"></i>
          <input type="text" placeholder="Buscar aposta..." oninput="filtrar(this.value)">
        </div>
      </div>

      <div class="no-results" id="noResults"><i class="bi bi-inbox"></i> Nenhuma aposta encontrada.</div>

      <?php if (empty($eventos)): ?>
      <div class="empty-state">
        <i class="bi bi-inbox"></i>
        <p>Nenhuma aposta <?= $filtro_status === 'aberta' ? 'aberta' : 'encerrada' ?> no momento.</p>
      </div>
      <?php endif; ?>

      <?php foreach ($eventos as $evt): ?>
      <div class="evt-card" id="card-<?= $evt['id'] ?>">
        <div class="evt-head">
          <div class="evt-badge <?= $evt['status'] ?>"></div>
          <div class="evt-info">
            <div class="evt-title"><?= htmlspecialchars($evt['nome']) ?></div>
            <div class="evt-meta">
              <i class="bi bi-clock"></i>
              <?= date('d/m/Y \à\s H:i', strtotime($evt['data_limite'])) ?>
            </div>
          </div>
          <div class="evt-actions">
            <button class="btn-sm-icon edit" title="Editar"
                    onclick='editarAposta(<?= json_encode($evt) ?>)'>
              <i class="bi bi-pencil-square"></i>
            </button>
            <div class="bets-pill">
              <i class="bi bi-people-fill"></i><?= $evt['total_apostas_evento'] ?>
            </div>
          </div>
        </div>

        <div class="evt-opts">
          <?php foreach ($evt['opcoes'] as $op):
            $win = $evt['status']==='encerrada' && $evt['vencedor_opcao_id']==$op['id'];
          ?>
          <div class="opt-chip <?= $win?'win':'' ?>">
            <div class="opt-chip-name">
              <?php if ($win): ?><i class="bi bi-check-circle-fill ic"></i><?php endif; ?>
              <?= htmlspecialchars($op['descricao']) ?>
            </div>
            <div class="opt-chip-count"><?= (int)$op['total_palpites'] ?> palpites</div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="evt-footer">
          <?php if ($evt['status']==='aberta'): ?>
          <form method="POST" class="result-row"
                onsubmit="return confirm('Encerrar aposta e pagar usuários? (+75 FBA Points por acerto)')">
            <input type="hidden" name="acao" value="encerrar_evento">
            <input type="hidden" name="id_evento" value="<?= $evt['id'] ?>">
            <select name="vencedor_opcao_id" class="f-select" required>
              <option value="">Selecione o resultado...</option>
              <?php foreach ($evt['opcoes'] as $op): ?>
              <option value="<?= $op['id'] ?>"><?= htmlspecialchars($op['descricao']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-close-evt">
              <i class="bi bi-flag-fill"></i> Encerrar
            </button>
          </form>
          <?php else: ?>
          <form method="POST" class="result-row"
                onsubmit="return confirm('Corrigir vencedor vai ajustar os pontos pagos. Continuar?')">
            <input type="hidden" name="acao" value="alterar_vencedor">
            <input type="hidden" name="id_evento" value="<?= $evt['id'] ?>">
            <select name="vencedor_opcao_id" class="f-select" required>
              <option value="">Alterar vencedor...</option>
              <?php foreach ($evt['opcoes'] as $op): ?>
              <option value="<?= $op['id'] ?>" <?= $evt['vencedor_opcao_id']==$op['id']?'selected':'' ?>>
                <?= htmlspecialchars($op['descricao']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-alter-evt">
              <i class="bi bi-arrow-repeat"></i> Corrigir
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>

    </div><!-- /list-panel -->
  </div><!-- /body-wrap -->
</div><!-- /page -->
</div><!-- /shell -->

<script>
const AC_SUGGESTIONS = <?= json_encode($acSuggestions, JSON_UNESCAPED_UNICODE) ?>;
const AC_TEAM_SET    = new Set(<?= json_encode($acTeams, JSON_UNESCAPED_UNICODE) ?>);

let editingCardId = null;

function initAC(input) {
  const wrap = input.closest('.ac-wrap');
  if (!wrap) return;
  const drop = wrap.querySelector('.ac-drop');
  let activeIdx = -1;

  function show(items) {
    drop.innerHTML = '';
    activeIdx = -1;
    if (!items.length) { drop.style.display = 'none'; return; }
    items.forEach(name => {
      const d = document.createElement('div');
      d.className = 'ac-item';
      const icon = AC_TEAM_SET.has(name) ? 'shield-half' : 'person-fill';
      d.innerHTML = `<i class="bi bi-${icon}"></i>${name}`;
      d.addEventListener('mousedown', e => {
        e.preventDefault();
        input.value = name;
        drop.style.display = 'none';
      });
      drop.appendChild(d);
    });
    drop.style.display = 'block';
  }

  function move(dir) {
    const items = drop.querySelectorAll('.ac-item');
    if (!items.length) return;
    items[activeIdx]?.classList.remove('ac-active');
    activeIdx = Math.max(0, Math.min(items.length - 1, activeIdx + dir));
    items[activeIdx].classList.add('ac-active');
    items[activeIdx].scrollIntoView({block:'nearest'});
  }

  input.addEventListener('input', () => {
    const q = input.value.trim().toLowerCase();
    if (!q) { drop.style.display = 'none'; return; }
    const starts   = AC_SUGGESTIONS.filter(s => s.toLowerCase().startsWith(q));
    const contains = AC_SUGGESTIONS.filter(s => !s.toLowerCase().startsWith(q) && s.toLowerCase().includes(q));
    show([...starts, ...contains].slice(0, 30));
  });

  input.addEventListener('keydown', e => {
    if (drop.style.display === 'none') return;
    if (e.key === 'ArrowDown')  { e.preventDefault(); move(1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
    else if (e.key === 'Enter') {
      const active = drop.querySelector('.ac-active');
      if (active) { e.preventDefault(); input.value = active.textContent.trim(); drop.style.display = 'none'; }
    }
    else if (e.key === 'Escape') { drop.style.display = 'none'; }
  });

  document.addEventListener('click', e => {
    if (!wrap.contains(e.target)) drop.style.display = 'none';
  });
}

function addOpcao(id='', nome='') {
  const div = document.createElement('div');
  div.className = 'opcao-row';
  div.innerHTML = `
    <input type="hidden" name="opcoes_ids[]" value="${id}">
    <div class="ac-wrap">
      <input type="text" name="opcoes_nomes[]" class="f-input" value="${nome.replace(/"/g,'&quot;')}" placeholder="Time, jogador ou texto livre" required>
      <div class="ac-drop"></div>
    </div>
    <button type="button" class="btn-rm" onclick="this.closest('.opcao-row').remove()"><i class="bi bi-x-lg"></i></button>
  `;
  document.getElementById('listaOpcoes').appendChild(div);
  initAC(div.querySelector('input[name="opcoes_nomes[]"]'));
}

function editarAposta(evt) {
  // Highlight card
  document.querySelectorAll('.evt-card').forEach(c => c.classList.remove('editing-target'));
  const card = document.getElementById('card-' + evt.id);
  if (card) card.classList.add('editing-target');
  editingCardId = evt.id;

  // Update form header
  document.getElementById('formTitle').textContent = 'Editando Aposta';
  document.getElementById('formSub').textContent   = evt.nome.length > 30 ? evt.nome.slice(0,30)+'…' : evt.nome;
  document.getElementById('formIcon').innerHTML    = '<i class="bi bi-pencil-square"></i>';
  document.getElementById('formIcon').style.background = 'rgba(245,158,11,.1)';
  document.getElementById('formIcon').style.borderColor= 'rgba(245,158,11,.25)';
  document.getElementById('formIcon').style.color  = '#fbbf24';

  // Fill fields
  document.getElementById('acaoInput').value    = 'editar_evento';
  document.getElementById('idEventoInput').value = evt.id;
  document.getElementById('nomeInput').value     = evt.nome;
  document.getElementById('dataInput').value     = evt.data_limite.replace(' ','T').substring(0,16);

  // Fill opcoes
  const lista = document.getElementById('listaOpcoes');
  lista.innerHTML = '';
  evt.opcoes.forEach(op => addOpcao(op.id, op.descricao));

  document.getElementById('btnSubmit').innerHTML  = '<i class="bi bi-save-fill"></i> Salvar Alterações';
  document.getElementById('btnCancelar').classList.add('show');

  // Scroll form into view on mobile
  document.querySelector('.form-panel').scrollTo({top:0, behavior:'smooth'});
}

function cancelarEdicao() {
  if (editingCardId) {
    const card = document.getElementById('card-' + editingCardId);
    if (card) card.classList.remove('editing-target');
    editingCardId = null;
  }

  document.getElementById('formTitle').textContent = 'Nova Aposta';
  document.getElementById('formSub').textContent   = 'Preencha e publique';
  document.getElementById('formIcon').innerHTML    = '<i class="bi bi-plus-lg"></i>';
  document.getElementById('formIcon').style.background   = 'var(--red-soft)';
  document.getElementById('formIcon').style.borderColor  = 'var(--border-red)';
  document.getElementById('formIcon').style.color        = 'var(--red)';

  document.getElementById('acaoInput').value    = 'criar_evento';
  document.getElementById('idEventoInput').value = '';
  document.getElementById('btnSubmit').innerHTML = '<i class="bi bi-send-fill"></i> Publicar Aposta';
  document.getElementById('btnCancelar').classList.remove('show');
  document.getElementById('mainForm').reset();

  const lista = document.getElementById('listaOpcoes');
  lista.innerHTML = '';
  addOpcao(); addOpcao();
}

function filtrar(q) {
  const cards = document.querySelectorAll('.evt-card');
  const term  = q.toLowerCase().trim();
  let vis = 0;
  cards.forEach(c => {
    const match = !term || (c.querySelector('.evt-title')?.textContent.toLowerCase() ?? '').includes(term);
    c.classList.toggle('hidden', !match);
    if (match) vis++;
  });
  document.getElementById('noResults').style.display = vis===0 ? 'block' : 'none';
}

function openSidebar() {
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('sbOverlay').classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sbOverlay').classList.remove('open');
  document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('#listaOpcoes input[name="opcoes_nomes[]"]').forEach(initAC);
});
</script>
</body>
</html>
