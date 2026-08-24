<?php
/**
 * admin-apostas.php — criação e controle das apostas, dentro do fbabrasil.com.br.
 *
 * Mesmas funções do antigo games/admin/dashboard.php (criar, editar, encerrar
 * pagando os acertos e corrigir vencedor), só que com a sidebar e o visual do
 * site, e protegida pelo admin do Games.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/games/core/funcoes.php';
requireAuth();

$sessionUser = getUserSession();
$pdo = db();
$userId = (int) $sessionUser['id'];

ensureGamesSchema($pdo);

if (!hasGamesAdminAccess($pdo, $userId)) {
    header('Location: /dashboard.php');
    exit;
}

// Time do GM — o cartão no topo da sidebar depende disso.
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$userId]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC) ?: null;
if ($team) { $team['photo_url'] = getTeamPhoto($team['photo_url'] ?? null); }
$user = $sessionUser;

$_SESSION['user_id'] = $userId; // as funções do games leem essa chave

$mensagem = "";
$mensagemType = "success";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['acao']) && $_POST['acao'] == 'criar_evento') {
        $nome_evento  = trim($_POST['nome_evento']);
        $data_limite  = $_POST['data_limite'];
        $opcoes_nomes = $_POST['opcoes_nomes'];
        $opcoes_imgs  = $_POST['opcoes_imgs'] ?? [];

        if (empty($nome_evento) || empty($data_limite) || count(array_filter($opcoes_nomes)) < 2) {
            $mensagem = "Preencha todos os campos (mínimo 2 opções).";
            $mensagemType = "warning";
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO eventos (nome, data_limite, status) VALUES (:nome, :data, 'aberta')");
                $stmt->execute([':nome' => $nome_evento, ':data' => $data_limite]);
                $evento_id = $pdo->lastInsertId();
                $stmtOpcao = $pdo->prepare("INSERT INTO opcoes (evento_id, descricao, odd, img_url) VALUES (:eid, :desc, 1, :img)");
                foreach ($opcoes_nomes as $i => $op) {
                    if (!empty(trim($op))) {
                        $img = !empty($opcoes_imgs[$i]) ? trim($opcoes_imgs[$i]) : null;
                        $stmtOpcao->execute([':eid' => $evento_id, ':desc' => trim($op), ':img' => $img]);
                    }
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
        $op_imgs     = $_POST['opcoes_imgs'] ?? [];
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE eventos SET nome=:nome, data_limite=:data WHERE id=:id")
                ->execute([':nome'=>$nome_evento,':data'=>$data_limite,':id'=>$id_evento]);
            $stmtUpd = $pdo->prepare("UPDATE opcoes SET descricao=:desc, img_url=:img WHERE id=:oid AND evento_id=:eid");
            $stmtIns = $pdo->prepare("INSERT INTO opcoes (evento_id,descricao,odd,img_url) VALUES (:eid,:desc,1,:img)");
            for ($i = 0; $i < count($op_nomes); $i++) {
                $nome = trim($op_nomes[$i]);
                $oid  = $op_ids[$i] ?? '';
                $img  = !empty($op_imgs[$i]) ? trim($op_imgs[$i]) : null;
                if (!empty($nome)) {
                    if (!empty($oid)) $stmtUpd->execute([':desc'=>$nome,':img'=>$img,':oid'=>$oid,':eid'=>$id_evento]);
                    else              $stmtIns->execute([':eid'=>$id_evento,':desc'=>$nome,':img'=>$img]);
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
                    UPDATE games_usuarios u
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
                            UPDATE games_usuarios u
                            JOIN (SELECT DISTINCT id_usuario FROM palpites WHERE opcao_id=?) p ON p.id_usuario=u.id
                            SET u.fba_points=u.fba_points-75, u.acertos_eventos=GREATEST(u.acertos_eventos-1,0)
                        ")->execute([$vencedor_antigo]);
                    }
                    $pdo->prepare("
                        UPDATE games_usuarios u
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

$acTeams = [];
$acPlayers = [];
$acImgMap = []; // name -> img_url
try {
    $pdoFba = $pdo; // depois da fusão é o mesmo banco
    $rows = $pdoFba->query("SELECT name, photo_url FROM teams ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $acTeams[] = $r['name'];
        if (!empty($r['photo_url'])) $acImgMap[$r['name']] = $r['photo_url'];
    }
    $rows = $pdoFba->query("SELECT name, nba_player_id, foto_adicional FROM players ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $acPlayers[] = $r['name'];
        if (!empty($r['foto_adicional'])) {
            $fa = trim($r['foto_adicional']);
            if (preg_match('/^https?:\/\//', $fa) || preg_match('/^data:image\//', $fa)) {
                $acImgMap[$r['name']] = $fa;
            } else {
                $acImgMap[$r['name']] = '/' . ltrim($fa, '/');
            }
        } elseif (!empty($r['nba_player_id'])) {
            $acImgMap[$r['name']] = "https://cdn.nba.com/headshots/nba/latest/260x190/{$r['nba_player_id']}.png";
        }
    }
} catch (Exception $e) { /* se o banco principal não estiver acessível, sugestões ficam vazias */ }
$acTeamSet = array_flip($acTeams);
$acSuggestions = $acTeams;
foreach ($acPlayers as $n) { if (!isset($acTeamSet[$n])) $acSuggestions[] = $n; }
sort($acSuggestions);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#fc0025">
<title>Admin Apostas — FBA Manager</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<style>
    :root {
        --red:#fc0025; --red-soft:rgba(252,0,37,.10); --border-red:rgba(252,0,37,.22);
        --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
        --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
        --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
        --amber:#f59e0b; --green:#22c55e; --blue:#3b82f6;
        --sidebar-w:260px; --font:'Montserrat',sans-serif;
        --radius:14px; --radius-sm:10px; --radius-xs:6px;
        --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
    }
    :root[data-theme="light"] {
        --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
        --border:#e3e6ee; --border-md:#d7dbe6; --text:#111217;
        --text-2:#5b6270; --text-3:#657080;
    }
    *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
    html,body { height:100%; }
    body { font-family:var(--font); background:var(--bg); color:var(--text); -webkit-font-smoothing:antialiased; }
    .app { display:flex; min-height:100vh; }
    .sidebar { position:fixed; top:0; left:0; width:260px; height:100vh; background:var(--panel); border-right:1px solid var(--border); display:flex; flex-direction:column; z-index:300; overflow-y:auto; scrollbar-width:none; transition:transform var(--t) var(--ease); }
    .sidebar::-webkit-scrollbar { display:none; }
    .sb-brand { padding:22px 18px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:12px; flex-shrink:0; }
    .sb-logo { width:34px; height:34px; border-radius:9px; background:var(--red); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; color:#fff; flex-shrink:0; }
    .sb-brand-text { font-weight:700; font-size:15px; line-height:1.1; }
    .sb-brand-text span { display:block; font-size:11px; font-weight:400; color:var(--text-2); }
    .sb-team { margin:14px 14px 0; background:var(--panel-2); border:1px solid var(--border); border-radius:var(--radius-sm); padding:14px; display:flex; align-items:center; gap:10px; flex-shrink:0; }
    .sb-team img { width:40px; height:40px; border-radius:9px; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
    .sb-team-name { font-size:13px; font-weight:600; color:var(--text); line-height:1.2; }
    .sb-team-league { font-size:11px; color:var(--red); font-weight:600; }
    .sb-nav { flex:1; padding:12px 10px 8px; }
    .sb-section { font-size:10px; font-weight:600; letter-spacing:1.2px; text-transform:uppercase; color:var(--text-3); padding:12px 10px 6px; }
    .sb-nav a { display:flex; align-items:center; gap:10px; padding:10px; border-radius:var(--radius-sm); color:var(--text-2); font-size:13px; font-weight:500; text-decoration:none; margin-bottom:2px; transition:all var(--t) var(--ease); }
    .sb-nav a i { font-size:15px; width:18px; text-align:center; flex-shrink:0; }
    .sb-nav a:hover { background:var(--panel-2); color:var(--text); }
    .sb-nav a.active { background:var(--red-soft); color:var(--red); font-weight:600; }
    .sb-nav a.active i { color:var(--red); }
    .sb-theme-toggle { margin:0 14px 12px; padding:8px 10px; border-radius:10px; border:1px solid var(--border); background:var(--panel-2); color:var(--text); display:flex; align-items:center; justify-content:center; gap:8px; font-size:12px; font-weight:600; cursor:pointer; }
    .sb-footer { padding:12px 14px; border-top:1px solid var(--border); display:flex; align-items:center; gap:10px; flex-shrink:0; }
    .sb-avatar { width:30px; height:30px; border-radius:50%; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
    .sb-username { font-size:12px; font-weight:500; color:var(--text); flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .sb-logout { width:26px; height:26px; border-radius:7px; background:transparent; border:1px solid var(--border); color:var(--text-2); display:flex; align-items:center; justify-content:center; font-size:12px; cursor:pointer; text-decoration:none; flex-shrink:0; }
    .main { flex:1; margin-left:var(--sidebar-w); display:flex; flex-direction:column; min-width:0; }
    .page-hero { padding:28px 32px 0; }
    .page-eyebrow { font-size:11px; font-weight:600; letter-spacing:1.4px; text-transform:uppercase; color:var(--red); margin-bottom:4px; }
    .page-title { font-size:26px; font-weight:800; line-height:1.1; display:flex; align-items:center; gap:10px; }
    .page-title i { color:var(--red); }
    .page-sub { font-size:13px; color:var(--text-2); margin-top:4px; }
    .topbar { display:none; position:fixed; top:0; left:0; right:0; height:54px; background:var(--panel); border-bottom:1px solid var(--border); align-items:center; padding:0 16px; gap:12px; z-index:260; }
    .topbar-title { font-weight:700; font-size:15px; flex:1; }
    .topbar-title em { color:var(--red); font-style:normal; }
    .menu-btn { background:none; border:none; color:var(--text); font-size:22px; cursor:pointer; }
    .sb-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:270; }
    .sb-overlay.open { display:block; }
    .body-wrap { display:grid; grid-template-columns:380px 1fr; gap:20px; padding:24px 32px 56px; align-items:start; }
    @media (max-width:1100px) { .body-wrap { grid-template-columns:1fr; } }
    @media (max-width:992px) {
        :root { --sidebar-w: 0px; }
        .main { margin-left:0; padding-top:54px; }
        .topbar { display:flex; }
        .sidebar { transform:translateX(-260px); }
        .sidebar.open { transform:translateX(0); }
        .page-hero { padding:20px 18px 0; }
        .body-wrap { padding:18px 18px 44px; }
    }
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
html,body{height:100%}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}

/* ── Shell ── */

/* ── Sidebar nav ── */
         display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:200;overflow-y:auto}
         justify-content:center;font-weight:800;font-size:11px;color:#fff;flex-shrink:0}
           display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:var(--red);margin-bottom:8px}
         padding:8px 4px;background:var(--panel-2);border-radius:8px;border:1px solid var(--border)}
         font-size:12px;font-weight:500;color:var(--text-2);transition:all var(--t) var(--ease);border-left:3px solid transparent}
           border:1px solid var(--border);background:transparent;color:var(--text-2);text-decoration:none;
           font-family:var(--font);font-size:12px;font-weight:600;transition:all var(--t) var(--ease)}

/* ── Page body ── */

/* ── Topbar ── */
        display:flex;align-items:center;padding:0 20px;gap:14px;z-index:100}
.chip{display:flex;align-items:center;gap:5px;background:var(--panel-2);border:1px solid var(--border);
      border-radius:999px;padding:4px 11px;font-size:11px;font-weight:700;color:var(--text)}
.chip i{font-size:11px}

/* ── Main layout: fixed form + scrollable list ── */

/* Form panel — fixed */
.form-panel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
            display:flex;flex-direction:column;position:sticky;top:24px;
            scrollbar-width:thin;scrollbar-color:var(--border-md) transparent}
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
.list-panel{min-width:0;scrollbar-width:thin;scrollbar-color:var(--border-md) transparent}
.list-panel::-webkit-scrollbar{width:5px}
.list-panel::-webkit-scrollbar-track{background:transparent}
.list-panel::-webkit-scrollbar-thumb{background:var(--border-md);border-radius:999px}
.list-panel::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.18)}

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
         background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
         width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px}

@media(max-width:768px){
  html,body{height:auto;overflow:auto}
  .body-wrap{flex-direction:column;overflow:visible;height:auto}
  .form-panel{width:100%;height:auto;flex-shrink:0;border-right:none;border-bottom:1px solid var(--border);overflow-y:visible}
  .list-panel{height:auto;overflow-y:visible;padding:16px}
}
@media(max-width:500px){
  .stats-strip{gap:7px}
  .stat-chip{padding:8px 12px}
}
<?php include __DIR__ . '/includes/accent-color.php'; ?>
    @media (prefers-reduced-motion: reduce) { *,*::before,*::after { transition-duration:.01ms !important; } }
</style>
</head>
<body>
<div class="app">

<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
    <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
    <div class="topbar-title">FBA <em>Manager</em></div>
</header>

<main class="main">
    <div class="page-hero">
        <div class="page-eyebrow">Admin · Games</div>
        <h1 class="page-title"><i class="bi bi-graph-up-arrow"></i> Controle de Apostas</h1>
        <p class="page-sub">Crie eventos, acompanhe os palpites e encerre pagando quem acertou.</p>
        <div style="margin-top:12px">
            <a href="/admin.php" style="display:inline-flex;align-items:center;gap:7px;text-decoration:none;background:transparent;border:1px solid var(--border-md);color:var(--text-2);font-weight:600;font-size:12.5px;border-radius:8px;padding:8px 14px">
                <i class="bi bi-arrow-left"></i> Voltar ao Admin
            </a>
        </div>
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

          <label class="f-label">Pergunta pronta (atalho, opcional)</label>
          <select class="f-select" id="presetSelect" onchange="aplicarPreset(this)" style="width:100%;margin-bottom:14px">
            <option value="">— Escolher pergunta pronta ou digitar a sua abaixo —</option>
            <optgroup label="Prêmios individuais">
              <option>Quem vai ser o MVP?</option>
              <option>Quem vai ser o ROY?</option>
              <option>Quem vai ser o DPOY?</option>
              <option>Quem vai ser o MIP?</option>
              <option>Quem vai ser o 6homem?</option>
            </optgroup>
            <optgroup label="Temporada regular">
              <option>Quem vai ser o seed1 - LESTE?</option>
              <option>Quem vai ser o seed1 - OESTE?</option>
            </optgroup>
            <optgroup label="Draft">
              <option>Quem vai a pick01?</option>
            </optgroup>
            <optgroup label="Playoffs — Quartas">
              <option>Quartas 01 - OESTE?</option>
              <option>Quartas 02 - OESTE?</option>
              <option>Quartas 03 - OESTE?</option>
              <option>Quartas 04 - OESTE?</option>
              <option>Quartas 01 - LESTE?</option>
              <option>Quartas 02 - LESTE?</option>
              <option>Quartas 03 - LESTE?</option>
              <option>Quartas 04 - LESTE?</option>
            </optgroup>
            <optgroup label="Playoffs — Semis">
              <option>Semis 01 - OESTE?</option>
              <option>Semis 02 - OESTE?</option>
              <option>Semis 01 - LESTE?</option>
              <option>Semis 02 - LESTE?</option>
            </optgroup>
            <optgroup label="Playoffs — Finais">
              <option>Final de Conferência?</option>
              <option>Final NBA?</option>
            </optgroup>
          </select>

          <label class="f-label">Pergunta / Evento</label>
          <input type="text" name="nome_evento" id="nomeInput" class="f-input"
                 placeholder="Ex: Quem ganha o jogo?" required>

          <label class="f-label">Data limite</label>
          <input type="datetime-local" name="data_limite" id="dataInput" class="f-input" required>

          <label class="f-label" style="margin-top:2px">Opções</label>
          <div class="opcoes-list" id="listaOpcoes">
            <div class="opcao-row">
              <input type="hidden" name="opcoes_ids[]" value="">
              <input type="hidden" name="opcoes_imgs[]" class="img-url-input" value="">
              <div class="ac-wrap">
                <input type="text" name="opcoes_nomes[]" class="f-input" placeholder="Time, jogador ou texto livre" required>
                <div class="ac-drop"></div>
              </div>
            </div>
            <div class="opcao-row">
              <input type="hidden" name="opcoes_ids[]" value="">
              <input type="hidden" name="opcoes_imgs[]" class="img-url-input" value="">
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
                data-confirmar="Encerrar aposta e pagar usuários? (+75 FBA Points por acerto)">
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
                data-confirmar="Corrigir vencedor vai ajustar os pontos pagos. Continuar?">
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

<script>
const AC_SUGGESTIONS = <?= json_encode($acSuggestions, JSON_UNESCAPED_UNICODE) ?>;
const AC_TEAM_SET    = new Set(<?= json_encode($acTeams, JSON_UNESCAPED_UNICODE) ?>);
const AC_IMG_MAP     = <?= json_encode($acImgMap, JSON_UNESCAPED_UNICODE) ?>;

let editingCardId = null;

function initAC(input) {
  const wrap = input.closest('.ac-wrap');
  if (!wrap) return;
  const drop = wrap.querySelector('.ac-drop');
  let activeIdx = -1;

  function fillImg(name) {
    const row = input.closest('.opcao-row');
    if (row) {
      const imgInput = row.querySelector('.img-url-input');
      if (imgInput) imgInput.value = AC_IMG_MAP[name] || '';
    }
  }

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
        fillImg(name);
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
      if (active) {
        e.preventDefault();
        const name = active.textContent.trim();
        input.value = name;
        fillImg(name);
        drop.style.display = 'none';
      }
    }
    else if (e.key === 'Escape') { drop.style.display = 'none'; }
  });

  document.addEventListener('click', e => {
    if (!wrap.contains(e.target)) drop.style.display = 'none';
  });
}

function aplicarPreset(select) {
  const valor = select.value;
  if (!valor) return;
  const nomeInput = document.getElementById('nomeInput');
  nomeInput.value = valor;
  select.value = ''; // volta ao placeholder, deixa reutilizar pra proxima aposta
  nomeInput.focus();
}

function addOpcao(id='', nome='', img='') {
  const div = document.createElement('div');
  div.className = 'opcao-row';
  div.innerHTML = `
    <input type="hidden" name="opcoes_ids[]" value="${id}">
    <input type="hidden" name="opcoes_imgs[]" class="img-url-input" value="${img.replace(/"/g,'&quot;')}">
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
  evt.opcoes.forEach(op => addOpcao(op.id, op.descricao, op.img_url || ''));

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
  document.getElementById('dataInput').value = defaultDataLimite();

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

function defaultDataLimite() {
  const d = new Date(Date.now() + 30 * 60 * 1000);
  const pad = n => String(n).padStart(2,'0');
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('#listaOpcoes input[name="opcoes_nomes[]"]').forEach(initAC);
  document.getElementById('dataInput').value = defaultDataLimite();
});
</script>
</body>
</html>
</main>
</div>
<script>
    const _sb = document.getElementById('sidebar');
    const _ov = document.getElementById('sbOverlay');
    document.getElementById('menuBtn')?.addEventListener('click', () => { _sb?.classList.add('open'); _ov?.classList.add('open'); });
    _ov?.addEventListener('click', () => { _sb?.classList.remove('open'); _ov?.classList.remove('open'); });
</script>
</body>
</html>
