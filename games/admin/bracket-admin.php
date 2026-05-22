<?php
session_start();
require '../core/conexao.php';
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$stmt = $pdo->prepare("SELECT is_admin, nome, pontos, fba_points, COALESCE(numero_tapas, 0) as numero_tapas FROM usuarios WHERE id=:id");
$stmt->execute([':id'=>$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user || !$user['is_admin']) die("Acesso negado.");

// ── AJAX ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['acao'])) {
    header('Content-Type: application/json');
    $acao = $_POST['acao'];

    if ($acao === 'save_conferences') {
        try {
            $league = trim($_POST['league']??'');
            $teams  = $_POST['teams']??[];
            $pdo->prepare("DELETE FROM fba_bracket_conferences WHERE league=?")->execute([$league]);
            $stmt2 = $pdo->prepare("INSERT INTO fba_bracket_conferences (team_id,league,conference) VALUES (?,?,?)");
            foreach ($teams as $teamId => $conf) {
                if ($conf==='A'||$conf==='B') $stmt2->execute([(int)$teamId,$league,$conf]);
            }
            echo json_encode(['ok'=>true,'msg'=>"Conferências da liga $league salvas!"]);
        } catch(Exception $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    if ($acao === 'save_official_picks') {
        try {
            $cycle_id = (int)($_POST['cycle_id']??0);
            $seeds    = $_POST['seeds']??'';
            $rounds   = $_POST['rounds']??'';
            if (!$cycle_id) throw new Exception('cycle_id inválido');
            $s = $pdo->prepare("INSERT INTO fba_bracket_official (cycle_id,seeds_json,rounds_json,updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE seeds_json=VALUES(seeds_json),rounds_json=VALUES(rounds_json),updated_by=VALUES(updated_by),updated_at=NOW()");
            $s->execute([$cycle_id, $seeds?:null, $rounds?:null, $_SESSION['user_id']]);
            // Auto-close picks for this league when official is saved
            $lgStmt = $pdo->prepare("SELECT league FROM fba_bracket_cycles WHERE id=?");
            $lgStmt->execute([$cycle_id]);
            $lgRow = $lgStmt->fetch(PDO::FETCH_ASSOC);
            if ($lgRow && $lgRow['league']) {
                $pdo->prepare("INSERT INTO fba_bracket_settings (league,picking_open) VALUES (?,0) ON DUPLICATE KEY UPDATE picking_open=0")->execute([$lgRow['league']]);
            }
            // Recalculate all user picks
            $official = ['seeds_json'=>$seeds,'rounds_json'=>$rounds];
            $picks = $pdo->prepare("SELECT id,seeds_json,rounds_json FROM fba_bracket_picks WHERE cycle_id=?");
            $picks->execute([$cycle_id]);
            foreach ($picks->fetchAll(PDO::FETCH_ASSOC) as $pick) {
                $pts = calcPoints($pick, $official);
                $pdo->prepare("UPDATE fba_bracket_picks SET points=? WHERE id=?")->execute([$pts,$pick['id']]);
            }
            echo json_encode(['ok'=>true]);
        } catch(Exception $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    if ($acao === 'auto_assign_conferences') {
        try {
            $league = trim($_POST['league']??'');
            if (!$league) throw new Exception('Liga inválida');
            $pdoFba2 = new PDO('mysql:host=localhost;dbname=u289267434_fbabrasilbanco;charset=utf8mb4','u289267434_fbabrasilbanco','Fbabrasil@2025',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $rows2 = $pdoFba2->prepare("SELECT id, conference FROM teams WHERE league=?");
            $rows2->execute([$league]);
            $teams2 = $rows2->fetchAll(PDO::FETCH_ASSOC);
            $pdo->prepare("DELETE FROM fba_bracket_conferences WHERE league=?")->execute([$league]);
            $stmt3 = $pdo->prepare("INSERT INTO fba_bracket_conferences (team_id,league,conference) VALUES (?,?,?)");
            $count = 0;
            foreach ($teams2 as $t) {
                $conf = ($t['conference'] === 'LESTE') ? 'A' : (($t['conference'] === 'OESTE') ? 'B' : null);
                if ($conf) { $stmt3->execute([(int)$t['id'],$league,$conf]); $count++; }
            }
            echo json_encode(['ok'=>true,'msg'=>"$count times atribuídos para $league!"]);
        } catch(Exception $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    if ($acao === 'toggle_picking') {
        try {
            $league = trim($_POST['league']??'');
            $open   = (int)$_POST['open'];
            $pdo->prepare("INSERT INTO fba_bracket_settings (league,picking_open) VALUES (?,?) ON DUPLICATE KEY UPDATE picking_open=?")->execute([$league,$open,$open]);
            echo json_encode(['ok'=>true,'open'=>$open]);
        } catch(Exception $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }

    if ($acao === 'force_reset') {
        try {
            $league = trim($_POST['league']??'');
            $cycles2 = $pdo->prepare("SELECT * FROM fba_bracket_cycles WHERE league=? AND status='open'");
            $cycles2->execute([$league]);
            foreach ($cycles2->fetchAll(PDO::FETCH_ASSOC) as $cycle) {
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
            $pdo->prepare("INSERT INTO fba_bracket_cycles (league,cycle_start,status) VALUES (?,NOW(),'open')")->execute([$league]);
            echo json_encode(['ok'=>true,'msg'=>"Bracket $league resetado."]);
        } catch(Exception $e) { echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); }
        exit;
    }
    exit;
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
$leagues = []; $teamsByLeague = []; $confAssignments = [];
try { $rows=$pdo->query("SELECT team_id,league,conference FROM fba_bracket_conferences")->fetchAll(PDO::FETCH_ASSOC); foreach($rows as $r) $confAssignments[$r['league']][$r['team_id']]=$r['conference']; } catch(Exception $e){}
try {
    $pdoFba = new PDO('mysql:host=localhost;dbname=u289267434_fbabrasilbanco;charset=utf8mb4','u289267434_fbabrasilbanco','Fbabrasil@2025',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $rows = $pdoFba->query("SELECT id,name,city,league,photo_url,conference FROM teams ORDER BY league,name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $lg=$r['league']; if ($lg==='ROOKIE') continue;
        if (!in_array($lg,$leagues)) $leagues[]=$lg;
        $conf = $confAssignments[$lg][(int)$r['id']] ?? null;
        if (!$conf && !empty($r['conference'])) {
            $conf = ($r['conference']==='LESTE') ? 'A' : (($r['conference']==='OESTE') ? 'B' : '');
        }
        $teamsByLeague[$lg][]=['id'=>(int)$r['id'],'name'=>$r['name'],'city'=>$r['city'],'photo_url'=>$r['photo_url']?:'','conference'=>$conf??''];
    }
} catch(Exception $e){}

$bracketSettings = [];
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_bracket_settings (league VARCHAR(20) NOT NULL PRIMARY KEY, picking_open TINYINT(1) NOT NULL DEFAULT 1)");
    foreach ($pdo->query("SELECT league, picking_open FROM fba_bracket_settings")->fetchAll(PDO::FETCH_ASSOC) as $r)
        $bracketSettings[$r['league']] = (int)$r['picking_open'];
} catch(Exception $e) {}

$cycles=[]; $officialByLeague=[]; $pickCountByLeague=[];
foreach ($leagues as $lg) {
    $s=$pdo->prepare("SELECT * FROM fba_bracket_cycles WHERE league=? AND status='open' ORDER BY id DESC LIMIT 1");
    $s->execute([$lg]); $c=$s->fetch(PDO::FETCH_ASSOC);
    $cycles[$lg]=$c?:null;
    if ($c) {
        $s2=$pdo->prepare("SELECT * FROM fba_bracket_official WHERE cycle_id=?"); $s2->execute([$c['id']]);
        $officialByLeague[$lg]=$s2->fetch(PDO::FETCH_ASSOC)?:null;
        $s3=$pdo->prepare("SELECT COUNT(*) FROM fba_bracket_picks WHERE cycle_id=? AND locked=1"); $s3->execute([$c['id']]);
        $pickCountByLeague[$lg]=(int)$s3->fetchColumn();
    } else { $officialByLeague[$lg]=null; $pickCountByLeague[$lg]=0; }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Bracket — FBA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:rgba(252,0,37,.10);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;--border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--border-red:rgba(252,0,37,.22);--text:#f0f0f3;--text-2:#868690;--text-3:#48484f;--green:#22c55e;--amber:#f59e0b;--blue:#3b82f6;--font:'Poppins',sans-serif;--radius:14px;--radius-sm:10px;--ease:cubic-bezier(.2,.8,.2,1);--t:200ms;--sw:240px}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{background:var(--bg);color:var(--text);font-family:var(--font);-webkit-font-smoothing:antialiased;padding:0}
/* ── Sidebar ── */
.page-layout{display:flex;min-height:100vh}
.sidebar{width:var(--sw);flex-shrink:0;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:200;overflow-y:auto}
.page-content{flex:1;margin-left:var(--sw);min-width:0;overflow-x:hidden}
.sb-header{display:flex;align-items:center;gap:10px;padding:16px 14px 12px;border-bottom:1px solid var(--border);flex-shrink:0}
.sb-logo{width:30px;height:30px;border-radius:8px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;color:#fff;flex-shrink:0}
.sb-brand{font-weight:800;font-size:13px;color:var(--text);flex:1}.sb-brand span{color:var(--red)}
.sb-close{display:none;background:none;border:none;color:var(--text-2);font-size:18px;cursor:pointer;padding:4px}
.sb-user{padding:14px;border-bottom:1px solid var(--border)}
.sb-avatar{width:40px;height:40px;border-radius:50%;background:var(--red-soft);border:2px solid var(--border-red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:15px;color:var(--red);margin-bottom:8px}
.sb-user-name{font-size:13px;font-weight:700;color:#fff}.sb-user-role{font-size:10px;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.sb-stats{padding:10px 14px;border-bottom:1px solid var(--border);display:flex;flex-direction:row;gap:6px}
.sb-stat{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;gap:4px;padding:8px 4px;background:var(--panel-2);border-radius:8px;border:1px solid var(--border)}
.sb-stat-val{font-size:12px;font-weight:700;color:var(--text);line-height:1.2}.sb-stat-label{font-size:9px;color:var(--text-3)}
.sb-nav{flex:1;padding:8px 0;overflow-y:auto}
.sb-nav-section{font-size:9px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:8px 14px 4px}
.sb-link{display:flex;align-items:center;gap:10px;padding:9px 14px;text-decoration:none;font-size:12px;font-weight:500;color:var(--text-2);transition:all var(--t) var(--ease);border-left:3px solid transparent}
.sb-link i{width:16px;text-align:center;font-size:13px}
.sb-link:hover{background:var(--panel-2);color:var(--text);border-left-color:var(--border-md)}
.sb-link.active{background:var(--red-soft);color:var(--red);border-left-color:var(--red);font-weight:700}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border);flex-shrink:0}
.sb-logout{display:flex;align-items:center;gap:8px;width:100%;padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-2);text-decoration:none;font-family:var(--font);font-size:12px;font-weight:600;transition:all var(--t) var(--ease)}
.sb-logout:hover{background:rgba(252,0,37,.1);border-color:var(--border-red);color:var(--red)}
/* ── Mob bar ── */
.mob-bar{display:none;align-items:center;gap:12px;height:52px;padding:0 14px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.mob-ham{background:none;border:1px solid var(--border);border-radius:8px;color:var(--text);width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px}
.mob-title{font-size:14px;font-weight:800;color:var(--text);flex:1}.mob-title span{color:var(--red)}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:199;backdrop-filter:blur(2px)}
/* ── Main content ── */
.main{max-width:960px;margin:0 auto;padding:24px 20px 60px}
.tab-bar{display:flex;gap:4px;background:var(--panel-2);border:1px solid var(--border);border-radius:999px;padding:3px;margin:0 auto 24px;width:fit-content}
.tab-btn{padding:7px 22px;border-radius:999px;border:none;background:transparent;color:var(--text-2);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease)}
.tab-btn.active{background:var(--red);color:#fff;box-shadow:0 2px 12px rgba(252,0,37,.3)}
.card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:14px}
.card-head{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;cursor:pointer;user-select:none}
.card-head-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-2);display:flex;align-items:center;gap:7px}
.card-head-title i{color:var(--red)}
.card-body{padding:16px}
/* Conference assignment */
.team-row{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--border)}
.team-row:last-child{border-bottom:none}
.t-logo{width:26px;height:26px;border-radius:50%;object-fit:cover;flex-shrink:0}
.t-name{font-size:12px;font-weight:600;color:var(--text);flex:1}
.conf-radio{display:flex;gap:6px}
.conf-btn{padding:3px 12px;border-radius:6px;border:1px solid var(--border);background:transparent;color:var(--text-2);font-family:var(--font);font-size:11px;font-weight:700;cursor:pointer;transition:all .15s}
.conf-btn.a.active{background:rgba(59,130,246,.15);border-color:var(--blue);color:var(--blue)}
.conf-btn.b.active{background:rgba(245,158,11,.15);border-color:var(--amber);color:var(--amber)}
/* Bracket picking */
.conf-wrap{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.conf-section{background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px}
.conf-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.conf-label.a{color:var(--blue)}.conf-label.b{color:var(--amber)}
.teams-grid{display:flex;flex-direction:column;gap:4px}
.team-card{background:var(--panel-3);border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px 12px;display:flex;align-items:center;gap:10px;cursor:pointer;transition:all var(--t) var(--ease);user-select:none}
.team-card:hover{border-color:var(--border-md)}.team-card.sel-a{border-color:var(--blue);background:rgba(59,130,246,.08)}.team-card.sel-b{border-color:var(--amber);background:rgba(245,158,11,.08)}.team-card.full{opacity:.3;pointer-events:none}
.seed-badge{width:20px;height:20px;border-radius:50%;font-size:9px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:var(--panel-2);border:1px solid var(--border);color:var(--text-3)}
.seed-badge.a{background:var(--blue);border-color:var(--blue);color:#fff}.seed-badge.b{background:var(--amber);border-color:var(--amber);color:#fff}
.tl{width:36px;height:36px;border-radius:50%;object-fit:cover;background:var(--panel-2)}
.tl-ph{width:36px;height:36px;border-radius:50%;background:var(--panel-2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;color:var(--text-3)}
.t-nm{font-size:12px;font-weight:600;color:var(--text);line-height:1.3}
/* Seeds strip */
.seeds-conf-row{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:5px}
.seed-slot{display:flex;align-items:center;gap:3px;padding:3px 7px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:6px;min-width:0}
.snum{font-size:9px;font-weight:800;white-space:nowrap}.snum.a{color:var(--blue)}.snum.b{color:var(--amber)}
.slogo{width:16px;height:16px;border-radius:50%;object-fit:cover;flex-shrink:0}
.sname{font-size:10px;font-weight:600;color:var(--text);max-width:60px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* Bracket */
.bracket-wrap{display:flex;flex-direction:column;gap:12px}
.round-block{background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden}
.round-head{padding:8px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.round-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-2)}
.round-grid{display:grid;background:var(--border)}
.round-grid.g4{grid-template-columns:1fr 1fr 1fr 1fr}.round-grid.g2{grid-template-columns:1fr 1fr}.round-grid.g1{grid-template-columns:1fr}
.matchup{background:var(--panel)}
.conf-tag{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:4px 10px 2px;display:block}
.conf-tag.a{color:var(--blue)}.conf-tag.b{color:var(--amber)}
.m-team{display:flex;align-items:center;gap:8px;padding:8px 10px;cursor:pointer;transition:background var(--t) var(--ease);border-left:3px solid transparent}
.m-team:not(.locked):not(.tbd):hover{background:var(--panel-2)}.m-team+.m-team{border-top:1px solid var(--border)}
.m-team.winner{background:rgba(34,197,94,.07);border-left-color:var(--green)}.m-team.loser{opacity:.3}.m-team.locked,.m-team.tbd{cursor:default}
.m-logo{width:26px;height:26px;border-radius:50%;object-fit:cover;flex-shrink:0;background:var(--panel-3)}
.m-logo-ph{width:26px;height:26px;border-radius:50%;background:var(--panel-3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:7px;font-weight:800;color:var(--text-3);flex-shrink:0}
.m-info{flex:1;min-width:0}.m-seed{font-size:9px;color:var(--text-3);font-weight:600}.m-name{font-size:11px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.m-check{margin-left:auto;color:var(--green);font-size:13px;flex-shrink:0}
.champ-wrap{margin-top:12px;display:flex;justify-content:center}
.champ-card{display:inline-flex;flex-direction:column;align-items:center;gap:7px;background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(252,0,37,.08));border:1px solid rgba(245,158,11,.3);border-radius:var(--radius);padding:18px 28px}
.champ-crown{font-size:22px}.champ-lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--amber)}
.champ-logo{width:52px;height:52px;border-radius:50%;object-fit:cover;border:3px solid var(--amber)}
.champ-name{font-size:16px;font-weight:800;color:#fff}.champ-city{font-size:11px;color:var(--text-2)}
/* Buttons */
.btn-red{background:var(--red);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:opacity .15s}
.btn-red:hover{opacity:.85}.btn-red:disabled{opacity:.35;cursor:not-allowed}
.btn-outline{background:transparent;border:1px solid var(--border-md);border-radius:8px;padding:8px 16px;color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s}
.btn-outline:hover{border-color:rgba(239,68,68,.4);color:#f87171;background:rgba(239,68,68,.06)}
.toggle-picking{display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:8px;border:none;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;transition:all .15s}
.toggle-picking.open{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:var(--green)}
.toggle-picking.open:hover{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.3);color:#f87171}
.toggle-picking.closed{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.25);color:#f87171}
.toggle-picking.closed:hover{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.3);color:var(--green)}
.btn-ghost-sm{background:transparent;border:1px solid var(--border);border-radius:7px;padding:5px 12px;color:var(--text-2);font-family:var(--font);font-size:11px;font-weight:600;cursor:pointer;transition:all .15s}
.btn-ghost-sm:hover{border-color:var(--border-md);color:var(--text)}
.cycle-info{font-size:11px;color:var(--text-2);margin-bottom:14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.badge-count{background:var(--panel-3);border:1px solid var(--border);border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700;color:var(--amber)}
.save-status{font-size:11px;color:var(--green);display:flex;align-items:center;gap:5px;opacity:0;transition:opacity .3s}
.save-status.show{opacity:1}
.toast{position:fixed;bottom:20px;right:20px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);padding:11px 16px;font-size:13px;font-weight:600;color:var(--text);z-index:9999;opacity:0;transform:translateY(8px);transition:all .3s;pointer-events:none}
.toast.show{opacity:1;transform:translateY(0)}.toast.green{border-color:rgba(34,197,94,.3);color:var(--green)}.toast.red{border-color:rgba(252,0,37,.3);color:#ff6680}
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
  .brk-tree .m-name{font-size:10px}.brk-tree .m-seed{font-size:8px}
  .brk-conns{width:24px;flex-shrink:0;display:flex;flex-direction:column;padding-top:34px;background:var(--panel)}
  .brk-conn{flex:1;position:relative}
  .brk-conn::before{content:'';position:absolute;left:0;top:25%;width:12px;height:50%;border-top:1px solid var(--border-md);border-right:1px solid var(--border-md);border-bottom:1px solid var(--border-md)}
  .brk-conn::after{content:'';position:absolute;left:12px;top:50%;right:0;height:1px;background:var(--border-md)}
  .brk-col-champ{flex:0 0 110px;min-width:90px}
  .brk-champ-mini{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:14px 8px;text-align:center;height:100%;background:linear-gradient(135deg,rgba(245,158,11,.07),rgba(252,0,37,.05))}
  .brk-champ-mini img{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--amber)}
  .brk-champ-mini .cn{font-size:11px;font-weight:800;color:var(--amber)}.brk-champ-mini .cc{font-size:9px;color:var(--text-2)}
}
@media(max-width:640px){.conf-wrap{grid-template-columns:1fr}.round-grid.g4{grid-template-columns:1fr 1fr}}
@media(max-width:768px){.sidebar{transform:translateX(-100%);transition:transform 280ms var(--ease)}.sidebar.open{transform:translateX(0)}.page-content{margin-left:0}.mob-bar{display:flex}.sb-close{display:flex;align-items:center;justify-content:center}.sb-overlay.open{display:block}}
</style>
</head>
<body>
<div class="toast" id="toast"></div>

<div class="page-layout">
<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-logo">FBA</div>
    <span class="sb-brand">FBA <span>Admin</span></span>
    <button class="sb-close" onclick="closeSidebar()"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?= strtoupper(substr($user['nome']??'A',0,1)) ?></div>
    <div class="sb-user-name"><?= htmlspecialchars($user['nome']??'') ?></div>
    <div class="sb-user-role">Administrador</div>
  </div>
  <div class="sb-stats">
    <div class="sb-stat">
      <i class="bi bi-hand-index-fill" style="color:var(--green)"></i>
      <div class="sb-stat-val"><?= number_format($user['numero_tapas']??0,0,',','.') ?></div>
      <div class="sb-stat-label">Tapas</div>
    </div>
    <div class="sb-stat">
      <img src="../moeda.png" style="width:18px;height:18px;object-fit:contain;vertical-align:middle">
      <div class="sb-stat-val"><?= number_format($user['pontos']??0,0,',','.') ?></div>
      <div class="sb-stat-label">Moedas</div>
    </div>
    <div class="sb-stat">
      <img src="../lebron.png" style="width:18px;height:18px;object-fit:contain;vertical-align:middle">
      <div class="sb-stat-val"><?= number_format($user['fba_points']??0,0,',','.') ?></div>
      <div class="sb-stat-label">FBA Pts</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-section">Menu</div>
    <a href="../index.php" class="sb-link"><i class="bi bi-lightning-charge"></i>Apostas</a>
    <a href="../games.php" class="sb-link"><i class="bi bi-joystick"></i>Games</a>
    <a href="../user/ranking-geral.php" class="sb-link"><i class="bi bi-trophy"></i>Ranking Geral</a>
    <a href="../bracket.php" class="sb-link"><i class="bi bi-diagram-3-fill"></i>Bracket</a>
    <div class="sb-nav-section">Admin</div>
    <a href="controlegames.php" class="sb-link"><i class="bi bi-gear-fill"></i>Controle de Jogos</a>
    <a href="dashboard.php" class="sb-link"><i class="bi bi-receipt-cutoff"></i>Controle Apostas</a>
    <a href="bracket-admin.php" class="sb-link active"><i class="bi bi-diagram-3"></i>Admin Bracket</a>
  </nav>
  <div class="sb-footer">
    <a href="../auth/logout.php" class="sb-logout"><i class="bi bi-box-arrow-right"></i>Sair</a>
  </div>
</aside>

<div class="page-content">
<div class="mob-bar">
  <button class="mob-ham" onclick="openSidebar()"><i class="bi bi-list"></i></button>
  <span class="mob-title">Admin <span>Bracket</span></span>
</div>

<div class="main">
  <?php if (empty($leagues)): ?>
  <p style="color:var(--text-3);text-align:center;padding:40px">Nenhuma liga encontrada.</p>
  <?php else: ?>

  <div class="tab-bar">
    <?php foreach ($leagues as $i=>$lg): ?>
    <button class="tab-btn <?= $i===0?'active':'' ?>" onclick="switchTab('<?= $lg ?>')" id="adm-tab-<?= $lg ?>"><?= htmlspecialchars($lg) ?></button>
    <?php endforeach; ?>
  </div>

  <?php foreach ($leagues as $i=>$lg): $cycle=$cycles[$lg]; $official=$officialByLeague[$lg]; ?>
  <div id="adm-panel-<?= $lg ?>" style="display:<?= $i===0?'block':'none' ?>">

    <!-- Cycle info -->
    <div class="cycle-info" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div>
        <?php if ($cycle): ?>
        <i class="bi bi-circle-fill" style="color:var(--green);font-size:8px"></i>
        Ciclo #<?= $cycle['id'] ?> · iniciado <?= date('d/m/Y H:i',strtotime($cycle['cycle_start'])) ?>
        <span class="badge-count"><?= $pickCountByLeague[$lg] ?> pick(s) salvo(s)</span>
        <?php else: ?>
        <i class="bi bi-exclamation-circle" style="color:var(--amber)"></i>
        Nenhum ciclo ativo. Acesse bracket.php para criar.
        <?php endif; ?>
      </div>
      <?php $isOpen=($bracketSettings[$lg]??1)===1; ?>
      <button id="toggle-picking-<?= $lg ?>" class="toggle-picking <?= $isOpen?'open':'closed' ?>"
              onclick="togglePicking('<?= $lg ?>', <?= $isOpen?0:1 ?>)">
        <i class="bi bi-<?= $isOpen?'unlock-fill':'lock-fill' ?>"></i>
        <?= $isOpen?'Picks Abertos — Desativar':'Picks Fechados — Ativar' ?>
      </button>
    </div>

    <!-- Conference assignment -->
    <div class="card">
      <div class="card-head" onclick="toggleCard('conf-<?= $lg ?>')">
        <div class="card-head-title"><i class="bi bi-shield-half"></i>Conferências — <?= htmlspecialchars($lg) ?></div>
        <i class="bi bi-chevron-down" id="chevron-conf-<?= $lg ?>" style="color:var(--text-3);font-size:12px;transition:transform .2s"></i>
      </div>
      <div id="conf-<?= $lg ?>" class="card-body" style="display:none">
        <div style="max-height:280px;overflow-y:auto">
          <?php foreach (($teamsByLeague[$lg]??[]) as $t): $conf=$confAssignments[$lg][$t['id']]??''; ?>
          <div class="team-row">
            <?php if ($t['photo_url']): ?><img class="t-logo" src="<?= htmlspecialchars($t['photo_url']) ?>" onerror="this.style.display='none'"><?php endif; ?>
            <div class="t-name"><?= htmlspecialchars($t['name']) ?></div>
            <div class="conf-radio">
              <button type="button" class="conf-btn a <?= $conf==='A'?'active':'' ?>" onclick="setConf(this,'A',<?= $t['id'] ?>,'<?= $lg ?>')">A</button>
              <button type="button" class="conf-btn b <?= $conf==='B'?'active':'' ?>" onclick="setConf(this,'B',<?= $t['id'] ?>,'<?= $lg ?>')">B</button>
              <input type="hidden" id="conf-val-<?= $lg ?>-<?= $t['id'] ?>" value="<?= htmlspecialchars($conf) ?>">
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <button class="btn-red" onclick="saveConferences('<?= $lg ?>')"><i class="bi bi-save-fill"></i>Salvar Conferências</button>
          <button class="btn-ghost-sm" onclick="autoAssignConferences('<?= $lg ?>')" style="display:flex;align-items:center;gap:5px"><i class="bi bi-magic"></i>Auto-atribuir (LESTE→A / OESTE→B)</button>
          <span class="save-status" id="conf-saved-<?= $lg ?>"><i class="bi bi-check-circle-fill"></i>Salvo!</span>
        </div>
      </div>
    </div>

    <!-- Official bracket -->
    <?php if ($cycle): ?>
    <div class="card">
      <div class="card-head" onclick="toggleCard('brk-<?= $lg ?>')">
        <div class="card-head-title"><i class="bi bi-trophy-fill"></i>Bracket Oficial — <?= htmlspecialchars($lg) ?></div>
        <div style="display:flex;align-items:center;gap:10px">
          <span class="save-status" id="brk-saved-<?= $lg ?>"><i class="bi bi-check-circle-fill"></i>Salvo</span>
          <i class="bi bi-chevron-down" id="chevron-brk-<?= $lg ?>" style="color:var(--text-3);font-size:12px;transition:transform .2s"></i>
        </div>
      </div>
      <div id="brk-<?= $lg ?>" class="card-body">


        <!-- Picking phase -->
        <div id="adm-picking-<?= $lg ?>">
          <div style="font-size:11px;color:var(--text-2);margin-bottom:10px">Selecione 8 seeds de cada conferência para montar o bracket oficial</div>
          <div class="conf-wrap" id="adm-confWrap-<?= $lg ?>"></div>
          <button class="btn-red" id="adm-btnStart-<?= $lg ?>" disabled onclick="adminStartBracket('<?= $lg ?>')"><i class="bi bi-play-fill"></i>Gerar Bracket</button>
        </div>

        <!-- Bracket phase -->
        <div id="adm-bracketPhase-<?= $lg ?>" style="display:none">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px">
            <div style="font-size:11px;color:var(--text-2)">Clique no vencedor de cada confronto para salvar o resultado oficial</div>
            <button class="btn-ghost-sm" onclick="adminResetBracket('<?= $lg ?>')"><i class="bi bi-arrow-counterclockwise"></i>Resetar</button>
          </div>
          <div class="bracket-wrap" id="adm-bracketWrap-<?= $lg ?>"></div>
        </div>
      </div>
    </div>

    <!-- Force reset -->
    <div style="margin-bottom:20px">
      <button class="btn-outline" onclick="forceReset('<?= $lg ?>')"><i class="bi bi-arrow-counterclockwise"></i>Forçar Reset <?= htmlspecialchars($lg) ?></button>
    </div>

    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div><!-- /main -->
</div><!-- /page-content -->
</div><!-- /page-layout -->

<script>
const TEAMS_BY_LEAGUE = <?= json_encode($teamsByLeague, JSON_UNESCAPED_UNICODE) ?>;
const LEAGUES = <?= json_encode($leagues, JSON_UNESCAPED_UNICODE) ?>;
const CYCLES  = <?= json_encode(array_combine($leagues, array_map(fn($lg)=>$cycles[$lg]?['id'=>$cycles[$lg]['id']]:null, $leagues)), JSON_UNESCAPED_UNICODE) ?>;
const OFFICIAL_RAW = <?= json_encode(array_combine($leagues, array_map(fn($lg)=>$officialByLeague[$lg]?['seeds'=>$officialByLeague[$lg]['seeds_json']??'','rounds'=>$officialByLeague[$lg]['rounds_json']??'']:null, $leagues)), JSON_UNESCAPED_UNICODE) ?>;
const PICKING_OPEN = <?= json_encode(array_combine($leagues, array_map(fn($lg)=>($bracketSettings[$lg]??1)===1, $leagues)), JSON_UNESCAPED_UNICODE) ?>;

const ADV = {
  r1:{0:{r:'r2',i:0,s:'t1'},1:{r:'r2',i:0,s:'t2'},2:{r:'r2',i:1,s:'t1'},3:{r:'r2',i:1,s:'t2'},
      4:{r:'r2',i:2,s:'t1'},5:{r:'r2',i:2,s:'t2'},6:{r:'r2',i:3,s:'t1'},7:{r:'r2',i:3,s:'t2'}},
  r2:{0:{r:'r3',i:0,s:'t1'},1:{r:'r3',i:0,s:'t2'},2:{r:'r3',i:1,s:'t1'},3:{r:'r3',i:1,s:'t2'}},
  r3:{0:{r:'r4',i:0,s:'t1'},1:{r:'r4',i:0,s:'t2'}}
};

function lsKey(lg){return 'fba_brk_adm_v1_'+lg;}
function loadLS(lg){try{return JSON.parse(localStorage.getItem(lsKey(lg)))||null;}catch(e){return null;}}
function saveLS(lg,s){localStorage.setItem(lsKey(lg),JSON.stringify(s));}
function clearLS(lg){localStorage.removeItem(lsKey(lg));}

function showToast(msg,type=''){const el=document.getElementById('toast');el.textContent=msg;el.className='toast show'+(type?' '+type:'');clearTimeout(el._t);el._t=setTimeout(()=>el.classList.remove('show'),2500);}
function showSaved(id){const el=document.getElementById(id);if(!el)return;el.classList.add('show');clearTimeout(el._t);el._t=setTimeout(()=>el.classList.remove('show'),2500);}

function switchTab(lg){LEAGUES.forEach(l=>{document.getElementById('adm-panel-'+l).style.display=l===lg?'block':'none';document.getElementById('adm-tab-'+l).classList.toggle('active',l===lg);});}

async function togglePicking(lg, newOpen) {
  const btn = document.getElementById('toggle-picking-'+lg);
  if (btn) { btn.disabled = true; }
  try {
    const resp = await fetch('bracket-admin.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({acao:'toggle_picking', league:lg, open:newOpen})});
    const data = await resp.json();
    if (data.ok) {
      const isNowOpen = data.open === 1;
      if (btn) {
        btn.className = 'toggle-picking ' + (isNowOpen ? 'open' : 'closed');
        btn.innerHTML = `<i class="bi bi-${isNowOpen?'unlock-fill':'lock-fill'}"></i> ${isNowOpen?'Picks Abertos — Desativar':'Picks Fechados — Ativar'}`;
        btn.setAttribute('onclick', `togglePicking('${lg}', ${isNowOpen?0:1})`);
      }
      showToast(isNowOpen ? `Picks da liga ${lg} ativados!` : `Picks da liga ${lg} fechados!`, isNowOpen?'green':'red');
    } else { showToast(data.msg||'Erro','red'); }
  } catch(e) { showToast('Erro de conexão','red'); }
  if (btn) btn.disabled = false;
}

function toggleCard(id){const el=document.getElementById(id);const chevron=document.getElementById('chevron-'+id);const open=el.style.display!=='none';el.style.display=open?'none':'block';if(chevron)chevron.style.transform=open?'':'rotate(180deg)';}

// ── Conference assignment ──────────────────────────────────────────────────────
function setConf(btn, conf, teamId, lg) {
  document.getElementById('conf-val-'+lg+'-'+teamId).value=conf;
  const row=btn.closest('.conf-radio');
  row.querySelectorAll('.conf-btn').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
}
async function saveConferences(lg) {
  const teams={};
  document.querySelectorAll('[id^="conf-val-'+lg+'-"]').forEach(el=>{
    const id=el.id.replace('conf-val-'+lg+'-','');const val=el.value;if(val)teams[id]=val;
  });
  const body=new URLSearchParams({acao:'save_conferences',league:lg});
  Object.entries(teams).forEach(([id,v])=>body.append('teams['+id+']',v));
  const resp=await fetch('bracket-admin.php',{method:'POST',body});
  const data=await resp.json();
  if(data.ok){showSaved('conf-saved-'+lg);showToast('Conferências salvas!','green');}
  else showToast(data.msg||'Erro','red');
}

// ── Logo helpers ──────────────────────────────────────────────────────────────
function logoHtml(t,sz=26){
  if(!t)return`<div class="m-logo-ph" style="width:${sz}px;height:${sz}px">?</div>`;
  if(t.photo_url)return`<img class="m-logo" src="${t.photo_url}" style="width:${sz}px;height:${sz}px" onerror="this.outerHTML='<div class=m-logo-ph style=width:${sz}px;height:${sz}px>${(t.name||'?').slice(0,2).toUpperCase()}</div>'">`;
  return`<div class="m-logo-ph" style="width:${sz}px;height:${sz}px">${(t.name||'?').slice(0,2).toUpperCase()}</div>`;
}

// ── Seeds strip ───────────────────────────────────────────────────────────────
function renderSeedsStrip(lg,sA,sB){
  const el=document.getElementById('adm-seeds-strip-'+lg);if(!el)return;
  if(!sA.some(Boolean)&&!sB.some(Boolean)){el.innerHTML='';return;}
  const row=(seeds,conf)=>seeds.map((t,i)=>`<div class="seed-slot"><span class="snum ${conf}">#${i+1}${conf.toUpperCase()}</span>${t&&t.photo_url?`<img class="slogo" src="${t.photo_url}" onerror="this.style.display='none'">`:''}<span class="sname">${t?t.name:'—'}</span></div>`).join('');
  el.innerHTML=`<div class="seeds-conf-row">${row(Array.from({length:8},(_,i)=>sA[i]||null),'a')}</div><div class="seeds-conf-row">${row(Array.from({length:8},(_,i)=>sB[i]||null),'b')}</div>`;
}

// ── Conf picking grid ─────────────────────────────────────────────────────────
function renderAdminConfWrap(lg,sA,sB){
  const el=document.getElementById('adm-confWrap-'+lg);if(!el)return;
  const teams=TEAMS_BY_LEAGUE[lg]||[];
  const hasConf=teams.some(t=>t.conference);
  const tA=hasConf?teams.filter(t=>t.conference==='A'):teams.slice(0,Math.ceil(teams.length/2));
  const tB=hasConf?teams.filter(t=>t.conference==='B'):teams.slice(Math.ceil(teams.length/2));
  const idsA=sA.filter(Boolean).map(t=>t.id),idsB=sB.filter(Boolean).map(t=>t.id);
  const card=(t,conf,selArr)=>{
    const idx=selArr.indexOf(t.id);const sel=idx!==-1;
    const full=(conf==='A'?idsA:idsB).length>=8&&idx===-1;
    return`<div class="team-card${sel?' sel-'+conf.toLowerCase():''}${full?' full':''}" onclick="adminToggleSeed('${lg}','${conf}',${t.id})">
      <div class="seed-badge ${sel?conf.toLowerCase():''}">${sel?idx+1:''}</div>
      <div class="t-nm">${(t.city?t.city+' ':'')+t.name}</div>
    </div>`;
  };
  const sortBySeeds=(teams,ids)=>[...teams].sort((a,b)=>{const ia=ids.indexOf(a.id),ib=ids.indexOf(b.id);if(ia!==-1&&ib!==-1)return ia-ib;if(ia!==-1)return-1;if(ib!==-1)return 1;return 0;});
  el.innerHTML=`<div class="conf-section"><div class="conf-label a"><i class="bi bi-shield-fill"></i>LESTE <span style="color:var(--text-3);font-weight:400">${idsA.length}/8</span></div><div class="teams-grid">${sortBySeeds(tA,idsA).map(t=>card(t,'A',idsA)).join('')}</div></div>
  <div class="conf-section"><div class="conf-label b"><i class="bi bi-shield-fill"></i>OESTE <span style="color:var(--text-3);font-weight:400">${idsB.length}/8</span></div><div class="teams-grid">${sortBySeeds(tB,idsB).map(t=>card(t,'B',idsB)).join('')}</div></div>`;
  const btn=document.getElementById('adm-btnStart-'+lg);if(btn)btn.disabled=idsA.length<8||idsB.length<8;
}

function adminToggleSeed(lg,conf,teamId){
  const state=loadLS(lg)||{phase:'picking',seedsA:[],seedsB:[]};
  if(state.phase!=='picking')return;
  const teams=TEAMS_BY_LEAGUE[lg]||[];const team=teams.find(t=>t.id===teamId);if(!team)return;
  const arr=conf==='A'?state.seedsA:state.seedsB;
  const idx=arr.findIndex(t=>t&&t.id===teamId);
  if(idx!==-1)arr.splice(idx,1);else{if(arr.length>=8)return;arr.push(team);}
  saveLS(lg,state);renderAdminConfWrap(lg,state.seedsA,state.seedsB);
}

// ── Build rounds ──────────────────────────────────────────────────────────────
function buildRounds(sA,sB){
  const e=()=>({t1:null,t2:null,w:null});
  return{
    r1:[{t1:sA[0],t2:sA[7],w:null},{t1:sA[3],t2:sA[4],w:null},{t1:sA[1],t2:sA[6],w:null},{t1:sA[2],t2:sA[5],w:null},
        {t1:sB[0],t2:sB[7],w:null},{t1:sB[3],t2:sB[4],w:null},{t1:sB[1],t2:sB[6],w:null},{t1:sB[2],t2:sB[5],w:null}],
    r2:[e(),e(),e(),e()],r3:[e(),e()],r4:[e()]
  };
}
function adminStartBracket(lg){
  const state=loadLS(lg)||{};const sA=state.seedsA||[],sB=state.seedsB||[];
  if(sA.length<8||sB.length<8)return;
  state.rounds=buildRounds(sA,sB);state.phase='bracket';saveLS(lg,state);
  document.getElementById('adm-picking-'+lg).style.display='none';
  document.getElementById('adm-bracketPhase-'+lg).style.display='block';
  renderAdminBracket(lg,state);
}
function adminResetBracket(lg){
  if(!confirm('Resetar bracket oficial de '+lg+'? O resultado salvo no banco não será apagado.'))return;
  clearLS(lg);
  document.getElementById('adm-picking-'+lg).style.display='block';
  document.getElementById('adm-bracketPhase-'+lg).style.display='none';
  renderAdminConfWrap(lg,[],[]);
}

// ── Pick winner ───────────────────────────────────────────────────────────────
function clearDownstream(rounds,round,idx){
  const adv=ADV[round]&&ADV[round][idx];if(!adv)return;
  const next=rounds[adv.r]&&rounds[adv.r][adv.i];if(!next)return;
  next[adv.s]=null;
  if(next.w){next.w=null;clearDownstream(rounds,adv.r,adv.i);}
}
function adminPickWinner(lg,round,idx,teamId){
  const state=loadLS(lg);if(!state||state.phase!=='bracket')return;
  const match=state.rounds[round][idx];if(!match.t1||!match.t2)return;
  const winner=[match.t1,match.t2].find(t=>t.id===teamId);if(!winner)return;
  if(match.w&&match.w.id===winner.id)return;
  if(match.w)clearDownstream(state.rounds,round,idx);
  match.w=winner;
  const adv=ADV[round]&&ADV[round][idx];
  if(adv){state.rounds[adv.r][adv.i][adv.s]=winner;}
  saveLS(lg,state);renderAdminBracket(lg,state);autoSaveOfficial(lg,state);
}

// ── Auto-save to DB ───────────────────────────────────────────────────────────
async function autoSaveOfficial(lg,state){
  const cid=CYCLES[lg]&&CYCLES[lg].id;if(!cid)return;
  const seeds8=[...(state.seedsA||[]),...(state.seedsB||[])];
  try {
    const resp=await fetch('bracket-admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({acao:'save_official_picks',cycle_id:cid,seeds:JSON.stringify(seeds8),rounds:JSON.stringify(state.rounds)})});
    const data=await resp.json();
    if(data.ok)showSaved('brk-saved-'+lg);
    else showToast(data.msg||'Erro ao salvar','red');
  } catch(e){showToast('Erro de rede','red');}
}

// ── Render bracket ────────────────────────────────────────────────────────────
function matchupHtml(lg,round,idx,match,confTag){
  const done=!!match.w;
  const row=(team,isWin)=>{
    if(!team)return`<div class="m-team tbd">${logoHtml(null,26)}<div class="m-info"><div class="m-name" style="color:var(--text-3);font-style:italic">A definir</div></div></div>`;
    const cls=done?(isWin?'winner':'loser'):'';
    return`<div class="m-team ${cls}" onclick="adminPickWinner('${lg}','${round}',${idx},${team.id})">
      ${logoHtml(team,26)}<div class="m-info"><div class="m-seed">${team.city||''}</div><div class="m-name">${team.name}</div></div>
      ${isWin?'<i class="bi bi-check-circle-fill m-check"></i>':''}
    </div>`;
  };
  const w1=match.w&&match.t1&&match.w.id===match.t1.id,w2=match.w&&match.t2&&match.w.id===match.t2.id;
  return`<div class="matchup">${confTag?`<span class="conf-tag ${confTag.toLowerCase()}">${confTag==='A'?'Conf A':'Conf B'}</span>`:''}<div>${row(match.t1,w1)}${row(match.t2,w2)}</div></div>`;
}
function renderAdminBracket(lg,state){
  const wrap=document.getElementById('adm-bracketWrap-'+lg);if(!wrap)return;
  const r=state.rounds;
  const champ=r.r4&&r.r4[0]&&r.r4[0].w;
  const mh=(round,idx,match,tag)=>matchupHtml(lg,round,idx,match,tag);

  if(window.innerWidth>768){
    // ── Desktop: tree layout ──
    let h='<div class="brk-tree">';
    h+=`<div class="brk-col"><div class="brk-col-head">1ª Rodada</div><div class="brk-col-body">`;
    r.r1.forEach((m,i)=>h+=`<div class="brk-m">${mh('r1',i,m,i<4?'A':'B')}</div>`);
    h+=`</div></div>`;
    h+=`<div class="brk-conns">`;for(let i=0;i<4;i++)h+=`<div class="brk-conn"></div>`;h+=`</div>`;
    h+=`<div class="brk-col"><div class="brk-col-head">Quartas</div><div class="brk-col-body">`;
    r.r2.forEach((m,i)=>h+=`<div class="brk-m">${mh('r2',i,m,i<2?'A':'B')}</div>`);
    h+=`</div></div>`;
    h+=`<div class="brk-conns">`;for(let i=0;i<2;i++)h+=`<div class="brk-conn"></div>`;h+=`</div>`;
    h+=`<div class="brk-col"><div class="brk-col-head">Finais de Conf</div><div class="brk-col-body">`;
    r.r3.forEach((m,i)=>h+=`<div class="brk-m">${mh('r3',i,m,i===0?'A':'B')}</div>`);
    h+=`</div></div>`;
    h+=`<div class="brk-conns"><div class="brk-conn"></div></div>`;
    h+=`<div class="brk-col"><div class="brk-col-head">🏆 Final</div><div class="brk-col-body"><div class="brk-m">${mh('r4',0,r.r4[0],null)}</div></div></div>`;
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
    if(champ)h+=`<div class="champ-wrap"><div class="champ-card"><div class="champ-crown">🏆</div><div class="champ-lbl">Campeão Oficial</div>${champ.photo_url?`<img class="champ-logo" src="${champ.photo_url}" onerror="this.style.display='none'">`:''}<div class="champ-name">${champ.name}</div><div class="champ-city">${champ.city||''}</div></div></div>`;
    wrap.innerHTML=h;
  }
}

// ── Auto-assign conferences ───────────────────────────────────────────────────
async function autoAssignConferences(lg){
  if(!confirm('Auto-atribuir conferências de '+lg+' com base em LESTE→A / OESTE→B?'))return;
  const resp=await fetch('bracket-admin.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({acao:'auto_assign_conferences',league:lg})});
  const data=await resp.json();
  if(data.ok){showToast(data.msg,'green');setTimeout(()=>location.reload(),1200);}
  else showToast(data.msg||'Erro','red');
}

// ── Sidebar ───────────────────────────────────────────────────────────────────
function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('sbOverlay').classList.add('open');document.body.style.overflow='hidden';}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sbOverlay').classList.remove('open');document.body.style.overflow='';}

// ── Force reset ───────────────────────────────────────────────────────────────
async function forceReset(lg){
  if(!confirm('Resetar bracket '+lg+'? Pontos serão pagos e novo ciclo criado.'))return;
  const body=new URLSearchParams({acao:'force_reset',league:lg});
  const resp=await fetch('bracket-admin.php',{method:'POST',body});
  const data=await resp.json();
  if(data.ok){showToast(data.msg,'green');setTimeout(()=>location.reload(),1500);}
  else showToast(data.msg||'Erro','red');
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded',()=>{
  LEAGUES.forEach(lg=>{
    // Try to restore from localStorage first, then fall back to DB official data
    let state=loadLS(lg);
    if(!state&&OFFICIAL_RAW[lg]){
      const raw=OFFICIAL_RAW[lg];
      try {
        const seeds=raw.seeds?JSON.parse(raw.seeds):null;
        const rounds=raw.rounds?JSON.parse(raw.rounds):null;
        if(seeds&&seeds.length===16){
          state={phase:'bracket',seedsA:seeds.slice(0,8),seedsB:seeds.slice(8,16),rounds:rounds||buildRounds(seeds.slice(0,8),seeds.slice(8,16))};
          saveLS(lg,state);
        }
      } catch(e){}
    }
    if(state&&state.phase==='bracket'&&state.rounds){
      document.getElementById('adm-picking-'+lg).style.display='none';
      document.getElementById('adm-bracketPhase-'+lg).style.display='block';
      renderAdminBracket(lg,state);
    } else {
      const sA=state?.seedsA||[],sB=state?.seedsB||[];
      renderAdminConfWrap(lg,sA,sB);
    }
  });
});
</script>
</body>
</html>
