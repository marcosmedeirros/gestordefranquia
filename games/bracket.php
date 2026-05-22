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
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_bracket_cycles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        league VARCHAR(20) NOT NULL,
        cycle_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status ENUM('open','closed') NOT NULL DEFAULT 'open',
        winner_user_id INT NULL,
        points_paid TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_league (league, status)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_bracket_picks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cycle_id INT NOT NULL,
        user_id INT NOT NULL,
        seeds_json TEXT NOT NULL,
        rounds_json TEXT NOT NULL,
        points INT NOT NULL DEFAULT 0,
        locked TINYINT(1) NOT NULL DEFAULT 0,
        locked_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_cycle_user (cycle_id, user_id),
        INDEX idx_cycle (cycle_id)
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_bracket_official (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cycle_id INT NOT NULL UNIQUE,
        seeds_json TEXT NULL,
        rounds_json TEXT NULL,
        updated_by INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fba_bracket_conferences (
        team_id INT NOT NULL,
        league VARCHAR(20) NOT NULL,
        conference CHAR(1) NOT NULL DEFAULT 'A',
        PRIMARY KEY (team_id, league)
    )");
} catch (Exception $e) {}

// ── AJAX ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['acao'])) {
    header('Content-Type: application/json');
    $acao = $_POST['acao'];

    if ($acao === 'save_picks') {
        $cycle_id = (int)($_POST['cycle_id'] ?? 0);
        $seeds    = $_POST['seeds'] ?? '';
        $rounds   = $_POST['rounds'] ?? '';
        $lock     = !empty($_POST['lock']);
        $seedsArr = @json_decode($seeds, true);
        $roundsArr = @json_decode($rounds, true);
        if (!is_array($seedsArr) || count($seedsArr) !== 8 || !is_array($roundsArr)) {
            echo json_encode(['ok'=>false,'msg'=>'Dados inválidos']); exit;
        }
        $stmt = $pdo->prepare("SELECT id, locked FROM fba_bracket_picks WHERE cycle_id=? AND user_id=?");
        $stmt->execute([$cycle_id, $user_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing && $existing['locked']) {
            echo json_encode(['ok'=>false,'msg'=>'Bracket já salvo e bloqueado']); exit;
        }
        if ($existing) {
            $pdo->prepare("UPDATE fba_bracket_picks SET seeds_json=?,rounds_json=?,locked=?,locked_at=?,updated_at=NOW() WHERE id=?")
                ->execute([$seeds,$rounds,$lock?1:0,$lock?date('Y-m-d H:i:s'):null,$existing['id']]);
        } else {
            $pdo->prepare("INSERT INTO fba_bracket_picks (cycle_id,user_id,seeds_json,rounds_json,locked,locked_at) VALUES (?,?,?,?,?,?)")
                ->execute([$cycle_id,$user_id,$seeds,$rounds,$lock?1:0,$lock?date('Y-m-d H:i:s'):null]);
        }
        echo json_encode(['ok'=>true]); exit;
    }
    exit;
}

// ── RESET / CYCLE MANAGEMENT ──────────────────────────────────────────────────
// ELITE=Thu(4), NEXT=Sat(6), RISE=Wed(3)
$resetSchedule = ['ELITE'=>4,'NEXT'=>6,'RISE'=>3];

function lastResetTime(int $dow): DateTime {
    $now = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $c = clone $now;
    $c->setTime(23,59,0);
    for ($i=0; $i<8; $i++) {
        if ((int)$c->format('N') === $dow && $c <= $now) return $c;
        $c->modify('-1 day');
    }
    return $c;
}

function closeCycleIfExpired(PDO $pdo, array $cycle, ?int $resetDow): void {
    if ($cycle['points_paid'] || $cycle['status'] === 'closed') return;
    if ($resetDow === null) return;
    $lastReset = lastResetTime($resetDow);
    $cycleStart = new DateTime($cycle['cycle_start'], new DateTimeZone('America/Sao_Paulo'));
    if ($cycleStart >= $lastReset) return;
    // Award points to winner
    $stmt = $pdo->prepare("SELECT user_id, MAX(points) as pts FROM fba_bracket_picks WHERE cycle_id=? AND locked=1 GROUP BY user_id ORDER BY pts DESC LIMIT 1");
    $stmt->execute([$cycle['id']]);
    $winner = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($winner && (int)$winner['pts'] > 0) {
        $pdo->prepare("UPDATE usuarios SET fba_points=fba_points+100 WHERE id=?")->execute([$winner['user_id']]);
        $pdo->prepare("UPDATE fba_bracket_cycles SET status='closed',winner_user_id=?,points_paid=1 WHERE id=?")->execute([$winner['user_id'],$cycle['id']]);
    } else {
        $pdo->prepare("UPDATE fba_bracket_cycles SET status='closed' WHERE id=?")->execute([$cycle['id']]);
    }
}

function getOrCreateCycle(PDO $pdo, string $league, ?int $resetDow): array {
    $stmt = $pdo->prepare("SELECT * FROM fba_bracket_cycles WHERE league=? AND status='open' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$league]);
    $cycle = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cycle) {
        closeCycleIfExpired($pdo, $cycle, $resetDow);
        $stmt->execute([$league]);
        $cycle = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$cycle) {
        $pdo->prepare("INSERT INTO fba_bracket_cycles (league,cycle_start,status) VALUES (?,NOW(),'open')")->execute([$league]);
        $id = (int)$pdo->lastInsertId();
        $cycle = ['id'=>$id,'league'=>$league,'status'=>'open','cycle_start'=>date('Y-m-d H:i:s'),'winner_user_id'=>null,'points_paid'=>0];
    }
    return $cycle;
}

function calcPoints(array $userPick, array $official): int {
    $pts = 0;
    $us = @json_decode($userPick['seeds_json'], true);
    $ur = @json_decode($userPick['rounds_json'], true);
    $os = @json_decode($official['seeds_json'], true);
    $or_ = @json_decode($official['rounds_json'], true);
    if (!is_array($us)||!is_array($os)) return 0;
    $correctIds = [];
    for ($i=0; $i<8; $i++) {
        if (isset($us[$i]['id'],$os[$i]['id']) && $us[$i]['id']==$os[$i]['id']) { $pts++; $correctIds[] = (int)$us[$i]['id']; }
    }
    if (!is_array($ur)||!is_array($or_)) return $pts;
    foreach (['r1','r2','r3'] as $rnd) {
        foreach (($ur[$rnd]??[]) as $i=>$um) {
            $om = $or_[$rnd][$i] ?? null;
            if (!$om) continue;
            $uw = $um['w']['id'] ?? null;
            $ow = $om['w']['id'] ?? null;
            if ($uw && $ow && $uw==$ow && in_array((int)$uw,$correctIds)) $pts+=2;
        }
    }
    return $pts;
}

// ── LOAD DATA ─────────────────────────────────────────────────────────────────
$leagues = [];
$teamsByLeague = [];
$confAssignments = []; // [league][team_id] = 'A'|'B'

try {
    $rows = $pdo->query("SELECT team_id, league, conference FROM fba_bracket_conferences")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) $confAssignments[$r['league']][$r['team_id']] = $r['conference'];
} catch(Exception $e) {}

try {
    $pdoFba = new PDO('mysql:host=localhost;dbname=u289267434_fbabrasilbanco;charset=utf8mb4',
        'u289267434_fbabrasilbanco','Fbabrasil@2025',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $rows = $pdoFba->query("SELECT id,name,city,league,photo_url FROM teams ORDER BY league,name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $lg = $r['league'];
        if (!in_array($lg,$leagues)) $leagues[] = $lg;
        $teamsByLeague[$lg][] = ['id'=>(int)$r['id'],'name'=>$r['name'],'city'=>$r['city'],
            'photo_url'=>$r['photo_url']?:'','conference'=>$confAssignments[$lg][(int)$r['id']]??null];
    }
} catch(Exception $e) {}

$cycles = [];
$userPicks = [];
$rankings = [];
$officialResults = [];

foreach ($leagues as $lg) {
    $dow = $resetSchedule[$lg] ?? null;
    $cycles[$lg] = getOrCreateCycle($pdo, $lg, $dow);
    $cid = $cycles[$lg]['id'];

    $stmt = $pdo->prepare("SELECT * FROM fba_bracket_picks WHERE cycle_id=? AND user_id=?");
    $stmt->execute([$cid,$user_id]);
    $pick = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt2 = $pdo->prepare("SELECT * FROM fba_bracket_official WHERE cycle_id=?");
    $stmt2->execute([$cid]);
    $official = $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
    $officialResults[$lg] = $official;

    if ($pick && $official) {
        $newPts = calcPoints($pick, $official);
        if ($newPts !== (int)$pick['points']) {
            $pdo->prepare("UPDATE fba_bracket_picks SET points=? WHERE id=?")->execute([$newPts,$pick['id']]);
            $pick['points'] = $newPts;
        }
    }
    $userPicks[$lg] = $pick ?: null;

    $stmt3 = $pdo->prepare("SELECT p.user_id, u.nome, p.points, p.locked, p.locked_at FROM fba_bracket_picks p JOIN usuarios u ON u.id=p.user_id WHERE p.cycle_id=? ORDER BY p.points DESC, p.locked_at ASC LIMIT 20");
    $stmt3->execute([$cid]);
    $rankings[$lg] = $stmt3->fetchAll(PDO::FETCH_ASSOC);
}

// ── Next reset labels ─────────────────────────────────────────────────────────
$dayNames = [1=>'Segunda',2=>'Terça',3=>'Quarta',4=>'Quinta',5=>'Sexta',6=>'Sábado',7=>'Domingo'];
$nextReset = [];
foreach ($leagues as $lg) {
    $dow = $resetSchedule[$lg] ?? null;
    if (!$dow) { $nextReset[$lg] = ''; continue; }
    $d = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    for ($i=0; $i<8; $i++) {
        if ((int)$d->format('N') === $dow) { $nextReset[$lg] = $dayNames[$dow].' às 23:59'; break; }
        $d->modify('+1 day');
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#fc0025">
<title>Bracket — FBA Games</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:rgba(252,0,37,.10);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;
     --border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--text:#f0f0f3;--text-2:#868690;--text-3:#48484f;
     --green:#22c55e;--amber:#f59e0b;--blue:#3b82f6;--font:'Poppins',sans-serif;--radius:14px;--radius-sm:10px;
     --ease:cubic-bezier(.2,.8,.2,1);--t:200ms;--sw:220px}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{background:var(--bg);color:var(--text);font-family:var(--font);-webkit-font-smoothing:antialiased}
/* Sidebar */
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
/* Page */
.page{margin-left:var(--sw);min-height:100vh;display:flex;flex-direction:column}
.topbar{height:52px;background:var(--panel);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 20px;flex-shrink:0}
.topbar-title{font-size:15px;font-weight:800;color:var(--text)}.topbar-title span{color:var(--red)}
.mob-bar{display:none;height:52px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 14px;gap:10px;position:sticky;top:0;z-index:100}
.mob-ham{background:none;border:1px solid var(--border);border-radius:8px;color:var(--text);width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px}
.mob-title{font-size:14px;font-weight:800;color:var(--text);flex:1}.mob-title span{color:var(--red)}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:199;backdrop-filter:blur(2px)}
.sb-overlay.open{display:block}
.main{flex:1;padding:20px 24px 60px;max-width:1000px;margin:0 auto;width:100%}
/* Tabs */
.tab-bar{display:flex;gap:4px;background:var(--panel-2);border:1px solid var(--border);border-radius:999px;padding:3px;margin-bottom:24px;width:fit-content}
.tab-btn{padding:7px 20px;border-radius:999px;border:none;background:transparent;color:var(--text-2);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease)}
.tab-btn.active{background:var(--red);color:#fff;box-shadow:0 2px 12px rgba(252,0,37,.3)}
/* Reset info */
.reset-info{display:flex;align-items:center;gap:7px;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px 14px;font-size:11px;color:var(--text-2);margin-bottom:20px;width:fit-content}
.reset-info i{color:var(--amber);font-size:12px}
/* Phase header */
.phase-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;gap:10px;flex-wrap:wrap}
.phase-title{font-size:16px;font-weight:800;color:var(--text)}.phase-title span{color:var(--red)}
.phase-sub{font-size:12px;color:var(--text-2);margin-top:2px}
.btn-reset{background:transparent;border:1px solid var(--border-md);border-radius:var(--radius-sm);padding:7px 14px;color:var(--text-2);font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease);display:flex;align-items:center;gap:6px}
.btn-reset:hover{border-color:rgba(239,68,68,.4);color:#f87171;background:rgba(239,68,68,.07)}
/* Conferences grid */
.conf-wrap{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
.conf-section{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:14px}
.conf-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;display:flex;align-items:center;gap:6px}
.conf-label.a{color:#3b82f6}.conf-label.b{color:#f59e0b}
.teams-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:8px}
.team-card{background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 7px;display:flex;flex-direction:column;align-items:center;gap:6px;cursor:pointer;transition:all var(--t) var(--ease);position:relative;user-select:none}
.team-card:hover{border-color:var(--border-md)}.team-card.selected-a{border-color:#3b82f6;background:rgba(59,130,246,.08)}
.team-card.selected-b{border-color:#f59e0b;background:rgba(245,158,11,.08)}.team-card.full{opacity:.4;pointer-events:none}
.seed-badge{position:absolute;top:5px;right:5px;width:18px;height:18px;border-radius:50%;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;color:#fff}
.seed-badge.a{background:#3b82f6}.seed-badge.b{background:#f59e0b}
.team-logo{width:42px;height:42px;border-radius:50%;object-fit:cover;background:var(--panel-3)}
.team-logo-ph{width:42px;height:42px;border-radius:50%;background:var(--panel-3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:var(--text-3)}
.team-name{font-size:10px;font-weight:700;color:var(--text);text-align:center;line-height:1.3}
/* Seeds strip */
.seeds-strip{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:16px;padding:10px 14px;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius)}
.seed-slot{display:flex;align-items:center;gap:5px;padding:4px 9px;border-radius:7px;border:1px solid var(--border-md);background:var(--panel-2)}
.seed-num{font-size:10px;font-weight:800;min-width:12px}
.seed-num.a{color:#3b82f6}.seed-num.b{color:#f59e0b}
.seed-slogo{width:20px;height:20px;border-radius:50%;object-fit:cover;flex-shrink:0}
.seed-sname{font-size:10px;font-weight:600;color:var(--text);max-width:70px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* Btn start */
.btn-start{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;max-width:400px;padding:13px;background:var(--red);color:#fff;border:none;border-radius:var(--radius-sm);font-family:var(--font);font-size:14px;font-weight:700;cursor:pointer;transition:opacity var(--t) var(--ease);margin-bottom:20px}
.btn-start:hover{opacity:.85}.btn-start:disabled{opacity:.35;cursor:not-allowed}
/* Bracket */
.bracket-wrap{display:flex;flex-direction:column;gap:20px}
.round-block{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.round-head{padding:10px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.round-head-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2)}
.round-grid{display:grid;gap:1px;background:var(--border)}
.round-grid.g2{grid-template-columns:1fr 1fr}
.round-grid.g1{grid-template-columns:1fr}
.matchup{background:var(--panel);padding:0}
.matchup-inner{padding:4px 0}
.conf-tag{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;padding:5px 14px 3px;display:block}
.conf-tag.a{color:#3b82f6}.conf-tag.b{color:#f59e0b}
.m-team{display:flex;align-items:center;gap:10px;padding:9px 14px;cursor:pointer;transition:background var(--t) var(--ease);position:relative}
.m-team:not(.locked):hover{background:var(--panel-2)}.m-team+.m-team{border-top:1px solid var(--border)}
.m-team.winner{background:rgba(34,197,94,.07);border-left:3px solid var(--green)}.m-team.loser{opacity:.35}
.m-team.locked{cursor:default}.m-team.tbd{cursor:default;opacity:.5}
.m-logo{width:30px;height:30px;border-radius:50%;object-fit:cover;flex-shrink:0;background:var(--panel-2)}
.m-logo-ph{width:30px;height:30px;border-radius:50%;background:var(--panel-3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;color:var(--text-3);flex-shrink:0}
.m-info{flex:1;min-width:0}.m-seed{font-size:9px;font-weight:600;color:var(--text-3)}.m-name{font-size:12px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.m-check{margin-left:auto;color:var(--green);font-size:15px;flex-shrink:0}
/* Action bar */
.action-bar{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}
.btn-save{display:flex;align-items:center;gap:7px;padding:11px 22px;background:var(--green);color:#000;border:none;border-radius:var(--radius-sm);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:opacity var(--t) var(--ease)}
.btn-save:hover{opacity:.85}.btn-save:disabled{opacity:.4;cursor:not-allowed}
.btn-share{display:flex;align-items:center;gap:7px;padding:11px 22px;background:rgba(37,211,102,.15);border:1px solid rgba(37,211,102,.3);color:#25d366;border-radius:var(--radius-sm);font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:all var(--t) var(--ease)}
.btn-share:hover{background:rgba(37,211,102,.25)}
.locked-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);color:var(--green);padding:7px 14px;border-radius:var(--radius-sm);font-size:12px;font-weight:700}
/* Champion */
.champion-wrap{margin-top:20px;text-align:center}
.champion-card{display:inline-flex;flex-direction:column;align-items:center;gap:8px;background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(252,0,37,.08));border:1px solid rgba(245,158,11,.3);border-radius:var(--radius);padding:24px 32px}
.champion-crown{font-size:28px;line-height:1}.champion-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--amber)}
.champion-logo{width:64px;height:64px;border-radius:50%;object-fit:cover;border:3px solid var(--amber);background:var(--panel-2)}
.champion-name{font-size:18px;font-weight:800;color:#fff}.champion-city{font-size:12px;color:var(--text-2)}
/* Points badge */
.pts-badge{display:inline-flex;align-items:center;gap:5px;background:var(--panel-3);border:1px solid var(--border-md);border-radius:999px;padding:4px 10px;font-size:12px;font-weight:700;color:var(--amber)}
/* Ranking */
.ranking-block{margin-top:32px;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.ranking-head{padding:13px 16px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px}
.ranking-row{display:flex;align-items:center;gap:10px;padding:10px 16px;transition:background var(--t) var(--ease)}
.ranking-row+.ranking-row{border-top:1px solid var(--border)}.ranking-row:hover{background:var(--panel-2)}
.rank-pos{font-size:13px;font-weight:800;color:var(--text-3);width:20px;text-align:center;flex-shrink:0}
.rank-pos.top1{color:var(--amber)}.rank-pos.top2{color:#94a3b8}.rank-pos.top3{color:#cd7f32}
.rank-name{flex:1;font-size:13px;font-weight:600;color:var(--text);min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rank-pts{font-size:13px;font-weight:800;color:var(--amber)}.rank-lock{font-size:11px;color:var(--green);margin-left:4px}
.rank-empty{padding:20px 16px;text-align:center;font-size:12px;color:var(--text-3)}
/* Toast */
.toast{position:fixed;bottom:24px;right:24px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);padding:12px 18px;font-size:13px;font-weight:600;color:var(--text);z-index:9999;opacity:0;transform:translateY(10px);transition:all .3s;pointer-events:none}
.toast.show{opacity:1;transform:translateY(0)}
.toast.green{border-color:rgba(34,197,94,.3);color:var(--green)}.toast.red{border-color:rgba(252,0,37,.3);color:#ff6680}
/* Mobile */
@media(max-width:768px){
  html,body{height:auto}
  .sidebar{transform:translateX(-100%);transition:transform 280ms var(--ease)}.sidebar.open{transform:translateX(0)}
  .sb-close{display:flex}.page{margin-left:0}.topbar{display:none}.mob-bar{display:flex}
  .main{padding:14px 12px 60px}
  .tab-bar{width:100%}.tab-btn{flex:1;padding:7px 8px;font-size:12px}
  .conf-wrap{grid-template-columns:1fr}
  .round-grid.g2{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="toast" id="toast"></div>
<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-logo">FBA</div>
    <span class="sb-brand">FBA <span>Games</span></span>
    <button class="sb-close" onclick="closeSidebar()"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?= strtoupper(substr($usuario['nome']??'U',0,1)) ?></div>
    <div class="sb-user-name"><?= htmlspecialchars($usuario['nome']??'') ?></div>
    <div class="sb-user-role"><?= !empty($usuario['is_admin'])?'Admin':'Jogador' ?></div>
  </div>
  <div class="sb-stats">
    <div class="sb-stat"><i class="bi bi-hand-index-fill" style="color:var(--green)"></i>
      <div class="sb-stat-val"><?= number_format($usuario['numero_tapas']??0,0,',','.') ?></div><div class="sb-stat-label">Tapas</div></div>
    <div class="sb-stat"><img src="moeda.png" style="width:18px;height:18px;object-fit:contain;vertical-align:middle">
      <div class="sb-stat-val"><?= number_format($usuario['pontos']??0,0,',','.') ?></div><div class="sb-stat-label">Moedas</div></div>
    <div class="sb-stat"><img src="lebron.png" style="width:18px;height:18px;object-fit:contain;vertical-align:middle">
      <div class="sb-stat-val"><?= number_format($usuario['fba_points']??0,0,',','.') ?></div><div class="sb-stat-label">FBA Pts</div></div>
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
  <div class="sb-footer">
    <a href="auth/logout.php" class="sb-logout"><i class="bi bi-box-arrow-right"></i>Sair</a>
  </div>
</aside>

<div class="page">
  <div class="topbar"><div class="topbar-title">Bracket <span>Playoffs</span></div></div>
  <div class="mob-bar">
    <button class="mob-ham" onclick="openSidebar()"><i class="bi bi-list"></i></button>
    <span class="mob-title">Bracket <span>Playoffs</span></span>
  </div>

  <div class="main">
    <?php if (empty($leagues)): ?>
    <div style="text-align:center;padding:48px 20px;color:var(--text-3)"><i class="bi bi-exclamation-circle" style="font-size:32px;display:block;margin-bottom:10px"></i><p style="font-size:13px">Nenhuma liga encontrada.</p></div>
    <?php else: ?>

    <div class="tab-bar" id="tabBar">
      <?php foreach ($leagues as $i=>$lg): ?>
      <button class="tab-btn <?= $i===0?'active':'' ?>" onclick="switchTab('<?= $lg ?>')" id="tab-<?= $lg ?>"><?= htmlspecialchars($lg) ?></button>
      <?php endforeach; ?>
    </div>

    <?php foreach ($leagues as $i=>$lg):
      $cycle   = $cycles[$lg];
      $myPick  = $userPicks[$lg];
      $ranking = $rankings[$lg];
      $isLocked = $myPick && $myPick['locked'];
      $hasSavedState = $myPick && !$myPick['locked'];
      // check conferences set for this league
      $hasConf = false;
      foreach (($teamsByLeague[$lg]??[]) as $t) { if ($t['conference']) { $hasConf=true; break; } }
    ?>
    <div class="tab-panel" id="panel-<?= $lg ?>" style="display:<?= $i===0?'block':'none' ?>">

      <?php if (!empty($nextReset[$lg])): ?>
      <div class="reset-info"><i class="bi bi-arrow-repeat"></i>Próximo reset: <strong><?= htmlspecialchars($nextReset[$lg]) ?></strong></div>
      <?php endif; ?>

      <?php if ($isLocked): ?>
      <!-- ── LOCKED: show bracket read-only ── -->
      <div class="phase-header">
        <div>
          <div class="phase-title">Seu Bracket <span><?= htmlspecialchars($lg) ?></span></div>
          <div class="phase-sub">Salvo e bloqueado <?= $myPick['locked_at'] ? '· '.date('d/m H:i',strtotime($myPick['locked_at'])) : '' ?></div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <span class="locked-badge"><i class="bi bi-lock-fill"></i>Bloqueado</span>
          <?php if ((int)$myPick['points']>0): ?>
          <span class="pts-badge"><i class="bi bi-star-fill"></i><?= $myPick['points'] ?> pts</span>
          <?php endif; ?>
        </div>
      </div>
      <div id="bracket-locked-<?= $lg ?>"></div>
      <div class="action-bar" id="actionBar-<?= $lg ?>">
        <button class="btn-share" onclick="shareBracket('<?= $lg ?>')"><i class="bi bi-whatsapp"></i>Compartilhar no WhatsApp</button>
      </div>
      <?php else: ?>
      <!-- ── PICKING/BUILDING ── -->
      <div class="phase-header">
        <div>
          <div class="phase-title" id="phaseTitle-<?= $lg ?>">Montar <span>Bracket</span></div>
          <div class="phase-sub" id="phaseSub-<?= $lg ?>">Selecione os 4 seeds de cada conferência</div>
        </div>
        <button class="btn-reset" onclick="resetBracket('<?= $lg ?>')"><i class="bi bi-arrow-counterclockwise"></i>Resetar</button>
      </div>
      <div id="seeds-strip-<?= $lg ?>" class="seeds-strip"></div>
      <!-- Picking phase -->
      <div id="pickingPhase-<?= $lg ?>">
        <div class="conf-wrap" id="confWrap-<?= $lg ?>"></div>
        <button class="btn-start" id="btnStart-<?= $lg ?>" disabled onclick="startBracket('<?= $lg ?>')"><i class="bi bi-play-fill"></i>Gerar Bracket</button>
      </div>
      <!-- Bracket phase -->
      <div id="bracketPhase-<?= $lg ?>" style="display:none">
        <div class="bracket-wrap" id="bracketWrap-<?= $lg ?>"></div>
        <div class="action-bar">
          <button class="btn-save" id="btnSave-<?= $lg ?>" onclick="saveBracket('<?= $lg ?>',true)" disabled><i class="bi bi-lock-fill"></i>Salvar e Bloquear</button>
          <button class="btn-share" id="btnShare-<?= $lg ?>" style="display:none" onclick="shareBracket('<?= $lg ?>')"><i class="bi bi-whatsapp"></i>Compartilhar</button>
        </div>
      </div>
      <?php endif; ?>

      <!-- Ranking -->
      <div class="ranking-block">
        <div class="ranking-head"><i class="bi bi-bar-chart-fill" style="color:var(--amber)"></i>Ranking — <?= htmlspecialchars($lg) ?></div>
        <?php if (empty($ranking)): ?>
        <div class="rank-empty">Nenhum bracket salvo ainda.</div>
        <?php else: foreach ($ranking as $ri=>$rrow): $rpos=$ri+1; ?>
        <div class="ranking-row">
          <div class="rank-pos <?= $rpos===1?'top1':($rpos===2?'top2':($rpos===3?'top3':'')) ?>"><?= $rpos ?>º</div>
          <div class="rank-name"><?= htmlspecialchars($rrow['nome']) ?><?= $rrow['user_id']==$user_id?' (você)':'' ?></div>
          <div class="rank-pts"><?= $rrow['points'] ?> pts</div>
          <?php if ($rrow['locked']): ?><i class="bi bi-lock-fill rank-lock"></i><?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>

    </div><!-- /panel -->
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
const TEAMS_BY_LEAGUE = <?= json_encode($teamsByLeague, JSON_UNESCAPED_UNICODE) ?>;
const LEAGUES         = <?= json_encode($leagues, JSON_UNESCAPED_UNICODE) ?>;
const CYCLES          = <?= json_encode(array_combine($leagues, array_map(fn($lg)=>['id'=>$cycles[$lg]['id'],'league'=>$lg], $leagues)), JSON_UNESCAPED_UNICODE) ?>;
const USER_PICKS_RAW  = <?= json_encode(array_map(fn($p)=>$p?['seeds'=>$p['seeds_json'],'rounds'=>$p['rounds_json'],'locked'=>(bool)$p['locked'],'points'=>(int)$p['points']]:null, $userPicks), JSON_UNESCAPED_UNICODE) ?>;

// ── Helpers ──────────────────────────────────────────────────────────────────

function lsKey(lg) { return 'fba_brk_v3_' + lg; }
function loadLS(lg) { try { return JSON.parse(localStorage.getItem(lsKey(lg)))||null; } catch(e){return null;} }
function saveLS(lg, s) { localStorage.setItem(lsKey(lg), JSON.stringify(s)); }
function clearLS(lg) { localStorage.removeItem(lsKey(lg)); }

function imgHtml(t, sz=30) {
  if (!t) return `<div class="m-logo-ph" style="width:${sz}px;height:${sz}px">?</div>`;
  if (t.photo_url) return `<img class="m-logo" src="${t.photo_url}" style="width:${sz}px;height:${sz}px" onerror="this.outerHTML='<div class=\\'m-logo-ph\\'style=\\'width:${sz}px;height:${sz}px\\'>${(t.name||'?').slice(0,2).toUpperCase()}</div>'">`;
  return `<div class="m-logo-ph" style="width:${sz}px;height:${sz}px">${(t.name||'?').slice(0,2).toUpperCase()}</div>`;
}
function imgHtmlSmall(t) {
  if (!t||!t.photo_url) return '';
  return `<img class="seed-slogo" src="${t.photo_url}" onerror="this.style.display='none'">`;
}

function showToast(msg, type='') {
  const el = document.getElementById('toast');
  el.textContent = msg; el.className = 'toast show' + (type?' '+type:'');
  clearTimeout(el._t); el._t = setTimeout(()=>el.classList.remove('show'), 2500);
}

// ── Tab switch ────────────────────────────────────────────────────────────────
function switchTab(lg) {
  LEAGUES.forEach(l => {
    document.getElementById('panel-'+l).style.display = l===lg?'block':'none';
    document.getElementById('tab-'+l).classList.toggle('active', l===lg);
  });
}

// ── Seeds strip ───────────────────────────────────────────────────────────────
function renderSeedsStrip(lg, seedsA, seedsB) {
  const el = document.getElementById('seeds-strip-'+lg);
  if (!el) return;
  let html = '';
  for (let i=0; i<4; i++) {
    const t = seedsA[i]||null;
    html += `<div class="seed-slot"><span class="seed-num a">#${i+1}A</span>${t?imgHtmlSmall(t):'<div style="width:20px;height:20px;border-radius:50%;border:1px dashed var(--border-md)"></div>'}<span class="seed-sname">${t?t.name:'—'}</span></div>`;
  }
  for (let i=0; i<4; i++) {
    const t = seedsB[i]||null;
    html += `<div class="seed-slot"><span class="seed-num b">#${i+1}B</span>${t?imgHtmlSmall(t):'<div style="width:20px;height:20px;border-radius:50%;border:1px dashed var(--border-md)"></div>'}<span class="seed-sname">${t?t.name:'—'}</span></div>`;
  }
  el.innerHTML = html;
}

// ── Teams grid (picking) ──────────────────────────────────────────────────────
function renderConfWrap(lg, seedsA, seedsB) {
  const el = document.getElementById('confWrap-'+lg);
  if (!el) return;
  const teams = TEAMS_BY_LEAGUE[lg]||[];
  const hasConf = teams.some(t=>t.conference);
  const confA = hasConf ? teams.filter(t=>t.conference==='A') : teams.slice(0, Math.ceil(teams.length/2));
  const confB = hasConf ? teams.filter(t=>t.conference==='B') : teams.slice(Math.ceil(teams.length/2));

  const idsA = seedsA.filter(Boolean).map(t=>t.id);
  const idsB = seedsB.filter(Boolean).map(t=>t.id);

  function teamCard(t, conf, selIdx, otherFull) {
    const selected = selIdx !== -1;
    const fullA = idsA.length >= 4 && conf==='A' && selIdx===-1;
    const fullB = idsB.length >= 4 && conf==='B' && selIdx===-1;
    const full = fullA || fullB || otherFull;
    return `<div class="team-card${selected?' selected-'+conf.toLowerCase():''}${full?' full':''}" onclick="toggleSeed('${lg}','${conf}',${t.id})">
      ${selected?`<div class="seed-badge ${conf.toLowerCase()}">${selIdx+1}</div>`:''}
      ${t.photo_url?`<img class="team-logo" src="${t.photo_url}" style="width:42px;height:42px" onerror="this.outerHTML='<div class=\\'team-logo-ph\\'>${t.name.slice(0,2).toUpperCase()}</div>'">`:`<div class="team-logo-ph">${t.name.slice(0,2).toUpperCase()}</div>`}
      <div class="team-name">${t.name}</div>
    </div>`;
  }

  el.innerHTML = `
    <div class="conf-section">
      <div class="conf-label a"><i class="bi bi-shield-fill"></i>Conferência A <span style="color:var(--text-3);font-weight:400">${idsA.length}/4</span></div>
      <div class="teams-grid">${confA.map(t=>teamCard(t,'A',idsA.indexOf(t.id),false)).join('')}</div>
    </div>
    <div class="conf-section">
      <div class="conf-label b"><i class="bi bi-shield-fill"></i>Conferência B <span style="color:var(--text-3);font-weight:400">${idsB.length}/4</span></div>
      <div class="teams-grid">${confB.map(t=>teamCard(t,'B',idsB.indexOf(t.id),false)).join('')}</div>
    </div>`;

  const btnStart = document.getElementById('btnStart-'+lg);
  if (btnStart) btnStart.disabled = idsA.length < 4 || idsB.length < 4;
}

// ── Toggle seed ───────────────────────────────────────────────────────────────
function toggleSeed(lg, conf, teamId) {
  const state = loadLS(lg) || {phase:'picking', seedsA:[], seedsB:[], rounds:null};
  if (state.phase !== 'picking') return;
  const teams = TEAMS_BY_LEAGUE[lg]||[];
  const team = teams.find(t=>t.id===teamId); if (!team) return;
  const arr = conf==='A' ? state.seedsA : state.seedsB;
  const idx = arr.findIndex(t=>t&&t.id===teamId);
  if (idx !== -1) { arr.splice(idx,1); }
  else { if (arr.length >= 4) return; arr.push(team); }
  saveLS(lg, state);
  renderConfWrap(lg, state.seedsA, state.seedsB);
  renderSeedsStrip(lg, state.seedsA, state.seedsB);
}

// ── Start bracket ─────────────────────────────────────────────────────────────
function startBracket(lg) {
  const state = loadLS(lg) || {};
  const sA = state.seedsA||[], sB = state.seedsB||[];
  if (sA.length < 4 || sB.length < 4) return;
  // seeds[0..3]=ConfA, seeds[4..7]=ConfB
  // r1[0]=A1vA4, r1[1]=A2vA3, r1[2]=B1vB4, r1[3]=B2vB3
  state.rounds = {
    r1:[{t1:sA[0],t2:sA[3],w:null},{t1:sA[1],t2:sA[2],w:null},{t1:sB[0],t2:sB[3],w:null},{t1:sB[1],t2:sB[2],w:null}],
    r2:[{t1:null,t2:null,w:null},{t1:null,t2:null,w:null}],
    r3:[{t1:null,t2:null,w:null}]
  };
  state.phase = 'bracket';
  saveLS(lg, state);
  document.getElementById('pickingPhase-'+lg).style.display = 'none';
  document.getElementById('bracketPhase-'+lg).style.display = 'block';
  document.getElementById('phaseTitle-'+lg).innerHTML = 'Bracket <span>Playoffs</span>';
  document.getElementById('phaseSub-'+lg).textContent = 'Clique no vencedor de cada confronto';
  renderBracket(lg, state);
}

// ── Pick winner ───────────────────────────────────────────────────────────────
function pickWinner(lg, round, idx, teamId) {
  const state = loadLS(lg); if (!state||state.phase!=='bracket') return;
  const match = state.rounds[round][idx];
  if (!match.t1||!match.t2) return;
  const winner = [match.t1,match.t2].find(t=>t.id===teamId); if (!winner) return;
  match.w = winner;
  if (round==='r1') {
    if (idx===0) state.rounds.r2[0].t1=winner;
    if (idx===1) state.rounds.r2[0].t2=winner;
    if (idx===2) state.rounds.r2[1].t1=winner;
    if (idx===3) state.rounds.r2[1].t2=winner;
  } else if (round==='r2') {
    if (idx===0) state.rounds.r3[0].t1=winner;
    if (idx===1) state.rounds.r3[0].t2=winner;
  }
  saveLS(lg, state);
  renderBracket(lg, state);
  // Auto-save (unlocked) to DB
  autoSave(lg, state);
}

// ── Render bracket ────────────────────────────────────────────────────────────
function renderMatchupHtml(lg, round, idx, match, confTag) {
  const locked = !!match.w;
  function row(team, isWin) {
    if (!team) return `<div class="m-team tbd">${imgHtml(null,30)}<div class="m-info"><div class="m-name" style="color:var(--text-3);font-style:italic">A definir</div></div></div>`;
    const cls = locked?(isWin?'winner':'loser'):'';
    return `<div class="m-team ${cls} ${locked?'locked':''}" onclick="${!locked?`pickWinner('${lg}','${round}',${idx},${team.id})`:''}">
      ${imgHtml(team,30)}
      <div class="m-info"><div class="m-name">${team.name}</div><div class="m-seed">${team.city||''}</div></div>
      ${isWin?'<i class="bi bi-check-circle-fill m-check"></i>':''}
    </div>`;
  }
  const w1 = match.w&&match.t1&&match.w.id===match.t1.id;
  const w2 = match.w&&match.t2&&match.w.id===match.t2.id;
  return `<div class="matchup"><div class="matchup-inner">${confTag?`<span class="conf-tag ${confTag.toLowerCase()}">${confTag==='A'?'Conf A':'Conf B'}</span>`:''}<div class="m-team-wrap">${row(match.t1,w1)}${row(match.t2,w2)}</div></div></div>`;
}

function renderBracket(lg, state) {
  const wrap = document.getElementById('bracketWrap-'+lg); if (!wrap) return;
  const r=state.rounds;
  const allR1Done = r.r1.every(m=>m.w);
  const allR2Done = r.r2.every(m=>m.w);
  const champion = r.r3[0].w;

  let html = `
    <div class="round-block">
      <div class="round-head"><span class="round-head-label">Quartas de Final</span></div>
      <div class="round-grid g2">
        ${renderMatchupHtml(lg,'r1',0,r.r1[0],'A')}${renderMatchupHtml(lg,'r1',1,r.r1[1],'A')}
        ${renderMatchupHtml(lg,'r1',2,r.r1[2],'B')}${renderMatchupHtml(lg,'r1',3,r.r1[3],'B')}
      </div>
    </div>`;

  if (allR1Done) {
    html += `<div class="round-block">
      <div class="round-head"><span class="round-head-label">Semifinais</span></div>
      <div class="round-grid g2">
        ${renderMatchupHtml(lg,'r2',0,r.r2[0],'A')}${renderMatchupHtml(lg,'r2',1,r.r2[1],'B')}
      </div>
    </div>`;
  }

  if (allR2Done) {
    html += `<div class="round-block">
      <div class="round-head"><span class="round-head-label">Final</span></div>
      <div class="round-grid g1">${renderMatchupHtml(lg,'r3',0,r.r3[0],null)}</div>
    </div>`;
  }

  if (champion) {
    html += `<div class="champion-wrap"><div class="champion-card">
      <div class="champion-crown">🏆</div>
      <div class="champion-label">Campeão</div>
      ${champion.photo_url?`<img class="champion-logo" src="${champion.photo_url}" onerror="this.style.display='none'">`:'' }
      <div class="champion-name">${champion.name}</div>
      <div class="champion-city">${champion.city||''}</div>
    </div></div>`;
  }

  wrap.innerHTML = html;

  const btnSave = document.getElementById('btnSave-'+lg);
  const btnShare = document.getElementById('btnShare-'+lg);
  if (btnSave) btnSave.disabled = !champion;
  if (btnShare) btnShare.style.display = champion ? 'flex' : 'none';
}

// ── Render locked bracket ─────────────────────────────────────────────────────
function renderLockedBracket(lg, seedsArr, rounds) {
  const el = document.getElementById('bracket-locked-'+lg); if (!el) return;
  const state = {phase:'bracket', seedsA:seedsArr.slice(0,4), seedsB:seedsArr.slice(4,8), rounds};
  // Reuse renderBracket but override onclick (all locked)
  const tmpWrap = document.createElement('div');
  tmpWrap.id = 'bracketWrap-'+lg+'_tmp';
  el.appendChild(tmpWrap);
  // Temporarily hack: render in wrap then move
  const origWrap = document.getElementById('bracketWrap-'+lg);
  el.innerHTML = '';
  const dummyWrap = document.createElement('div');
  dummyWrap.id = 'bracketWrap-'+lg;
  el.appendChild(dummyWrap);
  renderBracket(lg, state);
  // Make all teams locked
  el.querySelectorAll('.m-team').forEach(t=>t.classList.add('locked'));
  renderSeedsStrip(lg, state.seedsA, state.seedsB);
}

// ── Save to DB ────────────────────────────────────────────────────────────────
function getCycleId(lg) {
  const c = CYCLES[lg]; return c?c.id:null;
}

async function autoSave(lg, state) {
  const cycleId = getCycleId(lg); if (!cycleId) return;
  const seeds8 = [...(state.seedsA||[]), ...(state.seedsB||[])];
  await fetch('bracket.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({acao:'save_picks',cycle_id:cycleId,seeds:JSON.stringify(seeds8),rounds:JSON.stringify(state.rounds),lock:'0'})
  });
}

async function saveBracket(lg, lock=true) {
  const state = loadLS(lg);
  if (!state||!state.rounds||!state.rounds.r3[0].w) { showToast('Defina o campeão primeiro','red'); return; }
  if (!confirm('Salvar e bloquear o bracket? Após salvar não poderá alterar.')) return;
  const cycleId = getCycleId(lg); if (!cycleId) return;
  const seeds8 = [...(state.seedsA||[]), ...(state.seedsB||[])];
  const btn = document.getElementById('btnSave-'+lg);
  if (btn) { btn.disabled=true; btn.innerHTML='<i class="bi bi-hourglass"></i>Salvando...'; }
  const resp = await fetch('bracket.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({acao:'save_picks',cycle_id:cycleId,seeds:JSON.stringify(seeds8),rounds:JSON.stringify(state.rounds),lock:'1'})
  });
  const data = await resp.json();
  if (data.ok) { showToast('Bracket salvo e bloqueado!','green'); setTimeout(()=>location.reload(),1200); }
  else { showToast(data.msg||'Erro','red'); if(btn){btn.disabled=false;btn.innerHTML='<i class="bi bi-lock-fill"></i>Salvar e Bloquear';} }
}

// ── Share ─────────────────────────────────────────────────────────────────────
function getStateForShare(lg) {
  // Try locked from server first, then localStorage
  const raw = USER_PICKS_RAW[lg];
  if (raw && raw.locked) {
    const seeds8 = JSON.parse(raw.seeds);
    const rounds = JSON.parse(raw.rounds);
    return {seedsA:seeds8.slice(0,4),seedsB:seeds8.slice(4,8),rounds};
  }
  return loadLS(lg);
}

function shareBracket(lg) {
  const state = getStateForShare(lg);
  if (!state||!state.rounds) { showToast('Bracket incompleto','red'); return; }
  const r = state.rounds;
  const champ = r.r3&&r.r3[0]&&r.r3[0].w;
  const tn = t => t?t.name:'?';
  const win = (m) => m.w ? `→ ${m.w.name}` : '(indefinido)';

  let txt = `🏆 FBA BRACKET — ${lg}\n\n`;
  txt += `📋 SEEDS\n`;
  txt += `CONF A\n`;
  (state.seedsA||[]).forEach((t,i)=>txt+=`  ${i+1}. ${t?t.name:'—'}\n`);
  txt += `CONF B\n`;
  (state.seedsB||[]).forEach((t,i)=>txt+=`  ${i+1}. ${t?t.name:'—'}\n`);
  txt += `\n⚔️ QUARTAS\n`;
  txt += `  [A] ${tn(r.r1[0].t1)} × ${tn(r.r1[0].t2)} ${win(r.r1[0])}\n`;
  txt += `  [A] ${tn(r.r1[1].t1)} × ${tn(r.r1[1].t2)} ${win(r.r1[1])}\n`;
  txt += `  [B] ${tn(r.r1[2].t1)} × ${tn(r.r1[2].t2)} ${win(r.r1[2])}\n`;
  txt += `  [B] ${tn(r.r1[3].t1)} × ${tn(r.r1[3].t2)} ${win(r.r1[3])}\n`;
  if (r.r2[0].t1||r.r2[0].t2) {
    txt += `\n🏅 SEMIS\n`;
    txt += `  [A] ${tn(r.r2[0].t1)} × ${tn(r.r2[0].t2)} ${win(r.r2[0])}\n`;
    txt += `  [B] ${tn(r.r2[1].t1)} × ${tn(r.r2[1].t2)} ${win(r.r2[1])}\n`;
  }
  if (r.r3[0].t1||r.r3[0].t2) {
    txt += `\n🏆 FINAL\n`;
    txt += `  ${tn(r.r3[0].t1)} × ${tn(r.r3[0].t2)} ${win(r.r3[0])}\n`;
  }
  if (champ) txt += `\n👑 CAMPEÃO: ${champ.name}\n`;
  txt += `\n🏀 FBA Games`;

  if (navigator.share) {
    navigator.share({text:txt}).catch(()=>{});
  } else if (navigator.clipboard) {
    navigator.clipboard.writeText(txt).then(()=>showToast('Texto copiado! Cole no WhatsApp','green'));
  } else {
    window.open('https://wa.me/?text='+encodeURIComponent(txt),'_blank');
  }
}

// ── Reset ─────────────────────────────────────────────────────────────────────
function resetBracket(lg) {
  if (!confirm('Resetar seu bracket de '+lg+'?')) return;
  clearLS(lg);
  const state = {phase:'picking',seedsA:[],seedsB:[],rounds:null};
  renderConfWrap(lg,[],[]);
  renderSeedsStrip(lg,[],[]);
  document.getElementById('pickingPhase-'+lg).style.display='block';
  document.getElementById('bracketPhase-'+lg).style.display='none';
  document.getElementById('phaseTitle-'+lg).innerHTML='Montar <span>Bracket</span>';
  document.getElementById('phaseSub-'+lg).textContent='Selecione os 4 seeds de cada conferência';
  document.getElementById('btnStart-'+lg).disabled=true;
}

// ── Sidebar ───────────────────────────────────────────────────────────────────
function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('sbOverlay').classList.add('open');document.body.style.overflow='hidden';}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sbOverlay').classList.remove('open');document.body.style.overflow='';}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  LEAGUES.forEach(lg => {
    const raw = USER_PICKS_RAW[lg];
    if (raw && raw.locked) {
      // Server-loaded locked bracket
      const seeds8 = JSON.parse(raw.seeds);
      const rounds  = JSON.parse(raw.rounds);
      renderLockedBracket(lg, seeds8, rounds);
      return;
    }
    const state = loadLS(lg);
    if (state && state.phase === 'bracket' && state.rounds) {
      document.getElementById('pickingPhase-'+lg).style.display='none';
      document.getElementById('bracketPhase-'+lg).style.display='block';
      document.getElementById('phaseTitle-'+lg).innerHTML='Bracket <span>Playoffs</span>';
      document.getElementById('phaseSub-'+lg).textContent='Clique no vencedor de cada confronto';
      renderBracket(lg, state);
      renderSeedsStrip(lg, state.seedsA||[], state.seedsB||[]);
    } else {
      const sA = state?.seedsA||[], sB=state?.seedsB||[];
      renderConfWrap(lg, sA, sB);
      renderSeedsStrip(lg, sA, sB);
    }
  });
});

function getCycleId(lg) { return CYCLES[lg] ? CYCLES[lg].id : null; }
</script>
</body>
</html>
