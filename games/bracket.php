<?php
session_start();
require 'core/conexao.php';
if (!isset($_SESSION['user_id'])) { header("Location: auth/login.php"); exit; }

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT nome, pontos, is_admin, fba_points, tapas_disponiveis, COALESCE(numero_tapas,0) as numero_tapas FROM usuarios WHERE id=:id");
$stmt->execute([':id' => $user_id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

// ── DB SETUP ──────────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_bracket_cycles (id INT AUTO_INCREMENT PRIMARY KEY, league VARCHAR(20) NOT NULL, cycle_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, status ENUM('open','closed') NOT NULL DEFAULT 'open', winner_user_id INT NULL, points_paid TINYINT(1) NOT NULL DEFAULT 0, INDEX idx_league (league, status))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_bracket_picks (id INT AUTO_INCREMENT PRIMARY KEY, cycle_id INT NOT NULL, user_id INT NOT NULL, seeds_json TEXT NOT NULL, rounds_json TEXT NOT NULL, points INT NOT NULL DEFAULT 0, locked TINYINT(1) NOT NULL DEFAULT 0, locked_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_cycle_user (cycle_id, user_id), INDEX idx_cycle (cycle_id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_bracket_official (id INT AUTO_INCREMENT PRIMARY KEY, cycle_id INT NOT NULL UNIQUE, seeds_json TEXT NULL, rounds_json TEXT NULL, updated_by INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_bracket_conferences (team_id INT NOT NULL, league VARCHAR(20) NOT NULL, conference CHAR(1) NOT NULL DEFAULT 'A', PRIMARY KEY (team_id, league))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_bracket_settings (league VARCHAR(20) NOT NULL PRIMARY KEY, picking_open TINYINT(1) NOT NULL DEFAULT 1)");
} catch (Exception $e) {}

$bracketSettings = [];
try {
    foreach ($pdo->query("SELECT league, picking_open FROM fba_bracket_settings")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $bracketSettings[$r['league']] = (int)$r['picking_open'];
} catch (Exception $e) {}

// ── AJAX ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['acao'])) {
    header('Content-Type: application/json');
    if ($_POST['acao'] === 'save_picks') {
        $cycle_id = (int)($_POST['cycle_id'] ?? 0);
        $seeds  = $_POST['seeds'] ?? ''; $rounds = $_POST['rounds'] ?? '';
        $lock   = !empty($_POST['lock']);
        $sa = @json_decode($seeds, true); $ra = @json_decode($rounds, true);
        if (!is_array($sa) || count($sa) !== 16 || !is_array($ra)) { echo json_encode(['ok'=>false,'msg'=>'Dados inválidos']); exit; }
        $stmt = $pdo->prepare("SELECT id,locked FROM fba_bracket_picks WHERE cycle_id=? AND user_id=?");
        $stmt->execute([$cycle_id, $user_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing && $existing['locked']) { echo json_encode(['ok'=>false,'msg'=>'Bracket já bloqueado']); exit; }
        if ($existing) $pdo->prepare("UPDATE fba_bracket_picks SET seeds_json=?,rounds_json=?,locked=?,locked_at=?,updated_at=NOW() WHERE id=?")->execute([$seeds,$rounds,$lock?1:0,$lock?date('Y-m-d H:i:s'):null,$existing['id']]);
        else $pdo->prepare("INSERT INTO fba_bracket_picks (cycle_id,user_id,seeds_json,rounds_json,locked,locked_at) VALUES (?,?,?,?,?,?)")->execute([$cycle_id,$user_id,$seeds,$rounds,$lock?1:0,$lock?date('Y-m-d H:i:s'):null]);
        echo json_encode(['ok'=>true]); exit;
    }
    exit;
}

// ── RESET / CYCLE ─────────────────────────────────────────────────────────────
$resetSchedule = ['ELITE'=>4,'NEXT'=>6,'RISE'=>3];
$excludeLeagues = ['ROOKIE'];

function lastResetTime(int $dow): DateTime {
    $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $c = clone $now; $c->setTime(23,59,0);
    for ($i=0;$i<8;$i++) { if ((int)$c->format('N')===$dow && $c<=$now) return $c; $c->modify('-1 day'); }
    return $c;
}
function closeCycleIfExpired(PDO $pdo, array $cycle, ?int $dow): void {
    if ($cycle['points_paid']||$cycle['status']==='closed'||!$dow) return;
    $lr = lastResetTime($dow);
    $cs = new DateTime($cycle['cycle_start'], new DateTimeZone('America/Sao_Paulo'));
    if ($cs >= $lr) return;
    $w = $pdo->prepare("SELECT user_id, MAX(points) as pts FROM fba_bracket_picks WHERE cycle_id=? AND locked=1 GROUP BY user_id ORDER BY pts DESC LIMIT 1");
    $w->execute([$cycle['id']]); $winner = $w->fetch(PDO::FETCH_ASSOC);
    if ($winner && (int)$winner['pts']>0) {
        $pdo->prepare("UPDATE usuarios SET fba_points=fba_points+100 WHERE id=?")->execute([$winner['user_id']]);
        $pdo->prepare("UPDATE fba_bracket_cycles SET status='closed',winner_user_id=?,points_paid=1 WHERE id=?")->execute([$winner['user_id'],$cycle['id']]);
    } else $pdo->prepare("UPDATE fba_bracket_cycles SET status='closed' WHERE id=?")->execute([$cycle['id']]);
}
function getOrCreateCycle(PDO $pdo, string $league, ?int $dow): array {
    $stmt = $pdo->prepare("SELECT * FROM fba_bracket_cycles WHERE league=? AND status='open' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$league]); $cycle = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cycle) { closeCycleIfExpired($pdo,$cycle,$dow); $stmt->execute([$league]); $cycle=$stmt->fetch(PDO::FETCH_ASSOC); }
    if (!$cycle) { $pdo->prepare("INSERT INTO fba_bracket_cycles (league,cycle_start,status) VALUES (?,NOW(),'open')")->execute([$league]); $id=(int)$pdo->lastInsertId(); $cycle=['id'=>$id,'league'=>$league,'status'=>'open','cycle_start'=>date('Y-m-d H:i:s'),'winner_user_id'=>null,'points_paid'=>0]; }
    return $cycle;
}
function calcPoints(array $pick, array $off): int {
    $pts=0; $us=@json_decode($pick['seeds_json'],true); $ur=@json_decode($pick['rounds_json'],true);
    $os=@json_decode($off['seeds_json'],true); $or_=@json_decode($off['rounds_json'],true);
    if (!is_array($us)||!is_array($os)) return 0;
    $cids=[];
    for ($i=0;$i<min(count($us),count($os));$i++) if(isset($us[$i]['id'],$os[$i]['id'])&&$us[$i]['id']==$os[$i]['id']){$pts++;$cids[]=(int)$us[$i]['id'];}
    if (!is_array($ur)||!is_array($or_)) return $pts;
    foreach (['r1','r2','r3','r4'] as $rnd) foreach(($ur[$rnd]??[]) as $i=>$um) { $om=$or_[$rnd][$i]??null; if(!$om) continue; $uw=$um['w']['id']??null; $ow=$om['w']['id']??null; if($uw&&$ow&&$uw==$ow&&in_array((int)$uw,$cids)) $pts+=2; }
    return $pts;
}

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
$leagues=[]; $teamsByLeague=[]; $confAssignments=[];
try { $rows=$pdo->query("SELECT team_id,league,conference FROM fba_bracket_conferences")->fetchAll(PDO::FETCH_ASSOC); foreach($rows as $r) $confAssignments[$r['league']][$r['team_id']]=$r['conference']; } catch(Exception $e){}
try {
    $pdoFba=new PDO('mysql:host=localhost;dbname=u289267434_fbabrasilbanco;charset=utf8mb4','u289267434_fbabrasilbanco','Fbabrasil@2025',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $rows=$pdoFba->query("SELECT id,name,city,league,photo_url,conference FROM teams ORDER BY league,name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $lg=$r['league']; if (in_array($lg,$excludeLeagues)) continue;
        if (!in_array($lg,$leagues)) $leagues[]=$lg;
        $conf = $confAssignments[$lg][(int)$r['id']] ?? null;
        if (!$conf && !empty($r['conference'])) {
            $conf = ($r['conference']==='LESTE') ? 'A' : (($r['conference']==='OESTE') ? 'B' : null);
        }
        $teamsByLeague[$lg][]=['id'=>(int)$r['id'],'name'=>$r['name'],'city'=>$r['city'],'photo_url'=>$r['photo_url']?:'','conference'=>$conf];
    }
} catch(Exception $e){}

$cycles=[]; $userPicks=[]; $rankings=[]; $officialResults=[];
foreach ($leagues as $lg) {
    $dow=$resetSchedule[$lg]??null;
    $cycles[$lg]=getOrCreateCycle($pdo,$lg,$dow);
    $cid=$cycles[$lg]['id'];
    $stmt=$pdo->prepare("SELECT * FROM fba_bracket_picks WHERE cycle_id=? AND user_id=?"); $stmt->execute([$cid,$user_id]); $pick=$stmt->fetch(PDO::FETCH_ASSOC);
    $stmt2=$pdo->prepare("SELECT * FROM fba_bracket_official WHERE cycle_id=?"); $stmt2->execute([$cid]); $official=$stmt2->fetch(PDO::FETCH_ASSOC)?:null;
    $officialResults[$lg]=$official;
    if ($pick&&$official) { $np=calcPoints($pick,$official); if($np!=(int)$pick['points']){$pdo->prepare("UPDATE fba_bracket_picks SET points=? WHERE id=?")->execute([$np,$pick['id']]);$pick['points']=$np;} }
    $userPicks[$lg]=$pick?:null;
    $stmt3=$pdo->prepare("SELECT p.user_id,u.nome,p.points,p.locked,p.locked_at FROM fba_bracket_picks p JOIN usuarios u ON u.id=p.user_id WHERE p.cycle_id=? ORDER BY p.points DESC,p.locked_at ASC LIMIT 20"); $stmt3->execute([$cid]); $rankings[$lg]=$stmt3->fetchAll(PDO::FETCH_ASSOC);
}

$dayNames=[1=>'Segunda',2=>'Terça',3=>'Quarta',4=>'Quinta',5=>'Sexta',6=>'Sábado',7=>'Domingo'];
$nextReset=[];
foreach ($leagues as $lg) {
    $dow=$resetSchedule[$lg]??null; if (!$dow){$nextReset[$lg]='';continue;}
    $d=new DateTime('now',new DateTimeZone('America/Sao_Paulo'));
    for ($i=0;$i<8;$i++) { if((int)$d->format('N')===$dow){$nextReset[$lg]=$dayNames[$dow].' às 23:59';break;} $d->modify('+1 day'); }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#fc0025">
<title>Bracket — FBA Games</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:rgba(252,0,37,.10);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;
--border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--text:#f0f0f3;--text-2:#868690;--text-3:#48484f;
--green:#22c55e;--amber:#f59e0b;--blue:#3b82f6;--font:'Poppins',sans-serif;--radius:14px;--radius-sm:10px;--ease:cubic-bezier(.2,.8,.2,1);--t:200ms;--sw:220px}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{background:var(--bg);color:var(--text);font-family:var(--font);-webkit-font-smoothing:antialiased}
.sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sw);background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:200;overflow-y:auto}
.sb-header{display:flex;align-items:center;gap:10px;padding:16px 14px 12px;border-bottom:1px solid var(--border)}
.sb-logo{width:30px;height:30px;border-radius:8px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;color:#fff;flex-shrink:0}
.sb-brand{font-weight:800;font-size:13px;color:var(--text)}.sb-brand span{color:var(--red)}
.sb-close{display:none;background:none;border:none;color:var(--text-2);font-size:18px;cursor:pointer;margin-left:auto}
.sb-user{padding:14px;border-bottom:1px solid var(--border)}
.sb-avatar{width:38px;height:38px;border-radius:50%;background:var(--red-soft);border:2px solid rgba(252,0,37,.2);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:var(--red);margin-bottom:7px}
.sb-user-name{font-size:12px;font-weight:700;color:#fff}.sb-user-role{font-size:10px;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-top:1px}
.sb-stats{padding:10px 14px;border-bottom:1px solid var(--border);display:flex;gap:5px}
.sb-stat{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;padding:7px 4px;background:var(--panel-2);border-radius:8px;border:1px solid var(--border)}
.sb-stat i{font-size:12px;color:var(--red)}.sb-stat-val{font-size:11px;font-weight:700;color:var(--text);line-height:1}.sb-stat-label{font-size:9px;color:var(--text-3)}
.sb-nav{flex:1;padding:8px 0}.sb-nav-section{font-size:9px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:8px 14px 4px}
.sb-link{display:flex;align-items:center;gap:10px;padding:9px 14px;text-decoration:none;font-size:12px;font-weight:500;color:var(--text-2);border-left:3px solid transparent;transition:all var(--t) var(--ease)}
.sb-link i{width:16px;text-align:center;font-size:13px}.sb-link:hover{background:var(--panel-2);color:var(--text);border-left-color:var(--border-md)}
.sb-link.active{background:var(--red-soft);color:var(--red);border-left-color:var(--red);font-weight:700}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border)}
.sb-logout{display:flex;align-items:center;gap:8px;width:100%;padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-2);text-decoration:none;font-size:12px;font-weight:600;transition:all var(--t) var(--ease)}
.sb-logout:hover{background:var(--red-soft);border-color:rgba(252,0,37,.2);color:var(--red)}
.page{margin-left:var(--sw);min-height:100vh;display:flex;flex-direction:column}
.topbar{height:52px;background:var(--panel);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;flex-shrink:0}
.topbar-title{font-size:15px;font-weight:800;color:var(--text)}.topbar-title span{color:var(--red)}
.mob-bar{display:none;height:52px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 14px;gap:10px;position:sticky;top:0;z-index:100}
.mob-ham{background:none;border:1px solid var(--border);border-radius:8px;color:var(--text);width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px}
.mob-title{font-size:14px;font-weight:800;color:var(--text);flex:1}.mob-title span{color:var(--red)}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:199;backdrop-filter:blur(2px)}.sb-overlay.open{display:block}
.main{flex:1;padding:20px 24px 60px;max-width:1000px;margin:0 auto;width:100%}
.tab-bar{display:flex;gap:4px;background:var(--panel-2);border:1px solid var(--border);border-radius:999px;padding:3px;margin:0 auto 24px;width:fit-content}
.tab-btn{padding:7px 22px;border-radius:999px;border:none;background:transparent;color:var(--text-2);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease)}
.tab-btn.active{background:var(--red);color:#fff;box-shadow:0 2px 12px rgba(252,0,37,.3)}
.reset-info{display:inline-flex;align-items:center;gap:7px;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:7px 14px;font-size:11px;color:var(--text-2);margin-bottom:18px}
.reset-info i{color:var(--amber)}
.phase-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px;gap:10px;flex-wrap:wrap}
.phase-title{font-size:16px;font-weight:800;color:var(--text)}.phase-title span{color:var(--red)}
.phase-sub{font-size:12px;color:var(--text-2);margin-top:2px}
.btn-ghost{background:transparent;border:1px solid var(--border-md);border-radius:var(--radius-sm);padding:7px 14px;color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease);display:flex;align-items:center;gap:6px}
.btn-ghost:hover{border-color:rgba(239,68,68,.4);color:#f87171;background:rgba(239,68,68,.07)}
/* Conf wrap */
.conf-wrap{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.conf-section{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:14px}
.conf-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.conf-label.a{color:var(--blue)}.conf-label.b{color:var(--amber)}
.teams-grid{display:flex;flex-direction:column;gap:4px}
.team-card{background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:9px 12px;display:flex;align-items:center;gap:10px;cursor:pointer;transition:all var(--t) var(--ease);user-select:none}
.team-card:hover{border-color:var(--border-md)}.team-card.sel-a{border-color:var(--blue);background:rgba(59,130,246,.08)}.team-card.sel-b{border-color:var(--amber);background:rgba(245,158,11,.08)}.team-card.full{opacity:.35;pointer-events:none}
.seed-badge{width:22px;height:22px;border-radius:50%;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:var(--panel-3);border:1px solid var(--border);color:var(--text-3)}
.seed-badge.a{background:var(--blue);border-color:var(--blue);color:#fff}.seed-badge.b{background:var(--amber);border-color:var(--amber);color:#fff}
.t-logo{width:40px;height:40px;border-radius:50%;object-fit:cover;background:var(--panel-3)}
.t-logo-ph{width:40px;height:40px;border-radius:50%;background:var(--panel-3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:var(--text-3)}
.t-name{font-size:12px;font-weight:600;color:var(--text);line-height:1.3}
/* Seeds strip */
.seeds-wrap{margin-bottom:14px}
.seeds-conf-row{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:6px}
.seed-slot{display:flex;align-items:center;gap:4px;padding:4px 8px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:7px;min-width:0}
.snum{font-size:9px;font-weight:800;white-space:nowrap}.snum.a{color:var(--blue)}.snum.b{color:var(--amber)}
.slogo{width:18px;height:18px;border-radius:50%;object-fit:cover;flex-shrink:0}
.sname{font-size:10px;font-weight:600;color:var(--text);max-width:65px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* Btn start */
.btn-start{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;max-width:340px;padding:12px;background:var(--red);color:#fff;border:none;border-radius:var(--radius-sm);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:opacity var(--t) var(--ease);margin-bottom:18px}
.btn-start:hover{opacity:.85}.btn-start:disabled{opacity:.3;cursor:not-allowed}
/* Bracket */
.bracket-wrap{display:flex;flex-direction:column;gap:14px}
.round-block{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.round-head{padding:9px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px}
.round-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2)}
.round-grid{display:grid;background:var(--border)}
.round-grid.g4{grid-template-columns:1fr 1fr 1fr 1fr}.round-grid.g2{grid-template-columns:1fr 1fr}.round-grid.g1{grid-template-columns:1fr}
.matchup{background:var(--panel)}
.conf-tag{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:5px 12px 2px;display:block}
.conf-tag.a{color:var(--blue)}.conf-tag.b{color:var(--amber)}
.m-team{display:flex;align-items:center;gap:9px;padding:9px 12px;cursor:pointer;transition:background var(--t) var(--ease);border-left:3px solid transparent}
.m-team:not(.locked):not(.tbd):hover{background:var(--panel-2)}.m-team+.m-team{border-top:1px solid var(--border)}
.m-team.winner{background:rgba(34,197,94,.07);border-left-color:var(--green)}.m-team.loser{opacity:.3}
.m-team.correct{background:rgba(34,197,94,.1);border-left-color:var(--green)}.m-team.wrong{opacity:.35}.m-team.locked,.m-team.tbd{cursor:default}
.m-logo{width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0;background:var(--panel-2)}
.m-logo-ph{width:28px;height:28px;border-radius:50%;background:var(--panel-3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:8px;font-weight:800;color:var(--text-3);flex-shrink:0}
.m-info{flex:1;min-width:0}.m-seed{font-size:9px;color:var(--text-3);font-weight:600}.m-name{font-size:11px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.m-check{margin-left:auto;color:var(--green);font-size:14px;flex-shrink:0}
/* Action bar */
.action-bar{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;align-items:center;justify-content:center}
.btn-save{display:flex;align-items:center;gap:7px;padding:11px 22px;background:var(--green);color:#000;border:none;border-radius:var(--radius-sm);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:opacity var(--t) var(--ease)}
.btn-save:hover{opacity:.85}.btn-save:disabled{opacity:.35;cursor:not-allowed}
.btn-share{display:flex;align-items:center;gap:7px;padding:11px 22px;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);color:var(--blue);border-radius:var(--radius-sm);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:all var(--t) var(--ease)}
.btn-share:hover{background:rgba(59,130,246,.18)}
.btn-oficial{display:flex;align-items:center;gap:7px;padding:11px 22px;background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.25);color:var(--blue);border-radius:var(--radius-sm);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:all var(--t) var(--ease)}
.btn-oficial:hover{background:rgba(59,130,246,.18)}
.locked-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);color:var(--green);padding:7px 14px;border-radius:var(--radius-sm);font-size:12px;font-weight:700}
.pts-badge{display:inline-flex;align-items:center;gap:5px;background:var(--panel-3);border:1px solid var(--border-md);border-radius:999px;padding:5px 12px;font-size:12px;font-weight:700;color:var(--amber)}
/* Champion */
.champ-wrap{margin-top:14px;display:flex;justify-content:center}
.champ-card{display:inline-flex;flex-direction:column;align-items:center;gap:8px;background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(252,0,37,.08));border:1px solid rgba(245,158,11,.3);border-radius:var(--radius);padding:22px 32px}
.champ-crown{font-size:26px}.champ-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--amber)}
.champ-logo{width:60px;height:60px;border-radius:50%;object-fit:cover;border:3px solid var(--amber);background:var(--panel-2)}
.champ-name{font-size:18px;font-weight:800;color:#fff}.champ-city{font-size:11px;color:var(--text-2)}
/* Ranking */
.ranking-block{margin-top:28px;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.ranking-head{padding:12px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.rank-row{display:flex;align-items:center;gap:10px;padding:9px 16px;transition:background var(--t) var(--ease)}
.rank-row+.rank-row{border-top:1px solid var(--border)}.rank-row:hover{background:var(--panel-2)}
.rank-pos{font-size:13px;font-weight:800;color:var(--text-3);width:22px;text-align:center;flex-shrink:0}
.rank-pos.p1{color:var(--amber)}.rank-pos.p2{color:#94a3b8}.rank-pos.p3{color:#cd7f32}
.rank-name{flex:1;font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rank-pts{font-size:13px;font-weight:800;color:var(--amber)}.rank-lock{color:var(--green);font-size:11px;margin-left:3px}
.rank-empty{padding:20px 16px;text-align:center;font-size:12px;color:var(--text-3)}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1000;overflow-y:auto;backdrop-filter:blur(3px);padding:20px}
.modal-overlay.open{display:flex;align-items:flex-start;justify-content:center}
.modal-box{background:var(--panel);border:1px solid var(--border-md);border-radius:var(--radius);max-width:820px;width:100%;padding:20px;position:relative}
.modal-close{position:absolute;top:14px;right:14px;background:var(--panel-2);border:1px solid var(--border);border-radius:8px;color:var(--text-2);width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px}
.modal-close:hover{color:var(--text);background:var(--panel-3)}
.modal-title{font-size:15px;font-weight:800;color:var(--text);margin-bottom:16px}
/* Toast */
.toast{position:fixed;bottom:24px;right:24px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);padding:12px 18px;font-size:13px;font-weight:600;color:var(--text);z-index:9999;opacity:0;transform:translateY(10px);transition:all .3s;pointer-events:none}
.toast.show{opacity:1;transform:translateY(0)}.toast.green{border-color:rgba(34,197,94,.3);color:var(--green)}.toast.red{border-color:rgba(252,0,37,.3);color:#ff6680}
/* ── Tree bracket (desktop) ── */
@media(min-width:769px){
  .brk-tree{display:flex;align-items:stretch;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;overflow-x:auto}
  .brk-col{display:flex;flex-direction:column;min-width:150px;flex:1;overflow:hidden}
  .brk-col:not(:last-child){border-right:1px solid var(--border)}
  .brk-col-head{flex-shrink:0;height:34px;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2);background:var(--panel-2);border-bottom:1px solid var(--border);white-space:nowrap;padding:0 8px}
  .brk-col-body{flex:1;display:flex;flex-direction:column}
  .brk-m{flex:1;display:flex;align-items:center;padding:4px 6px;border-bottom:1px solid var(--border);min-height:0}
  .brk-m:last-child{border-bottom:none}
  .brk-m .matchup{width:100%;background:var(--panel-2);border:1px solid var(--border);border-radius:8px;overflow:hidden}
  .brk-tree .conf-tag{display:none}
  .brk-tree .m-team{padding:6px 8px}
  .brk-tree .m-logo,.brk-tree .m-logo-ph{width:22px!important;height:22px!important}
  .brk-tree .m-name{font-size:10px}
  .brk-tree .m-seed{font-size:8px}
  /* Connectors */
  .brk-conns{width:24px;flex-shrink:0;display:flex;flex-direction:column;padding-top:34px;background:var(--panel)}
  .brk-conn{flex:1;position:relative}
  .brk-conn::before{content:'';position:absolute;left:0;top:25%;width:12px;height:50%;border-top:1px solid var(--border-md);border-right:1px solid var(--border-md);border-bottom:1px solid var(--border-md)}
  .brk-conn::after{content:'';position:absolute;left:12px;top:50%;right:0;height:1px;background:var(--border-md)}
  /* Champion column */
  .brk-col-champ{flex:0 0 110px;min-width:90px}
  .brk-champ-mini{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:14px 8px;text-align:center;height:100%;background:linear-gradient(135deg,rgba(245,158,11,.07),rgba(252,0,37,.05))}
  .brk-champ-mini img{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--amber)}
  .brk-champ-mini .cn{font-size:11px;font-weight:800;color:var(--amber)}
  .brk-champ-mini .cc{font-size:9px;color:var(--text-2)}
}
@media(max-width:768px){
  html,body{height:auto}.sidebar{transform:translateX(-100%);transition:transform 280ms var(--ease)}.sidebar.open{transform:translateX(0)}
  .sb-close{display:flex}.page{margin-left:0}.topbar{display:none}.mob-bar{display:flex}
  .main{padding:14px 12px 60px}.conf-wrap{grid-template-columns:1fr}.round-grid.g4{grid-template-columns:1fr 1fr}.round-grid.g2{grid-template-columns:1fr}
  .tab-btn{padding:7px 12px;font-size:12px}
}
</style>
</head>
<body>
<div class="toast" id="toast"></div>

<!-- Modal oficial -->
<div class="modal-overlay" id="modalOficial">
  <div class="modal-box">
    <button class="modal-close" onclick="closeModal()"><i class="bi bi-x-lg"></i></button>
    <div class="modal-title">📋 Bracket Oficial — <span id="modalLgLabel"></span></div>
    <div id="modalContent"></div>
  </div>
</div>

<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sb-header"><div class="sb-logo">FBA</div><span class="sb-brand">FBA <span>Games</span></span><button class="sb-close" onclick="closeSidebar()"><i class="bi bi-x-lg"></i></button></div>
  <div class="sb-user">
    <div class="sb-avatar"><?= strtoupper(substr($usuario['nome']??'U',0,1)) ?></div>
    <div class="sb-user-name"><?= htmlspecialchars($usuario['nome']??'') ?></div>
    <div class="sb-user-role"><?= !empty($usuario['is_admin'])?'Admin':'Jogador' ?></div>
  </div>
  <div class="sb-stats">
    <div class="sb-stat"><i class="bi bi-hand-index-fill" style="color:var(--green)"></i><div class="sb-stat-val"><?= number_format($usuario['numero_tapas']??0,0,',','.') ?></div><div class="sb-stat-label">Tapas</div></div>
    <div class="sb-stat"><img src="moeda.png" style="width:18px;height:18px;object-fit:contain"><div class="sb-stat-val"><?= number_format($usuario['pontos']??0,0,',','.') ?></div><div class="sb-stat-label">Moedas</div></div>
    <div class="sb-stat"><img src="lebron.png" style="width:18px;height:18px;object-fit:contain"><div class="sb-stat-val"><?= number_format($usuario['fba_points']??0,0,',','.') ?></div><div class="sb-stat-label">FBA Pts</div></div>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-section">Menu</div>
    <a href="index.php" class="sb-link"><i class="bi bi-lightning-charge"></i>Apostas</a>
    <a href="games.php" class="sb-link"><i class="bi bi-joystick"></i>Games</a>
    <a href="bracket.php" class="sb-link active"><i class="bi bi-trophy-fill"></i>Bracket</a>
    <a href="user/ranking-geral.php" class="sb-link"><i class="bi bi-bar-chart-fill"></i>Ranking Geral</a>
    <?php if (!empty($usuario['is_admin'])): ?>
    <div class="sb-nav-section">Admin</div>
    <a href="admin/controlegames.php" class="sb-link"><i class="bi bi-gear-fill"></i>Controle de Jogos</a>
    <a href="admin/dashboard.php" class="sb-link"><i class="bi bi-receipt-cutoff"></i>Controle Apostas</a>
    <a href="admin/bracket-admin.php" class="sb-link"><i class="bi bi-trophy"></i>Admin Bracket</a>
    <?php endif; ?>
  </nav>
  <div class="sb-footer"><a href="auth/logout.php" class="sb-logout"><i class="bi bi-box-arrow-right"></i>Sair</a></div>
</aside>

<div class="page">
  <div class="topbar"><div class="topbar-title">Bracket <span>Playoffs</span></div></div>
  <div class="mob-bar">
    <button class="mob-ham" onclick="openSidebar()"><i class="bi bi-list"></i></button>
    <span class="mob-title">Bracket <span>Playoffs</span></span>
  </div>
  <div class="main">
    <?php if (empty($leagues)): ?>
    <div style="text-align:center;padding:48px 20px;color:var(--text-3)"><i class="bi bi-exclamation-circle" style="font-size:32px;display:block;margin-bottom:10px"></i><p>Nenhuma liga encontrada.</p></div>
    <?php else: ?>
    <div class="tab-bar">
      <?php foreach ($leagues as $i=>$lg): ?>
      <button class="tab-btn <?= $i===0?'active':'' ?>" onclick="switchTab('<?= $lg ?>')" id="tab-<?= $lg ?>"><?= htmlspecialchars($lg) ?></button>
      <?php endforeach; ?>
    </div>

    <?php foreach ($leagues as $i=>$lg):
      $cycle=$cycles[$lg]; $myPick=$userPicks[$lg]; $ranking=$rankings[$lg];
      $isLocked=$myPick&&$myPick['locked'];
      $official=$officialResults[$lg];
      $isPickingOpen=($bracketSettings[$lg]??1)===1;
    ?>
    <div class="tab-panel" id="panel-<?= $lg ?>" style="display:<?= $i===0?'block':'none' ?>">
      <?php if (!empty($nextReset[$lg])): ?>
      <div class="reset-info"><i class="bi bi-arrow-repeat"></i>Reset: <strong><?= htmlspecialchars($nextReset[$lg]) ?></strong></div>
      <?php endif; ?>

      <?php if ($isLocked): ?>
      <div class="phase-header">
        <div><div class="phase-title">Seu Bracket <span><?= htmlspecialchars($lg) ?></span></div><div class="phase-sub">Salvo <?= $myPick['locked_at']?'em '.date('d/m H:i',strtotime($myPick['locked_at'])):'' ?></div></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <span class="locked-badge"><i class="bi bi-lock-fill"></i>Bloqueado</span>
          <?php if ((int)$myPick['points']>0): ?><span class="pts-badge"><i class="bi bi-star-fill"></i><?= $myPick['points'] ?> pts</span><?php endif; ?>
        </div>
      </div>
      <div id="seeds-strip-<?= $lg ?>" class="seeds-wrap"></div>
      <div id="bracket-locked-<?= $lg ?>"></div>
      <div class="action-bar">
        <button class="btn-share" onclick="shareBracket('<?= $lg ?>')"><i class="bi bi-clipboard"></i>Copiar</button>
        <?php if ($official&&($official['seeds_json']||$official['rounds_json'])): ?>
        <button class="btn-oficial" onclick="openOficial('<?= $lg ?>')"><i class="bi bi-eye-fill"></i>Ver Oficial</button>
        <?php endif; ?>
      </div>
      <?php elseif (!$isPickingOpen): ?>
      <div style="text-align:center;padding:32px 20px">
        <div style="font-size:32px;margin-bottom:12px">🏀</div>
        <div style="font-size:17px;font-weight:800;color:var(--text);margin-bottom:6px">Simulação já começou</div>
        <div style="font-size:13px;color:var(--text-2)">As picks para a liga <?= htmlspecialchars($lg) ?> estão encerradas.</div>
        <?php if ($official&&($official['seeds_json']||$official['rounds_json'])): ?>
        <div style="margin-top:16px"><button class="btn-oficial" onclick="openOficial('<?= $lg ?>')"><i class="bi bi-eye-fill"></i>Ver Bracket Oficial</button></div>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="phase-header">
        <div><div class="phase-title" id="phaseTitle-<?= $lg ?>">Montar <span>Bracket</span></div><div class="phase-sub" id="phaseSub-<?= $lg ?>">Selecione 8 seeds de cada conferência</div></div>
        <button class="btn-ghost" onclick="resetBracket('<?= $lg ?>')"><i class="bi bi-arrow-counterclockwise"></i>Resetar</button>
      </div>
      <div id="pickingPhase-<?= $lg ?>">
        <div class="conf-wrap" id="confWrap-<?= $lg ?>"></div>
        <button class="btn-start" id="btnStart-<?= $lg ?>" disabled onclick="startBracket('<?= $lg ?>')"><i class="bi bi-play-fill"></i>Gerar Bracket</button>
      </div>
      <div id="bracketPhase-<?= $lg ?>" style="display:none">
        <div class="bracket-wrap" id="bracketWrap-<?= $lg ?>"></div>
        <div class="action-bar">
          <button class="btn-save" id="btnSave-<?= $lg ?>" disabled onclick="saveBracket('<?= $lg ?>')"><i class="bi bi-lock-fill"></i>Salvar e Bloquear</button>
          <button class="btn-share" id="btnShare-<?= $lg ?>" style="display:none" onclick="shareBracket('<?= $lg ?>')"><i class="bi bi-clipboard"></i>Copiar</button>
          <?php if ($official&&($official['seeds_json']||$official['rounds_json'])): ?>
          <button class="btn-oficial" onclick="openOficial('<?= $lg ?>')"><i class="bi bi-eye-fill"></i>Ver Oficial</button>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="ranking-block">
        <div class="ranking-head"><i class="bi bi-bar-chart-fill" style="color:var(--amber)"></i>Ranking — <?= htmlspecialchars($lg) ?></div>
        <?php if (empty($ranking)): ?><div class="rank-empty">Nenhum bracket salvo ainda.</div>
        <?php else: foreach ($ranking as $ri=>$rrow): $rp=$ri+1; ?>
        <div class="rank-row">
          <div class="rank-pos <?= $rp===1?'p1':($rp===2?'p2':($rp===3?'p3':'')) ?>"><?= $rp ?>º</div>
          <div class="rank-name"><?= htmlspecialchars($rrow['nome']) ?><?= $rrow['user_id']==$user_id?' (você)':'' ?></div>
          <div class="rank-pts"><?= $rrow['points'] ?> pts</div>
          <?php if ($rrow['locked']): ?><i class="bi bi-lock-fill rank-lock"></i><?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
const TEAMS_BY_LEAGUE = <?= json_encode($teamsByLeague, JSON_UNESCAPED_UNICODE) ?>;
// DEBUG temporário - remover depois
console.log('DEBUG photo_url NEXT:', (TEAMS_BY_LEAGUE['NEXT']||[]).slice(0,3).map(t=>({name:t.name,photo:t.photo_url})));
const LEAGUES = <?= json_encode($leagues, JSON_UNESCAPED_UNICODE) ?>;
const CYCLES  = <?= json_encode(array_combine($leagues, array_map(fn($lg)=>['id'=>$cycles[$lg]['id']], $leagues)), JSON_UNESCAPED_UNICODE) ?>;
const USER_PICKS_RAW = <?= json_encode(array_combine($leagues, array_map(fn($lg)=>$userPicks[$lg]?['seeds'=>$userPicks[$lg]['seeds_json'],'rounds'=>$userPicks[$lg]['rounds_json'],'locked'=>(bool)$userPicks[$lg]['locked'],'points'=>(int)$userPicks[$lg]['points']]:null, $leagues)), JSON_UNESCAPED_UNICODE) ?>;
const OFFICIAL_RAW = <?= json_encode(array_combine($leagues, array_map(fn($lg)=>$officialResults[$lg]?['seeds'=>$officialResults[$lg]['seeds_json']??'','rounds'=>$officialResults[$lg]['rounds_json']??'']:null, $leagues)), JSON_UNESCAPED_UNICODE) ?>;

// Advancement map: round → matchIdx → {round, idx, slot}
const ADV = {
  r1:{0:{r:'r2',i:0,s:'t1'},1:{r:'r2',i:0,s:'t2'},2:{r:'r2',i:1,s:'t1'},3:{r:'r2',i:1,s:'t2'},
      4:{r:'r2',i:2,s:'t1'},5:{r:'r2',i:2,s:'t2'},6:{r:'r2',i:3,s:'t1'},7:{r:'r2',i:3,s:'t2'}},
  r2:{0:{r:'r3',i:0,s:'t1'},1:{r:'r3',i:0,s:'t2'},2:{r:'r3',i:1,s:'t1'},3:{r:'r3',i:1,s:'t2'}},
  r3:{0:{r:'r4',i:0,s:'t1'},1:{r:'r4',i:0,s:'t2'}}
};

function lsKey(lg){return 'fba_brk_v4_'+lg+'_c'+(CYCLES[lg]&&CYCLES[lg].id||0);}
function loadLS(lg){try{return JSON.parse(localStorage.getItem(lsKey(lg)))||null;}catch(e){return null;}}
function saveLS(lg,s){localStorage.setItem(lsKey(lg),JSON.stringify(s));}
function clearLS(lg){localStorage.removeItem(lsKey(lg));}

function showToast(msg,type=''){const el=document.getElementById('toast');el.textContent=msg;el.className='toast show'+(type?' '+type:'');clearTimeout(el._t);el._t=setTimeout(()=>el.classList.remove('show'),2500);}

function switchTab(lg){LEAGUES.forEach(l=>{document.getElementById('panel-'+l).style.display=l===lg?'block':'none';document.getElementById('tab-'+l).classList.toggle('active',l===lg);});}

// ── Logo HTML ─────────────────────────────────────────────────────────────────
function logoHtml(t,sz=28){
  if(!t)return`<div class="m-logo-ph" style="width:${sz}px;height:${sz}px">?</div>`;
  if(t.photo_url)return`<img class="m-logo" src="${t.photo_url}" style="width:${sz}px;height:${sz}px" onerror="this.outerHTML='<div class=\\'m-logo-ph\\'style=\\'width:${sz}px;height:${sz}px\\'>${(t.name||'?').slice(0,2).toUpperCase()}</div>'">`;
  return`<div class="m-logo-ph" style="width:${sz}px;height:${sz}px">${(t.name||'?').slice(0,2).toUpperCase()}</div>`;
}

// ── Seeds strip ───────────────────────────────────────────────────────────────
function renderSeedsStrip(lg,sA,sB){
  const el=document.getElementById('seeds-strip-'+lg);if(!el)return;
  const row=(seeds,conf)=>seeds.map((t,i)=>`<div class="seed-slot">
    <span class="snum ${conf}">#${i+1}${conf.toUpperCase()}</span>
    ${t&&t.photo_url?`<img class="slogo" src="${t.photo_url}" onerror="this.style.display='none'">`:''}
    <span class="sname">${t?t.name:'—'}</span>
  </div>`).join('');
  el.innerHTML=`<div class="seeds-conf-row">${row(Array.from({length:8},(_,i)=>sA[i]||null),'a')}</div><div class="seeds-conf-row">${row(Array.from({length:8},(_,i)=>sB[i]||null),'b')}</div>`;
}

// ── Conf grid ─────────────────────────────────────────────────────────────────
function renderConfWrap(lg,sA,sB){
  const el=document.getElementById('confWrap-'+lg);if(!el)return;
  const teams=TEAMS_BY_LEAGUE[lg]||[];
  const hasConf=teams.some(t=>t.conference);
  const tA=hasConf?teams.filter(t=>t.conference==='A'):teams.slice(0,Math.ceil(teams.length/2));
  const tB=hasConf?teams.filter(t=>t.conference==='B'):teams.slice(Math.ceil(teams.length/2));
  const idsA=sA.filter(Boolean).map(t=>t.id);
  const idsB=sB.filter(Boolean).map(t=>t.id);
  const card=(t,conf,selArr)=>{
    const idx=selArr.indexOf(t.id);const sel=idx!==-1;
    const full=(conf==='A'?idsA:idsB).length>=8&&idx===-1;
    return`<div class="team-card${sel?' sel-'+conf.toLowerCase():''}${full?' full':''}" onclick="toggleSeed('${lg}','${conf}',${t.id})">
      <div class="seed-badge ${sel?conf.toLowerCase():''}">${sel?idx+1:''}</div>
      <div class="t-name">${(t.city?t.city+' ':'')+t.name}</div>
    </div>`;
  };
  const sortBySeeds=(teams,ids)=>[...teams].sort((a,b)=>{const ia=ids.indexOf(a.id),ib=ids.indexOf(b.id);if(ia!==-1&&ib!==-1)return ia-ib;if(ia!==-1)return-1;if(ib!==-1)return 1;return 0;});
  el.innerHTML=`<div class="conf-section"><div class="conf-label a"><i class="bi bi-shield-fill"></i>LESTE <span style="color:var(--text-3);font-weight:400">${idsA.length}/8</span></div><div class="teams-grid">${sortBySeeds(tA,idsA).map(t=>card(t,'A',idsA)).join('')}</div></div>
  <div class="conf-section"><div class="conf-label b"><i class="bi bi-shield-fill"></i>OESTE <span style="color:var(--text-3);font-weight:400">${idsB.length}/8</span></div><div class="teams-grid">${sortBySeeds(tB,idsB).map(t=>card(t,'B',idsB)).join('')}</div></div>`;
  const btn=document.getElementById('btnStart-'+lg);if(btn)btn.disabled=idsA.length<8||idsB.length<8;
}

// ── Toggle seed ───────────────────────────────────────────────────────────────
function toggleSeed(lg,conf,teamId){
  const state=loadLS(lg)||{phase:'picking',seedsA:[],seedsB:[],rounds:null};
  if(state.phase!=='picking')return;
  const teams=TEAMS_BY_LEAGUE[lg]||[];const team=teams.find(t=>t.id===teamId);if(!team)return;
  const arr=conf==='A'?state.seedsA:state.seedsB;
  const idx=arr.findIndex(t=>t&&t.id===teamId);
  if(idx!==-1)arr.splice(idx,1);else{if(arr.length>=8)return;arr.push(team);}
  saveLS(lg,state);renderConfWrap(lg,state.seedsA,state.seedsB);
}

// ── Start bracket ─────────────────────────────────────────────────────────────
function buildRounds(sA,sB){
  const e=()=>({t1:null,t2:null,w:null});
  return{
    r1:[{t1:sA[0],t2:sA[7],w:null},{t1:sA[3],t2:sA[4],w:null},{t1:sA[1],t2:sA[6],w:null},{t1:sA[2],t2:sA[5],w:null},
        {t1:sB[0],t2:sB[7],w:null},{t1:sB[3],t2:sB[4],w:null},{t1:sB[1],t2:sB[6],w:null},{t1:sB[2],t2:sB[5],w:null}],
    r2:[e(),e(),e(),e()],r3:[e(),e()],r4:[e()]
  };
}
function startBracket(lg){
  const state=loadLS(lg)||{};const sA=state.seedsA||[],sB=state.seedsB||[];
  if(sA.length<8||sB.length<8)return;
  state.rounds=buildRounds(sA,sB);state.phase='bracket';saveLS(lg,state);
  document.getElementById('pickingPhase-'+lg).style.display='none';
  document.getElementById('bracketPhase-'+lg).style.display='block';
  document.getElementById('phaseTitle-'+lg).innerHTML='Bracket <span>Playoffs</span>';
  document.getElementById('phaseSub-'+lg).textContent='Clique no vencedor de cada confronto';
  renderBracket(lg,state);
}

// ── Pick winner ───────────────────────────────────────────────────────────────
function clearDownstream(rounds,round,idx){
  const adv=ADV[round]&&ADV[round][idx];if(!adv)return;
  const next=rounds[adv.r]&&rounds[adv.r][adv.i];if(!next)return;
  next[adv.s]=null;
  if(next.w){next.w=null;clearDownstream(rounds,adv.r,adv.i);}
}
function pickWinner(lg,round,idx,teamId){
  const state=loadLS(lg);if(!state||state.phase!=='bracket')return;
  const match=state.rounds[round][idx];if(!match.t1||!match.t2)return;
  const winner=[match.t1,match.t2].find(t=>t.id===teamId);if(!winner)return;
  if(match.w&&match.w.id===winner.id)return;
  if(match.w)clearDownstream(state.rounds,round,idx);
  match.w=winner;
  const adv=ADV[round]&&ADV[round][idx];
  if(adv){state.rounds[adv.r][adv.i][adv.s]=winner;}
  saveLS(lg,state);renderBracket(lg,state);autoSave(lg,state);
}

// ── Official comparison ───────────────────────────────────────────────────────
function getOfficial(lg){
  const raw=OFFICIAL_RAW[lg];if(!raw)return null;
  try{return{seeds:raw.seeds?JSON.parse(raw.seeds):null,rounds:raw.rounds?JSON.parse(raw.rounds):null};}catch(e){return null;}
}
function matchStatus(official,round,idx,winner){
  if(!official||!official.rounds)return'pending';
  const om=official.rounds[round]&&official.rounds[round][idx];
  if(!om||!om.w)return'pending';if(!winner)return'pending';
  return winner.id===om.w.id?'correct':'wrong';
}

// ── Render bracket ────────────────────────────────────────────────────────────
function matchupHtml(lg,round,idx,match,confTag,official,locked){
  const done=!!match.w;
  const row=(team,isWin)=>{
    if(!team)return`<div class="m-team tbd">${logoHtml(null,28)}<div class="m-info"><div class="m-name" style="color:var(--text-3);font-style:italic">A definir</div></div></div>`;
    let cls=''; const st=matchStatus(official,round,idx,team.id===match.w?.id?match.w:null);
    if(locked){cls=isWin?(st==='correct'?'winner correct':st==='wrong'?'winner wrong':'winner'):(done?'loser':'');}
    else{cls=done?(isWin?'winner':'loser'):'';}
    const onclick=!locked?`pickWinner('${lg}','${round}',${idx},${team.id})`:'';
    return`<div class="m-team ${cls} ${locked?'locked':''}" ${onclick?`onclick="${onclick}"`:''}>
      ${logoHtml(team,28)}<div class="m-info"><div class="m-seed">${team.city||''}</div><div class="m-name">${team.name}</div></div>
      ${isWin?'<i class="bi bi-check-circle-fill m-check"></i>':''}
    </div>`;
  };
  const w1=match.w&&match.t1&&match.w.id===match.t1.id,w2=match.w&&match.t2&&match.w.id===match.t2.id;
  return`<div class="matchup">${confTag?`<span class="conf-tag ${confTag.toLowerCase()}">${confTag==='A'?'Conf A':'Conf B'}</span>`:''}<div>${row(match.t1,w1)}${row(match.t2,w2)}</div></div>`;
}

function renderBracket(lg,state,locked=false,targetId){
  const wrapId=targetId||('bracketWrap-'+lg);const wrap=document.getElementById(wrapId);if(!wrap)return;
  const r=state.rounds;const official=getOfficial(lg);
  const champ=r.r4&&r.r4[0]&&r.r4[0].w;
  const mh=(round,idx,match,tag)=>matchupHtml(lg,round,idx,match,tag,official,locked);

  if(window.innerWidth>768){
    // ── Desktop: tree layout ──
    let h='<div class="brk-tree">';
    // R1
    h+=`<div class="brk-col"><div class="brk-col-head">1ª Rodada</div><div class="brk-col-body">`;
    r.r1.forEach((m,i)=>h+=`<div class="brk-m">${mh('r1',i,m,i<4?'A':'B')}</div>`);
    h+=`</div></div>`;
    // Conn R1→R2
    h+=`<div class="brk-conns">`;for(let i=0;i<4;i++)h+=`<div class="brk-conn"></div>`;h+=`</div>`;
    // R2
    h+=`<div class="brk-col"><div class="brk-col-head">Quartas</div><div class="brk-col-body">`;
    r.r2.forEach((m,i)=>h+=`<div class="brk-m">${mh('r2',i,m,i<2?'A':'B')}</div>`);
    h+=`</div></div>`;
    // Conn R2→R3
    h+=`<div class="brk-conns">`;for(let i=0;i<2;i++)h+=`<div class="brk-conn"></div>`;h+=`</div>`;
    // R3
    h+=`<div class="brk-col"><div class="brk-col-head">Finais de Conf</div><div class="brk-col-body">`;
    r.r3.forEach((m,i)=>h+=`<div class="brk-m">${mh('r3',i,m,i===0?'A':'B')}</div>`);
    h+=`</div></div>`;
    // Conn R3→R4
    h+=`<div class="brk-conns"><div class="brk-conn"></div></div>`;
    // R4
    h+=`<div class="brk-col"><div class="brk-col-head">🏆 Final</div><div class="brk-col-body"><div class="brk-m">${mh('r4',0,r.r4[0],null)}</div></div></div>`;
    // Champion
    if(champ){h+=`<div class="brk-col brk-col-champ"><div class="brk-col-head">👑</div><div class="brk-col-body"><div class="brk-m"><div class="brk-champ-mini">🏆${champ.photo_url?`<img src="${champ.photo_url}" onerror="this.style.display='none'">`:''}<div class="cn">${champ.name}</div><div class="cc">${champ.city||''}</div></div></div></div></div>`;}
    h+='</div>';
    wrap.innerHTML=h;
  } else {
    // ── Mobile: vertical cards ──
    const allR1=r.r1.every(m=>m.w),allR2=r.r2.every(m=>m.w),allR3=r.r3.every(m=>m.w);
    let h=`<div class="round-block"><div class="round-head"><span class="round-label">Primeira Rodada</span></div>
      <div class="round-grid g4">
        ${[0,1,2,3].map(i=>mh('r1',i,r.r1[i],'A')).join('')}${[4,5,6,7].map(i=>mh('r1',i,r.r1[i],'B')).join('')}
      </div></div>`;
    if(allR1)h+=`<div class="round-block"><div class="round-head"><span class="round-label">Semifinais</span></div>
      <div class="round-grid g4">${[0,1].map(i=>mh('r2',i,r.r2[i],'A')).join('')}${[2,3].map(i=>mh('r2',i,r.r2[i],'B')).join('')}</div></div>`;
    if(allR2)h+=`<div class="round-block"><div class="round-head"><span class="round-label">Finais de Conferência</span></div>
      <div class="round-grid g2">${mh('r3',0,r.r3[0],'A')}${mh('r3',1,r.r3[1],'B')}</div></div>`;
    if(allR3)h+=`<div class="round-block"><div class="round-head"><span class="round-label">🏆 Grande Final</span></div>
      <div class="round-grid g1">${mh('r4',0,r.r4[0],null)}</div></div>`;
    if(champ)h+=`<div class="champ-wrap"><div class="champ-card"><div class="champ-crown">🏆</div><div class="champ-lbl">Campeão</div>${champ.photo_url?`<img class="champ-logo" src="${champ.photo_url}" onerror="this.style.display='none'">`:''}<div class="champ-name">${champ.name}</div><div class="champ-city">${champ.city||''}</div></div></div>`;
    wrap.innerHTML=h;
  }

  const btnSave=document.getElementById('btnSave-'+lg);const btnShare=document.getElementById('btnShare-'+lg);
  if(btnSave)btnSave.disabled=!champ;if(btnShare)btnShare.style.display=champ?'flex':'none';
}

// ── Locked bracket display ────────────────────────────────────────────────────
function renderLockedBracket(lg,seeds8,rounds){
  const el=document.getElementById('bracket-locked-'+lg);if(!el)return;
  const sA=seeds8.slice(0,8),sB=seeds8.slice(8,16);
  renderSeedsStrip(lg,sA,sB);
  const wrapId='brk-locked-wrap-'+lg;
  el.innerHTML=`<div class="bracket-wrap" id="${wrapId}"></div>`;
  renderBracket(lg,{seedsA:sA,seedsB:sB,rounds},true,wrapId);
}

// ── Modal oficial ─────────────────────────────────────────────────────────────
function openOficial(lg){
  const raw=OFFICIAL_RAW[lg];if(!raw)return;
  let seeds=[],rounds={};
  try{if(raw.seeds)seeds=JSON.parse(raw.seeds);}catch(e){}
  try{if(raw.rounds)rounds=JSON.parse(raw.rounds);}catch(e){}
  if(!rounds.r1)return showToast('Ainda não há resultado oficial','red');
  document.getElementById('modalLgLabel').textContent=lg;
  const content=document.getElementById('modalContent');
  const wrapId='modal-brk-'+lg;
  content.innerHTML=`<div class="bracket-wrap" id="${wrapId}"></div>`;
  renderBracket(lg,{seedsA:seeds.slice(0,8),seedsB:seeds.slice(8,16),rounds},true,wrapId);
  document.getElementById('modalOficial').classList.add('open');
}
function closeModal(){document.getElementById('modalOficial').classList.remove('open');}

// ── Save ──────────────────────────────────────────────────────────────────────
async function autoSave(lg,state){
  const cid=CYCLES[lg]&&CYCLES[lg].id;if(!cid)return;
  const seeds8=[...(state.seedsA||[]),...(state.seedsB||[])];
  fetch('bracket.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({acao:'save_picks',cycle_id:cid,seeds:JSON.stringify(seeds8),rounds:JSON.stringify(state.rounds),lock:'0'})});
}
async function saveBracket(lg){
  const state=loadLS(lg);if(!state?.rounds?.r4?.[0]?.w){showToast('Defina o campeão primeiro','red');return;}
  if(!confirm('Salvar e bloquear? Não poderá alterar depois.'))return;
  const cid=CYCLES[lg]&&CYCLES[lg].id;if(!cid)return;
  const seeds8=[...(state.seedsA||[]),...(state.seedsB||[])];
  const btn=document.getElementById('btnSave-'+lg);if(btn){btn.disabled=true;btn.innerHTML='<i class="bi bi-hourglass"></i>Salvando...';}
  const resp=await fetch('bracket.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:new URLSearchParams({acao:'save_picks',cycle_id:cid,seeds:JSON.stringify(seeds8),rounds:JSON.stringify(state.rounds),lock:'1'})});
  const data=await resp.json();
  if(data.ok){showToast('Bracket bloqueado!','green');setTimeout(()=>location.reload(),1200);}
  else{showToast(data.msg||'Erro','red');if(btn){btn.disabled=false;btn.innerHTML='<i class="bi bi-lock-fill"></i>Salvar e Bloquear';}}
}

// ── Share ─────────────────────────────────────────────────────────────────────
function shareBracket(lg){
  const raw=USER_PICKS_RAW[lg];let state=null;
  if(raw&&raw.locked){const s=JSON.parse(raw.seeds),r=JSON.parse(raw.rounds);state={seedsA:s.slice(0,8),seedsB:s.slice(8,16),rounds:r};}
  else state=loadLS(lg);
  if(!state?.rounds)return showToast('Bracket incompleto','red');
  const r=state.rounds;
  const tn=t=>t?t.name:'?';
  const fmt=(m,conf)=>{
    const cn=conf==='A'?'LESTE':'OESTE';
    if(!m.t1||!m.t2)return`[${cn}] — × —`;
    const n1=tn(m.t1),n2=tn(m.t2);
    if(!m.w)return`[${cn}] ${n1} × ${n2}`;
    const w1=m.w.id===m.t1.id;
    return`[${cn}] ${w1?'*'+n1+'*':n1} × ${w1?n2:'*'+n2+'*'}`;
  };
  let txt=`🏆 FBA BRACKET — ${lg}\n\n⚔️ 1ª RODADA\n`;
  r.r1.forEach((m,i)=>txt+=fmt(m,i<4?'A':'B')+'\n');
  if(r.r2[0].t1)txt+=`\n🏅 SEMIS\n`+r.r2.map((m,i)=>fmt(m,i<2?'A':'B')).join('\n')+'\n';
  if(r.r3[0].t1)txt+=`\n🥊 FINAIS CONF\n`+r.r3.map((m,i)=>fmt(m,i===0?'A':'B')).join('\n')+'\n';
  if(r.r4[0].t1)txt+=`\n🏆 GRANDE FINAL\n`+fmt(r.r4[0],'')+'\n';
  if(r.r4[0].w)txt+=`\n👑 CAMPEÃO: *${r.r4[0].w.name}*\n`;
  txt+=`\n🏀 FBA Games`;
  if(navigator.clipboard)navigator.clipboard.writeText(txt).then(()=>showToast('Copiado!','green')).catch(()=>showToast('Erro ao copiar','red'));
  else{const ta=document.createElement('textarea');ta.value=txt;document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta);showToast('Copiado!','green');}
}

// ── Reset ─────────────────────────────────────────────────────────────────────
function resetBracket(lg){
  if(!confirm('Resetar bracket de '+lg+'?'))return;
  clearLS(lg);renderConfWrap(lg,[],[]);renderSeedsStrip(lg,[],[]);
  document.getElementById('pickingPhase-'+lg).style.display='block';
  document.getElementById('bracketPhase-'+lg).style.display='none';
  document.getElementById('phaseTitle-'+lg).innerHTML='Montar <span>Bracket</span>';
  document.getElementById('phaseSub-'+lg).textContent='Selecione 8 seeds de cada conferência';
  document.getElementById('btnStart-'+lg).disabled=true;
}

// ── Sidebar ───────────────────────────────────────────────────────────────────
function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('sbOverlay').classList.add('open');document.body.style.overflow='hidden';}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sbOverlay').classList.remove('open');document.body.style.overflow='';}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded',()=>{
  LEAGUES.forEach(lg=>{
    const raw=USER_PICKS_RAW[lg];
    if(raw&&raw.locked){
      const s=JSON.parse(raw.seeds),r=JSON.parse(raw.rounds);
      renderLockedBracket(lg,s,r);return;
    }
    // Clear old format (was 8 seeds total, now 16)
    const st=loadLS(lg);
    if(st&&st.seedsA&&st.seedsA.length<=4&&!st.rounds){clearLS(lg);}
    const state=loadLS(lg);
    if(state&&state.phase==='bracket'&&state.rounds){
      document.getElementById('pickingPhase-'+lg).style.display='none';
      document.getElementById('bracketPhase-'+lg).style.display='block';
      document.getElementById('phaseTitle-'+lg).innerHTML='Bracket <span>Playoffs</span>';
      document.getElementById('phaseSub-'+lg).textContent='Clique no vencedor de cada confronto';
      renderBracket(lg,state);
    }else{
      const sA=state?.seedsA||[],sB=state?.seedsB||[];
      renderConfWrap(lg,sA,sB);
    }
  });
  document.getElementById('modalOficial').addEventListener('click',e=>{if(e.target===e.currentTarget)closeModal();});
});
</script>
</body>
</html>
