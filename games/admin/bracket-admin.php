<?php
session_start();
require '../core/conexao.php';
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$stmt = $pdo->prepare("SELECT is_admin, nome FROM usuarios WHERE id=:id");
$stmt->execute([':id'=>$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user || !$user['is_admin']) die("Acesso negado.");

$msg = ''; $msgType = 'success';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $acao = $_POST['acao']??'';

    if ($acao === 'save_conferences') {
        try {
            $league = trim($_POST['league']??'');
            $teams  = $_POST['teams']??[];
            $pdo->prepare("DELETE FROM fba_bracket_conferences WHERE league=?")->execute([$league]);
            $stmt = $pdo->prepare("INSERT INTO fba_bracket_conferences (team_id,league,conference) VALUES (?,?,?)");
            foreach ($teams as $teamId => $conf) {
                if ($conf==='A'||$conf==='B') $stmt->execute([(int)$teamId,$league,$conf]);
            }
            $msg = "Conferências da liga $league salvas!";
        } catch(Exception $e) { $msg=$e->getMessage(); $msgType='danger'; }
    }

    if ($acao === 'save_official') {
        try {
            $cycle_id  = (int)($_POST['cycle_id']??0);
            $seeds_raw = trim($_POST['seeds_json']??'');
            $rounds_raw= trim($_POST['rounds_json']??'');
            if (!$cycle_id) throw new Exception('cycle_id inválido');
            $stmt = $pdo->prepare("INSERT INTO fba_bracket_official (cycle_id,seeds_json,rounds_json,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE seeds_json=VALUES(seeds_json),rounds_json=VALUES(rounds_json),updated_by=VALUES(updated_by),updated_at=NOW()");
            $stmt->execute([$cycle_id, $seeds_raw?:null, $rounds_raw?:null, $_SESSION['user_id']]);
            // Recalculate all user points for this cycle
            $picks = $pdo->prepare("SELECT id,seeds_json,rounds_json FROM fba_bracket_picks WHERE cycle_id=?");
            $picks->execute([$cycle_id]);
            $official = ['seeds_json'=>$seeds_raw,'rounds_json'=>$rounds_raw];
            foreach ($picks->fetchAll(PDO::FETCH_ASSOC) as $pick) {
                $pts = calcPointsAdmin($pick, $official);
                $pdo->prepare("UPDATE fba_bracket_picks SET points=? WHERE id=?")->execute([$pts,$pick['id']]);
            }
            $msg = "Resultado oficial salvo e pontos recalculados!";
        } catch(Exception $e) { $msg=$e->getMessage(); $msgType='danger'; }
    }

    if ($acao === 'force_reset') {
        try {
            $league = trim($_POST['league']??'');
            // Close open cycles for this league
            $cycles = $pdo->prepare("SELECT * FROM fba_bracket_cycles WHERE league=? AND status='open'");
            $cycles->execute([$league]);
            foreach ($cycles->fetchAll(PDO::FETCH_ASSOC) as $cycle) {
                $winner = $pdo->prepare("SELECT user_id, MAX(points) as pts FROM fba_bracket_picks WHERE cycle_id=? AND locked=1 GROUP BY user_id ORDER BY pts DESC LIMIT 1");
                $winner->execute([$cycle['id']]);
                $w = $winner->fetch(PDO::FETCH_ASSOC);
                if ($w && (int)$w['pts']>0) {
                    $pdo->prepare("UPDATE usuarios SET fba_points=fba_points+100 WHERE id=?")->execute([$w['user_id']]);
                    $pdo->prepare("UPDATE fba_bracket_cycles SET status='closed',winner_user_id=?,points_paid=1 WHERE id=?")->execute([$w['user_id'],$cycle['id']]);
                } else {
                    $pdo->prepare("UPDATE fba_bracket_cycles SET status='closed' WHERE id=?")->execute([$cycle['id']]);
                }
            }
            // Create new cycle
            $pdo->prepare("INSERT INTO fba_bracket_cycles (league,cycle_start,status) VALUES (?,NOW(),'open')")->execute([$league]);
            $msg = "Bracket $league resetado. Novo ciclo criado.";
        } catch(Exception $e) { $msg=$e->getMessage(); $msgType='danger'; }
    }
}

function calcPointsAdmin(array $pick, array $official): int {
    $pts=0;
    $us=@json_decode($pick['seeds_json'],true);
    $ur=@json_decode($pick['rounds_json'],true);
    $os=@json_decode($official['seeds_json'],true);
    $or_=@json_decode($official['rounds_json'],true);
    if (!is_array($us)||!is_array($os)) return 0;
    $cids=[];
    for ($i=0;$i<8;$i++) { if(isset($us[$i]['id'],$os[$i]['id'])&&$us[$i]['id']==$os[$i]['id']){$pts++;$cids[]=(int)$us[$i]['id'];} }
    if (!is_array($ur)||!is_array($or_)) return $pts;
    foreach (['r1','r2','r3'] as $rnd) {
        foreach (($ur[$rnd]??[]) as $i=>$um) {
            $om=$or_[$rnd][$i]??null; if(!$om) continue;
            $uw=$um['w']['id']??null; $ow=$om['w']['id']??null;
            if ($uw&&$ow&&$uw==$ow&&in_array((int)$uw,$cids)) $pts+=2;
        }
    }
    return $pts;
}

// Load data
$leagues = [];
$teamsByLeague = [];
$confAssignments = [];
try {
    $rows = $pdo->query("SELECT team_id,league,conference FROM fba_bracket_conferences")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $confAssignments[$r['league']][$r['team_id']] = $r['conference'];
} catch(Exception $e) {}

try {
    $pdoFba = new PDO('mysql:host=localhost;dbname=u289267434_fbabrasilbanco;charset=utf8mb4','u289267434_fbabrasilbanco','Fbabrasil@2025',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $rows = $pdoFba->query("SELECT id,name,city,league,photo_url FROM teams ORDER BY league,name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $lg=$r['league'];
        if (!in_array($lg,$leagues)) $leagues[]=$lg;
        $teamsByLeague[$lg][] = ['id'=>(int)$r['id'],'name'=>$r['name'],'city'=>$r['city'],'photo_url'=>$r['photo_url']?:'','conference'=>$confAssignments[$lg][(int)$r['id']]??''];
    }
} catch(Exception $e) {}

$cycles = [];
$officialByLeague = [];
foreach ($leagues as $lg) {
    $stmt = $pdo->prepare("SELECT * FROM fba_bracket_cycles WHERE league=? AND status='open' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$lg]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    $cycles[$lg] = $c ?: null;
    if ($c) {
        $stmt2 = $pdo->prepare("SELECT * FROM fba_bracket_official WHERE cycle_id=?");
        $stmt2->execute([$c['id']]);
        $officialByLeague[$lg] = $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Bracket — FBA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--bg:#07070a;--panel:#101013;--panel-2:#16161a;--border:rgba(255,255,255,.08);--text:#f0f0f3;--text-2:#868690;--text-3:#48484f;--font:'Poppins',sans-serif}
body{background:var(--bg);color:var(--text);font-family:var(--font)}
.card-dark{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:20px}
.section-title{font-size:14px;font-weight:700;color:var(--text);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.f-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-2);display:block;margin-bottom:5px}
.f-input{width:100%;background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);font-family:var(--font);font-size:13px;outline:none}
.f-input:focus{border-color:var(--red)}
.team-row{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--border)}
.team-row:last-child{border-bottom:none}
.t-logo{width:28px;height:28px;border-radius:50%;object-fit:cover;background:var(--panel-2);flex-shrink:0}
.t-name{font-size:12px;font-weight:600;color:var(--text);flex:1}
.conf-radio{display:flex;gap:8px}
.conf-btn{padding:4px 14px;border-radius:6px;border:1px solid var(--border);background:transparent;color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s}
.conf-btn.a.active{background:rgba(59,130,246,.15);border-color:#3b82f6;color:#3b82f6}
.conf-btn.b.active{background:rgba(245,158,11,.15);border-color:#f59e0b;color:#f59e0b}
.btn-submit{background:var(--red);color:#fff;border:none;border-radius:8px;padding:10px 22px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:opacity .15s}
.btn-submit:hover{opacity:.85}
.btn-reset{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.2);color:#f87171;border-radius:8px;padding:9px 20px;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer}
.alert-bar{padding:10px 14px;border-radius:8px;font-size:13px;font-weight:500;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.alert-bar.success{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);color:#4ade80}
.alert-bar.danger{background:rgba(252,0,37,.1);border:1px solid rgba(252,0,37,.2);color:#ff6680}
.cycle-info{font-size:11px;color:var(--text-2);margin-bottom:12px}
</style>
</head>
<body class="p-4">
<div style="max-width:900px;margin:0 auto">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:10px">
  <h1 style="font-size:18px;font-weight:800;color:var(--text)">Admin <span style="color:var(--red)">Bracket</span></h1>
  <a href="../bracket.php" style="color:var(--text-2);font-size:12px;text-decoration:none"><i class="bi bi-arrow-left"></i> Ver Bracket</a>
</div>

<?php if ($msg): ?>
<div class="alert-bar <?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php foreach ($leagues as $lg): $cycle=$cycles[$lg]; $official=$officialByLeague[$lg]??null; ?>
<div class="card-dark">
  <div class="section-title"><i class="bi bi-shield-fill" style="color:var(--red)"></i><?= htmlspecialchars($lg) ?></div>
  <?php if ($cycle): ?>
  <div class="cycle-info">Ciclo ativo: #<?= $cycle['id'] ?> · iniciado em <?= date('d/m/Y H:i',strtotime($cycle['cycle_start'])) ?></div>
  <?php else: ?>
  <div class="cycle-info" style="color:#f59e0b">Nenhum ciclo ativo. Acesse bracket.php para criar.</div>
  <?php endif; ?>

  <!-- Conference assignment -->
  <div style="margin-bottom:20px">
    <div style="font-size:12px;font-weight:700;color:var(--text-2);margin-bottom:10px;text-transform:uppercase;letter-spacing:.6px">Conferências</div>
    <form method="POST">
      <input type="hidden" name="acao" value="save_conferences">
      <input type="hidden" name="league" value="<?= htmlspecialchars($lg) ?>">
      <div style="max-height:300px;overflow-y:auto">
        <?php foreach (($teamsByLeague[$lg]??[]) as $t): $conf=$confAssignments[$lg][$t['id']]??''; ?>
        <div class="team-row">
          <?php if ($t['photo_url']): ?><img class="t-logo" src="<?= htmlspecialchars($t['photo_url']) ?>" onerror="this.style.display='none'"><?php endif; ?>
          <div class="t-name"><?= htmlspecialchars($t['name']) ?></div>
          <div class="conf-radio">
            <button type="button" class="conf-btn a <?= $conf==='A'?'active':'' ?>" onclick="setConf(this,'A',<?= $t['id'] ?>,'<?= $lg ?>')">A</button>
            <button type="button" class="conf-btn b <?= $conf==='B'?'active':'' ?>" onclick="setConf(this,'B',<?= $t['id'] ?>,'<?= $lg ?>')">B</button>
            <input type="hidden" name="teams[<?= $t['id'] ?>]" id="conf-<?= $lg ?>-<?= $t['id'] ?>" value="<?= htmlspecialchars($conf) ?>">
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn-submit mt-3"><i class="bi bi-save-fill me-1"></i>Salvar Conferências</button>
    </form>
  </div>

  <!-- Official result -->
  <?php if ($cycle): ?>
  <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:4px">
    <div style="font-size:12px;font-weight:700;color:var(--text-2);margin-bottom:10px;text-transform:uppercase;letter-spacing:.6px">Resultado Oficial (para calcular pontos)</div>
    <form method="POST">
      <input type="hidden" name="acao" value="save_official">
      <input type="hidden" name="cycle_id" value="<?= $cycle['id'] ?>">
      <label class="f-label">Seeds JSON (array de 8 times, índice 0-3=Conf A, 4-7=Conf B)</label>
      <textarea class="f-input" name="seeds_json" rows="3" style="font-size:11px;resize:vertical" placeholder='[{"id":1,"name":"Team A",...},...]'><?= htmlspecialchars($official['seeds_json']??'') ?></textarea>
      <label class="f-label mt-2">Rounds JSON (resultados reais)</label>
      <textarea class="f-input" name="rounds_json" rows="4" style="font-size:11px;resize:vertical" placeholder='{"r1":[{"t1":{...},"t2":{...},"w":{...}},...],"r2":[...],"r3":[...]}'><?= htmlspecialchars($official['rounds_json']??'') ?></textarea>
      <button type="submit" class="btn-submit mt-2"><i class="bi bi-calculator me-1"></i>Salvar e Recalcular Pontos</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- Force reset -->
  <div style="border-top:1px solid var(--border);padding-top:16px;margin-top:16px">
    <form method="POST" onsubmit="return confirm('Resetar bracket <?= $lg ?>? Pontos serão pagos e novo ciclo criado.')">
      <input type="hidden" name="acao" value="force_reset">
      <input type="hidden" name="league" value="<?= htmlspecialchars($lg) ?>">
      <button type="submit" class="btn-reset"><i class="bi bi-arrow-counterclockwise me-1"></i>Forçar Reset <?= htmlspecialchars($lg) ?></button>
    </form>
  </div>
</div>
<?php endforeach; ?>

</div>
<script>
function setConf(btn, conf, teamId, lg) {
  document.querySelectorAll(`[id^="conf-${lg}-${teamId}"]`).forEach(el=>el.value=conf);
  const row = btn.closest('.conf-radio');
  row.querySelectorAll('.conf-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
}
</script>
</body>
</html>
