<?php
session_start();
require 'core/conexao.php';
if (!isset($_SESSION['user_id'])) { header("Location: auth/login.php"); exit; }

$user_id = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT nome, pontos, is_admin, fba_points, COALESCE(numero_tapas,0) as numero_tapas, tapas_disponiveis FROM usuarios WHERE id=?");
$stmt->execute([$user_id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);
$isAdmin = !empty($usuario['is_admin']);

// ── DB setup ──────────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS copa26_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        letter CHAR(1) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS copa26_teams (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        flag VARCHAR(10) NULL,
        FOREIGN KEY (group_id) REFERENCES copa26_groups(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS copa26_matches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        match_date DATE NOT NULL,
        match_time VARCHAR(8) NULL,
        home_team_id INT NULL,
        away_team_id INT NULL,
        home_name VARCHAR(100) NULL,
        away_name VARCHAR(100) NULL,
        phase ENUM('group','r32','r16','qf','sf','third','final') DEFAULT 'group',
        group_id INT NULL,
        score_home TINYINT NULL,
        score_away TINYINT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS copa26_predictions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        groups_json TEXT NULL,
        thirds_json TEXT NULL,
        bracket_json TEXT NULL,
        top_scorer VARCHAR(100) NULL,
        best_player VARCHAR(100) NULL,
        revelation VARCHAR(100) NULL,
        champion VARCHAR(100) NULL,
        submitted_at TIMESTAMP NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS copa26_score_preds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        match_id INT NOT NULL,
        score_home TINYINT NOT NULL DEFAULT 0,
        score_away TINYINT NOT NULL DEFAULT 0,
        points_earned TINYINT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_match (user_id, match_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ── Seed ─────────────────────────────────────────────────────────────────────
function seedCopa26(PDO $pdo): void {
    $pdo->exec("DELETE FROM copa26_teams");
    $pdo->exec("DELETE FROM copa26_groups");

    $data = [
        'A' => [['México','🇲🇽'],['Jamaica','🇯🇲'],['Venezuela','🇻🇪'],['Equador','🇪🇨']],
        'B' => [['Estados Unidos','🇺🇸'],['Bolívia','🇧🇴'],['Panamá','🇵🇦'],['Uruguai','🇺🇾']],
        'C' => [['Canadá','🇨🇦'],['Honduras','🇭🇳'],['Colômbia','🇨🇴'],['Paraguai','🇵🇾']],
        'D' => [['Brasil','🇧🇷'],['Japão','🇯🇵'],['Rep. Tcheca','🇨🇿'],['Congo DR','🇨🇩']],
        'E' => [['Espanha','🇪🇸'],['Croácia','🇭🇷'],['Marrocos','🇲🇦'],['Uzbequistão','🇺🇿']],
        'F' => [['Argentina','🇦🇷'],['Chile','🇨🇱'],['Turquia','🇹🇷'],['Angola','🇦🇴']],
        'G' => [['França','🇫🇷'],['Bélgica','🇧🇪'],['Argélia','🇩🇿'],['Nova Zelândia','🇳🇿']],
        'H' => [['Portugal','🇵🇹'],['Polônia','🇵🇱'],['Senegal','🇸🇳'],['Guatemala','🇬🇹']],
        'I' => [['Holanda','🇳🇱'],['Peru','🇵🇪'],['Haiti','🇭🇹'],['Arábia Saudita','🇸🇦']],
        'J' => [['Alemanha','🇩🇪'],['Sérvia','🇷🇸'],['Costa Rica','🇨🇷'],['Camarões','🇨🇲']],
        'K' => [['Itália','🇮🇹'],['Catar','🇶🇦'],['Nicarágua','🇳🇮'],['Austrália','🇦🇺']],
        'L' => [['Inglaterra','🏴󠁧󠁢󠁥󠁮󠁧󠁿'],['Irã','🇮🇷'],['Eslováquia','🇸🇰'],['Tailândia','🇹🇭']],
    ];

    $sg = $pdo->prepare("INSERT INTO copa26_groups (letter) VALUES (?)");
    $st = $pdo->prepare("INSERT INTO copa26_teams (group_id, name, flag) VALUES (?,?,?)");
    foreach ($data as $letter => $teams) {
        $sg->execute([$letter]);
        $gid = $pdo->lastInsertId();
        foreach ($teams as [$n, $f]) $st->execute([$gid, $n, $f]);
    }
}

// ── POST actions ──────────────────────────────────────────────────────────────
if ($isAdmin && isset($_GET['action']) && $_GET['action'] === 'seed') {
    seedCopa26($pdo);
    header('Location: copa26.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $act  = $body['action'] ?? '';

    if ($act === 'save') {
        $stmt = $pdo->prepare("INSERT INTO copa26_predictions (user_id,groups_json,thirds_json,bracket_json,top_scorer,best_player,revelation,champion)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE groups_json=VALUES(groups_json),thirds_json=VALUES(thirds_json),bracket_json=VALUES(bracket_json),
            top_scorer=VALUES(top_scorer),best_player=VALUES(best_player),revelation=VALUES(revelation),champion=VALUES(champion)");
        $stmt->execute([
            $user_id,
            json_encode($body['groups']  ?? []),
            json_encode($body['thirds']  ?? []),
            json_encode($body['bracket'] ?? []),
            $body['top_scorer']  ?? null,
            $body['best_player'] ?? null,
            $body['revelation']  ?? null,
            $body['champion']    ?? null,
        ]);
        echo json_encode(['ok' => true]); exit;
    }

    if ($act === 'submit') {
        $pdo->prepare("UPDATE copa26_predictions SET submitted_at=NOW() WHERE user_id=? AND submitted_at IS NULL")
            ->execute([$user_id]);
        echo json_encode(['ok' => true]); exit;
    }

    if ($act === 'scores') {
        $scores = $body['scores'] ?? [];
        $stmt = $pdo->prepare("INSERT INTO copa26_score_preds (user_id,match_id,score_home,score_away) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE score_home=VALUES(score_home),score_away=VALUES(score_away)");
        foreach ($scores as $mid => $s)
            $stmt->execute([$user_id, (int)$mid, (int)($s['h'] ?? 0), (int)($s['a'] ?? 0)]);
        echo json_encode(['ok' => true]); exit;
    }

    if ($act === 'set_result' && $isAdmin) {
        $pdo->prepare("UPDATE copa26_matches SET score_home=?,score_away=? WHERE id=?")
            ->execute([(int)($body['home'] ?? 0), (int)($body['away'] ?? 0), (int)($body['match_id'] ?? 0)]);
        echo json_encode(['ok' => true]); exit;
    }

    echo json_encode(['ok' => false]); exit;
}

// ── Load groups / teams ───────────────────────────────────────────────────────
$groups = [];
try {
    $rows = $pdo->query("SELECT g.id gid, g.letter, t.id tid, t.name, t.flag
        FROM copa26_groups g LEFT JOIN copa26_teams t ON t.group_id=g.id
        ORDER BY g.letter, t.name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $l = $r['letter'];
        if (!isset($groups[$l])) $groups[$l] = ['id' => $r['gid'], 'teams' => []];
        if ($r['tid']) $groups[$l]['teams'][] = ['id' => $r['tid'], 'name' => $r['name'], 'flag' => $r['flag'] ?? ''];
    }
} catch (Exception $e) {}

$allTeams = [];
foreach ($groups as $g) foreach ($g['teams'] as $t) $allTeams[$t['id']] = $t;

$pred = null;
try {
    $s = $pdo->prepare("SELECT * FROM copa26_predictions WHERE user_id=?");
    $s->execute([$user_id]);
    $pred = $s->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {}

$submitted   = !empty($pred['submitted_at']);
$predGroups  = $pred ? (json_decode($pred['groups_json']  ?? '[]', true) ?: []) : [];
$predThirds  = $pred ? (json_decode($pred['thirds_json']  ?? '[]', true) ?: []) : [];
$predBracket = $pred ? (json_decode($pred['bracket_json'] ?? '{}', true) ?: []) : [];

// Today's matches
$todayMatches = [];
try {
    $todayMatches = $pdo->query("SELECT m.*,
        ht.name hname, ht.flag hflag,
        at.name aname, at.flag aflag
        FROM copa26_matches m
        LEFT JOIN copa26_teams ht ON ht.id=m.home_team_id
        LEFT JOIN copa26_teams at ON at.id=m.away_team_id
        WHERE m.match_date=CURDATE() ORDER BY m.match_time")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$myScores = [];
if ($todayMatches) {
    try {
        $ids = array_column($todayMatches, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $s   = $pdo->prepare("SELECT * FROM copa26_score_preds WHERE user_id=? AND match_id IN ($ph)");
        $s->execute(array_merge([$user_id], $ids));
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $myScores[$r['match_id']] = $r;
    } catch (Exception $e) {}
}

$seeded = !empty($groups);
$nameInitial = mb_strtoupper(mb_substr($usuario['nome'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#fc0025">
<title>Bolão Copa 2026 · FBA Games</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025;--red-soft:rgba(252,0,37,.10);--red-glow:rgba(252,0,37,.18);
  --bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;
  --border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);
  --text:#f0f0f3;--text-2:#868690;--text-3:#48484f;
  --green:#22c55e;--amber:#f59e0b;--blue:#3b82f6;--gold:#f59e0b;
  --font:'Poppins',sans-serif;--radius:14px;--radius-sm:10px;--t:200ms;--ease:cubic-bezier(.2,.8,.2,1);
  --sw:220px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{background:var(--bg);color:var(--text);font-family:var(--font);-webkit-font-smoothing:antialiased}

/* Sidebar */
.sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sw);background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:200;overflow-y:auto;scrollbar-width:none}
.sidebar::-webkit-scrollbar{display:none}
.sb-header{display:flex;align-items:center;gap:10px;padding:16px 14px 12px;border-bottom:1px solid var(--border)}
.sb-logo{width:30px;height:30px;border-radius:8px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:11px;color:#fff;flex-shrink:0}
.sb-brand{font-weight:800;font-size:13px;color:var(--text)}
.sb-brand span{color:var(--red)}
.sb-close{display:none;background:none;border:none;color:var(--text-2);font-size:18px;cursor:pointer;margin-left:auto}
.sb-user{padding:14px;border-bottom:1px solid var(--border)}
.sb-avatar{width:38px;height:38px;border-radius:50%;background:var(--red-soft);border:2px solid rgba(252,0,37,.2);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:var(--red);margin-bottom:7px}
.sb-user-name{font-size:12px;font-weight:700;color:#fff}
.sb-user-role{font-size:10px;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;margin-top:1px}
.sb-stats{padding:10px 14px;border-bottom:1px solid var(--border);display:flex;gap:5px}
.sb-stat{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;padding:7px 4px;background:var(--panel-2);border-radius:8px;border:1px solid var(--border)}
.sb-stat i{font-size:12px;color:var(--red)}
.sb-stat-val{font-size:11px;font-weight:700;color:var(--text);line-height:1}
.sb-stat-label{font-size:9px;color:var(--text-3)}
.sb-nav{flex:1;padding:8px 0}
.sb-nav-section{font-size:9px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:8px 14px 4px}
.sb-link{display:flex;align-items:center;gap:10px;padding:9px 14px;text-decoration:none;font-size:12px;font-weight:500;color:var(--text-2);border-left:3px solid transparent;transition:all var(--t) var(--ease)}
.sb-link i{width:16px;text-align:center;font-size:13px}
.sb-link:hover{background:var(--panel-2);color:var(--text);border-left-color:var(--border-md)}
.sb-link.active{background:var(--red-soft);color:var(--red);border-left-color:var(--red);font-weight:700}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border);margin-top:auto}
.sb-logout{display:flex;align-items:center;gap:8px;width:100%;padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-2);text-decoration:none;font-size:12px;font-weight:600;transition:all var(--t) var(--ease)}
.sb-logout:hover{background:var(--red-soft);border-color:rgba(252,0,37,.2);color:var(--red)}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:199}
.sb-overlay.open{display:block}

/* Page layout */
.page{margin-left:var(--sw);min-height:100vh;display:flex;flex-direction:column}
.mob-bar{display:none;height:52px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 14px;gap:10px;position:sticky;top:0;z-index:100}
.mob-ham{background:none;border:1px solid var(--border);border-radius:8px;color:var(--text);width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px}
.mob-title{font-size:14px;font-weight:800;color:var(--text);flex:1}
.mob-title span{color:var(--red)}
.main{flex:1;padding:20px 24px 60px;max-width:1100px;margin:0 auto;width:100%}

/* Generic */
.btn-r{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:500;border:none;cursor:pointer;transition:opacity var(--t);font-family:var(--font);text-decoration:none}
.btn-r:hover{opacity:.85}
.btn-r.primary{background:var(--red);color:#fff}
.btn-r.secondary{background:var(--panel-3);color:var(--text-2);border:1px solid var(--border)}
.btn-r.outline{background:transparent;color:var(--red);border:1px solid rgba(252,0,37,.3)}
.btn-r.gold{background:rgba(245,158,11,.15);color:var(--gold);border:1px solid rgba(245,158,11,.3)}
.btn-r:disabled{opacity:.35;cursor:not-allowed}
.btn-r.sm{padding:5px 12px;font-size:12px}
.btn-r.lg{padding:11px 24px;font-size:14px;font-weight:700}
.tag{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600}
.tag.gray{background:var(--panel-3);color:var(--text-2)}
.tag.green{background:rgba(34,197,94,.12);color:#22c55e}

/* Copa hero */
.copa-hero{background:linear-gradient(135deg,#0a1628 0%,#1a0510 100%);border:1px solid rgba(252,0,37,.22);border-radius:var(--radius);padding:20px 24px;margin-bottom:20px;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.copa-hero::before{content:'⚽';position:absolute;right:20px;top:50%;transform:translateY(-50%);font-size:72px;opacity:.05;pointer-events:none}
.copa-hero-title{font-size:22px;font-weight:800;color:#fff}
.copa-hero-title span{color:var(--red)}
.copa-hero-sub{font-size:12px;color:rgba(255,255,255,.45);margin-top:3px}

/* Tabs */
.copa-tabs{display:flex;border-bottom:1px solid var(--border);margin-bottom:20px;overflow-x:auto;scrollbar-width:none}
.copa-tabs::-webkit-scrollbar{display:none}
.copa-tab{padding:10px 18px;font-size:13px;font-weight:500;color:var(--text-2);border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap;font-family:var(--font);transition:color var(--t)}
.copa-tab:hover{color:var(--text)}
.copa-tab.active{color:var(--red);border-bottom-color:var(--red);font-weight:600}
.copa-pane{display:none}
.copa-pane.active{display:block}

/* Daily scores */
.score-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);padding:12px 16px;display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap}
.score-team{display:flex;align-items:center;gap:8px;flex:1;min-width:100px}
.score-team-name{font-size:13px;font-weight:600;color:var(--text)}
.score-team.right{flex-direction:row-reverse}
.score-team.right .score-team-name{text-align:right}
.score-input-wrap{display:flex;align-items:center;gap:6px;flex-shrink:0}
.score-input{width:44px;height:36px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;color:var(--text);font-size:16px;font-weight:700;text-align:center;font-family:var(--font)}
.score-input:focus{outline:none;border-color:var(--red)}
.score-sep{color:var(--text-3);font-weight:700}
.score-result{font-size:18px;font-weight:800;color:var(--green);text-align:center;min-width:54px;flex-shrink:0}
.score-time{font-size:11px;color:var(--text-3);flex-shrink:0}

/* Groups */
.groups-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.group-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden}
.group-card-header{padding:8px 12px;background:var(--panel-2);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.group-letter{font-size:12px;font-weight:700;color:var(--red)}
.group-label{font-size:10px;color:var(--text-3);text-transform:uppercase}
.group-teams{padding:6px}
.team-row{display:flex;align-items:center;gap:7px;padding:6px 7px;border-radius:7px;cursor:pointer;transition:background var(--t);border:1px solid transparent;margin-bottom:3px;user-select:none}
.team-row:last-child{margin-bottom:0}
.team-row:hover{background:var(--panel-2)}
.team-row.rank-1{background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.2)}
.team-row.rank-2{background:rgba(59,130,246,.06);border-color:rgba(59,130,246,.15)}
.team-row.rank-3{background:rgba(255,255,255,.02)}
.team-rank{width:18px;height:18px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;flex-shrink:0}
.rank-1 .team-rank{background:rgba(245,158,11,.2);color:var(--gold)}
.rank-2 .team-rank{background:rgba(59,130,246,.15);color:var(--blue)}
.rank-3 .team-rank,.rank-4 .team-rank{background:var(--panel-3);color:var(--text-3)}
.team-flag{font-size:15px;flex-shrink:0}
.team-name-sm{font-size:11px;font-weight:500;color:var(--text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.team-arrows{display:flex;flex-direction:column;gap:1px;flex-shrink:0}
.arrow-btn{background:none;border:none;color:var(--text-3);cursor:pointer;padding:0 2px;font-size:10px;line-height:1.2;transition:color var(--t)}
.arrow-btn:hover{color:var(--text)}
.arrow-btn:disabled{opacity:.2;cursor:default}
.advance-badge{font-size:9px;font-weight:700;padding:1px 5px;border-radius:4px;flex-shrink:0}
.advance-1{background:rgba(245,158,11,.15);color:var(--gold)}
.advance-2{background:rgba(59,130,246,.12);color:var(--blue)}
.advance-3{background:rgba(255,255,255,.05);color:var(--text-3)}

/* 3rd place */
.thirds-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:8px}
.third-slot{background:var(--panel);border:2px solid var(--border);border-radius:10px;padding:10px 6px;text-align:center;cursor:pointer;transition:all var(--t);position:relative}
.third-slot:hover{border-color:var(--border-md)}
.third-slot.selected{border-color:var(--blue);background:rgba(59,130,246,.07)}
.third-slot-flag{font-size:20px;margin-bottom:3px}
.third-slot-name{font-size:10px;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.third-slot-group{font-size:9px;color:var(--text-3)}
.third-check{position:absolute;top:3px;right:3px;font-size:10px;color:var(--blue)}

/* Bracket */
.bracket-wrap{overflow-x:auto;padding-bottom:8px}
.bracket{display:flex;align-items:stretch;min-width:860px}
.bracket-round{display:flex;flex-direction:column;justify-content:space-around;flex:1;padding:0 3px}
.bracket-round-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);text-align:center;padding:6px 0;border-bottom:1px solid var(--border);margin-bottom:6px}
.bracket-match{background:var(--panel);border:1px solid var(--border);border-radius:8px;overflow:hidden;margin:3px 0;cursor:pointer;transition:border-color var(--t)}
.bracket-match:hover{border-color:var(--border-md)}
.bracket-slot{padding:5px 9px;display:flex;align-items:center;gap:5px;font-size:11px;font-weight:500;color:var(--text);min-height:28px;transition:background var(--t)}
.bracket-slot.winner{background:rgba(34,197,94,.08);color:var(--green);font-weight:700}
.bracket-slot.tbd{color:var(--text-3)}
.bracket-slot-flag{font-size:12px;flex-shrink:0}
.bracket-slot-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bracket-divider{height:1px;background:var(--border);margin:0 7px}

/* Awards */
.awards-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
.award-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px}
.award-icon{font-size:26px;margin-bottom:7px}
.award-title{font-size:13px;font-weight:600;color:var(--text);margin-bottom:3px}
.award-sub{font-size:11px;color:var(--text-2);margin-bottom:10px}
.award-select,.award-input{width:100%;background:var(--panel-2);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-size:13px;font-family:var(--font)}
.award-select:focus,.award-input:focus{outline:none;border-color:var(--red)}
.award-input::placeholder{color:var(--text-3)}

/* Submit */
.submit-bar{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:14px;margin-top:20px;flex-wrap:wrap}
.submit-bar-info{font-size:13px;color:var(--text-2)}
.submit-bar-info strong{color:var(--text)}
.submitted-banner{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);border-radius:var(--radius-sm);padding:12px 18px;display:flex;align-items:center;gap:9px;margin-bottom:18px;color:var(--green);font-weight:600;font-size:13px}
.no-data{text-align:center;padding:60px 20px}
.no-data-icon{font-size:48px;margin-bottom:14px}
.no-data-title{font-size:18px;font-weight:600;color:var(--text);margin-bottom:6px}
.no-data-sub{font-size:13px;color:var(--text-2)}
.section-header{font-size:14px;font-weight:700;color:var(--text);margin-bottom:14px;display:flex;align-items:center;gap:8px}

@media(max-width:900px){
  .sidebar{transform:translateX(-100%);transition:transform var(--t) var(--ease)}
  .sidebar.open{transform:translateX(0)}
  .sb-close{display:block}
  .page{margin-left:0}
  .mob-bar{display:flex}
  .groups-grid{grid-template-columns:repeat(2,1fr)}
  .thirds-grid{grid-template-columns:repeat(4,1fr)}
  .awards-grid{grid-template-columns:1fr}
}
@media(max-width:540px){
  .main{padding:14px 14px 40px}
  .groups-grid{grid-template-columns:repeat(2,1fr)}
  .thirds-grid{grid-template-columns:repeat(3,1fr)}
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-logo">FBA</div>
    <div class="sb-brand">FBA <span>Games</span></div>
    <button class="sb-close" onclick="closeSidebar()"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="sb-user">
    <div class="sb-avatar"><?=htmlspecialchars($nameInitial)?></div>
    <div class="sb-user-name"><?=htmlspecialchars($usuario['nome'] ?? '')?></div>
    <div class="sb-user-role"><?=$isAdmin ? 'Admin' : 'Jogador'?></div>
  </div>
  <div class="sb-stats">
    <div class="sb-stat"><i class="bi bi-coin"></i><span class="sb-stat-val"><?=(int)($usuario['pontos']??0)?></span><span class="sb-stat-label">Moedas</span></div>
    <div class="sb-stat"><i class="bi bi-star-fill"></i><span class="sb-stat-val"><?=(int)($usuario['fba_points']??0)?></span><span class="sb-stat-label">FBA Pts</span></div>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-section">Menu</div>
    <a class="sb-link" href="index.php"><i class="bi bi-house-door-fill"></i>Início</a>
    <a class="sb-link" href="games.php"><i class="bi bi-controller"></i>Jogos</a>
    <a class="sb-link" href="bracket.php"><i class="bi bi-diagram-3-fill"></i>Bracket NBA</a>
    <a class="sb-link active" href="copa26.php"><i class="bi bi-trophy-fill"></i>Bolão Copa 2026</a>
    <a class="sb-link" href="user/ranking.php"><i class="bi bi-bar-chart-fill"></i>Ranking</a>
    <?php if ($isAdmin): ?>
    <div class="sb-nav-section">Admin</div>
    <a class="sb-link" href="admin/dashboard.php"><i class="bi bi-shield-lock-fill"></i>Painel Admin</a>
    <?php endif; ?>
  </nav>
  <div class="sb-footer">
    <a class="sb-logout" href="auth/logout.php"><i class="bi bi-box-arrow-right"></i>Sair</a>
  </div>
</aside>
<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<div class="page">
  <!-- Mobile bar -->
  <div class="mob-bar">
    <button class="mob-ham" onclick="openSidebar()"><i class="bi bi-list"></i></button>
    <div class="mob-title">Bolão <span>Copa 2026</span></div>
    <?php if ($submitted): ?>
    <span class="tag green" style="font-size:10px"><i class="bi bi-check-circle-fill"></i>Enviado</span>
    <?php endif; ?>
  </div>

  <div class="main">

    <!-- Copa hero -->
    <div class="copa-hero">
      <div>
        <div class="copa-hero-title">Copa do Mundo <span>2026</span></div>
        <div class="copa-hero-sub">48 seleções · 12 grupos · USA, Canadá, México</div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?php if ($submitted): ?>
        <span class="tag green"><i class="bi bi-check-circle-fill"></i>Palpite enviado</span>
        <?php elseif ($seeded): ?>
        <span class="tag gray">Palpites abertos</span>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
        <?php if (!$seeded): ?>
        <a href="copa26.php?action=seed" class="btn-r outline sm"><i class="bi bi-database-fill-add"></i>Configurar grupos</a>
        <?php else: ?>
        <a href="copa26.php?action=seed" class="btn-r secondary sm" onclick="return confirm('Reconfigurar apaga todos os palpites. Confirmar?')"><i class="bi bi-arrow-counterclockwise"></i>Reconfigurar</a>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$seeded): ?>
    <div class="no-data">
      <div class="no-data-icon">⚽</div>
      <div class="no-data-title">Bolão ainda não configurado</div>
      <div class="no-data-sub">Aguarde o administrador configurar os grupos.</div>
      <?php if ($isAdmin): ?>
      <br><a href="copa26.php?action=seed" class="btn-r primary">
        <i class="bi bi-database-fill-add"></i>Configurar agora
      </a>
      <?php endif; ?>
    </div>
    <?php else: ?>

    <!-- ── Daily scores ──────────────────────────────────────────────────── -->
    <?php if ($todayMatches): ?>
    <div style="margin-bottom:24px">
      <div class="section-header">
        <i class="bi bi-calendar-event-fill" style="color:var(--red)"></i>
        Acerte o Placar — Jogos de Hoje
      </div>
      <?php foreach ($todayMatches as $m):
          $hName = $m['hname'] ?: $m['home_name'] ?: 'Time A';
          $aName = $m['aname'] ?: $m['away_name'] ?: 'Time B';
          $hFlag = $m['hflag'] ?? '🏳';
          $aFlag = $m['aflag'] ?? '🏳';
          $sp    = $myScores[$m['id']] ?? null;
          $hasResult = $m['score_home'] !== null;
      ?>
      <div class="score-card" data-match="<?=$m['id']?>">
        <div class="score-team">
          <span style="font-size:18px"><?=$hFlag?></span>
          <span class="score-team-name"><?=htmlspecialchars($hName)?></span>
        </div>
        <?php if ($hasResult): ?>
        <div class="score-result"><?=$m['score_home']?> – <?=$m['score_away']?></div>
        <?php else: ?>
        <div class="score-input-wrap">
          <input type="number" min="0" max="30" class="score-input" id="sh_<?=$m['id']?>"
                 value="<?=$sp ? $sp['score_home'] : ''?>" placeholder="0"
                 <?=$submitted ? 'readonly' : ''?>>
          <span class="score-sep">×</span>
          <input type="number" min="0" max="30" class="score-input" id="sa_<?=$m['id']?>"
                 value="<?=$sp ? $sp['score_away'] : ''?>" placeholder="0"
                 <?=$submitted ? 'readonly' : ''?>>
        </div>
        <?php endif; ?>
        <div class="score-team right">
          <span class="score-team-name"><?=htmlspecialchars($aName)?></span>
          <span style="font-size:18px"><?=$aFlag?></span>
        </div>
        <span class="score-time"><?=htmlspecialchars($m['match_time'] ?? '')?></span>
        <?php if ($isAdmin && !$hasResult): ?>
        <button class="btn-r secondary sm" onclick="setResult(<?=$m['id']?>)">
          <i class="bi bi-check2"></i>Resultado
        </button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if (!$submitted): ?>
      <button class="btn-r primary" style="margin-top:10px" onclick="saveScores()">
        <i class="bi bi-check2-circle"></i>Salvar placares
      </button>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- submitted banner -->
    <?php if ($submitted): ?>
    <div class="submitted-banner">
      <i class="bi bi-check-circle-fill" style="font-size:18px"></i>
      Palpite enviado em <?=date('d/m/Y H:i', strtotime($pred['submitted_at'] ?? ''))?>! Boa sorte! 🎉
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="copa-tabs" id="copaTabs">
      <button class="copa-tab active" data-tab="grupos">⚽ Grupos</button>
      <button class="copa-tab" data-tab="thirds">🥉 3º Lugar</button>
      <button class="copa-tab" data-tab="bracket">🏆 Bracket</button>
      <button class="copa-tab" data-tab="premios">🌟 Prêmios</button>
    </div>

    <!-- ── Grupos ───────────────────────────────────────────────────────── -->
    <div class="copa-pane active" id="pane-grupos">
      <p style="font-size:12px;color:var(--text-2);margin-bottom:14px">
        <i class="bi bi-info-circle me-1"></i>Use as setas para ordenar. 1º e 2º avançam direto, 3º entra na disputa de melhor terceiro.
      </p>
      <div class="groups-grid" id="groupsGrid">
      <?php foreach ($groups as $letter => $g):
          $savedOrder = $predGroups[$letter] ?? null;
          $teams = $g['teams'];
          if ($savedOrder) {
              usort($teams, function($a, $b) use ($savedOrder) {
                  $pa = array_search($a['id'], $savedOrder); $pb = array_search($b['id'], $savedOrder);
                  return ($pa === false ? 99 : $pa) - ($pb === false ? 99 : $pb);
              });
          }
      ?>
      <div class="group-card" data-group="<?=$letter?>">
        <div class="group-card-header">
          <span class="group-letter">GRUPO <?=$letter?></span>
          <span class="group-label">Classificação</span>
        </div>
        <div class="group-teams" id="gt_<?=$letter?>">
        <?php foreach ($teams as $idx => $t):
            $rank = $idx + 1;
            $badge = $rank===1 ? '<span class="advance-badge advance-1">1º</span>'
                  : ($rank===2 ? '<span class="advance-badge advance-2">2º</span>'
                  : ($rank===3 ? '<span class="advance-badge advance-3">3º?</span>' : ''));
        ?>
        <div class="team-row rank-<?=$rank?>" data-team-id="<?=$t['id']?>" data-team-name="<?=htmlspecialchars($t['name'])?>" data-team-flag="<?=htmlspecialchars($t['flag']??'🏳')?>" <?=$submitted?'style="cursor:default"':''?>>
          <span class="team-rank"><?=$rank?></span>
          <span class="team-flag"><?=$t['flag']??'🏳'?></span>
          <span class="team-name-sm"><?=htmlspecialchars($t['name'])?></span>
          <?=$badge?>
          <?php if (!$submitted): ?>
          <div class="team-arrows">
            <button class="arrow-btn" onclick="moveTeam('<?=$letter?>',<?=$t['id']?>,-1)" <?=$rank===1?'disabled':''?>>▲</button>
            <button class="arrow-btn" onclick="moveTeam('<?=$letter?>',<?=$t['id']?>,1)"  <?=$rank===4?'disabled':''?>>▼</button>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      </div>
      <?php if (!$submitted): ?>
      <div style="margin-top:14px;text-align:right">
        <button class="btn-r outline" onclick="nextTab('thirds')">Próximo: 3º Lugar <i class="bi bi-arrow-right"></i></button>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── 3º Lugar ─────────────────────────────────────────────────────── -->
    <div class="copa-pane" id="pane-thirds">
      <p style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:4px">Melhores 3ºs Colocados</p>
      <p style="font-size:12px;color:var(--text-2);margin-bottom:14px">Selecione 8 seleções que avançam como melhores terceiros colocados</p>
      <div class="thirds-grid" id="thirdsGrid">
      <?php foreach ($groups as $letter => $g):
          $teams = $g['teams'];
      ?>
      <div class="third-slot <?=in_array($g['id'], $predThirds ?? []) ? 'selected' : ''?>"
           data-group="<?=$letter?>" data-group-id="<?=$g['id']?>"
           onclick="<?=$submitted ? '' : 'toggleThird(this)'?>"
           id="third_<?=$letter?>">
        <div class="third-check" style="display:<?=in_array($g['id'], $predThirds ?? []) ? 'block' : 'none'?>">
          <i class="bi bi-check-circle-fill"></i>
        </div>
        <div class="third-slot-flag" id="t3flag_<?=$letter?>"><?=$teams[2]['flag'] ?? '🏳'?></div>
        <div class="third-slot-name" id="t3name_<?=$letter?>"><?=htmlspecialchars($teams[2]['name'] ?? '?')?></div>
        <div class="third-slot-group">Grupo <?=$letter?></div>
      </div>
      <?php endforeach; ?>
      </div>
      <div style="margin-top:8px;font-size:12px;color:var(--text-2)">
        Selecionados: <strong id="thirdsCount"><?=count($predThirds ?? [])?></strong>/8
      </div>
      <?php if (!$submitted): ?>
      <div style="margin-top:14px;text-align:right">
        <button class="btn-r outline" onclick="nextTab('bracket')">Próximo: Bracket <i class="bi bi-arrow-right"></i></button>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Bracket ──────────────────────────────────────────────────────── -->
    <div class="copa-pane" id="pane-bracket">
      <p style="font-size:12px;color:var(--text-2);margin-bottom:14px">
        <i class="bi bi-info-circle"></i> Clique no time para avançá-lo. O bracket é gerado dos seus palpites de grupo.
      </p>
      <div class="bracket-wrap">
        <div class="bracket" id="bracketEl"></div>
      </div>
      <?php if (!$submitted): ?>
      <div style="margin-top:14px;text-align:right">
        <button class="btn-r outline" onclick="nextTab('premios')">Próximo: Prêmios <i class="bi bi-arrow-right"></i></button>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Prêmios ──────────────────────────────────────────────────────── -->
    <div class="copa-pane" id="pane-premios">
      <div class="awards-grid">
        <div class="award-card">
          <div class="award-icon">🥇</div>
          <div class="award-title">Campeão</div>
          <div class="award-sub">Quem vai ser campeão do mundo?</div>
          <select class="award-select" id="award_champion" <?=$submitted ? 'disabled' : ''?>>
            <option value="">Selecione...</option>
            <?php foreach ($allTeams as $t): ?>
            <option value="<?=htmlspecialchars($t['name'])?>" <?=($pred['champion'] ?? '') === $t['name'] ? 'selected' : ''?>>
              <?=$t['flag'] ?? ''?> <?=htmlspecialchars($t['name'])?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="award-card">
          <div class="award-icon">👟</div>
          <div class="award-title">Artilheiro</div>
          <div class="award-sub">Quem vai fazer mais gols?</div>
          <input class="award-input" id="award_scorer" placeholder="Nome do jogador..."
                 value="<?=htmlspecialchars($pred['top_scorer'] ?? '')?>"
                 <?=$submitted ? 'readonly' : ''?>>
        </div>
        <div class="award-card">
          <div class="award-icon">🌟</div>
          <div class="award-title">Melhor Jogador</div>
          <div class="award-sub">Quem vai ganhar a Bola de Ouro da Copa?</div>
          <input class="award-input" id="award_player" placeholder="Nome do jogador..."
                 value="<?=htmlspecialchars($pred['best_player'] ?? '')?>"
                 <?=$submitted ? 'readonly' : ''?>>
        </div>
        <div class="award-card">
          <div class="award-icon">⭐</div>
          <div class="award-title">Revelação</div>
          <div class="award-sub">Quem vai ser a grande revelação?</div>
          <input class="award-input" id="award_revelation" placeholder="Nome do jogador..."
                 value="<?=htmlspecialchars($pred['revelation'] ?? '')?>"
                 <?=$submitted ? 'readonly' : ''?>>
        </div>
      </div>
      <?php if (!$submitted): ?>
      <div class="submit-bar">
        <div class="submit-bar-info">
          <strong>Tudo pronto?</strong> Após enviar não é possível editar.
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn-r secondary" onclick="saveDraft()"><i class="bi bi-floppy2"></i>Salvar rascunho</button>
          <button class="btn-r gold lg" onclick="submitPrediction()"><i class="bi bi-send-fill"></i>Enviar palpites</button>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php endif; /* seeded */ ?>
  </div><!-- /main -->
</div><!-- /page -->

<script>
const SUBMITTED   = <?=json_encode($submitted)?>;
const GROUPS_DATA = <?=json_encode(array_map(fn($g) => $g['teams'], $groups))?>;
const GROUP_KEYS  = <?=json_encode(array_keys($groups))?>;

// groupOrder: letter → [teamId, ...]
const groupOrder = {};
<?php foreach ($groups as $letter => $g):
    $saved = $predGroups[$letter] ?? null;
    $ids   = $saved ?: array_column($g['teams'], 'id');
?>
groupOrder[<?=json_encode($letter)?>] = <?=json_encode(array_map('intval', $ids))?>;
<?php endforeach; ?>

let selectedThirds = new Set(<?=json_encode(array_map('intval', $predThirds ?? []))?>);
const bracketState = <?=json_encode($predBracket ?: (object)[])?>;

// ── Helpers ──────────────────────────────────────────────────────────────────
function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function getTeamById(id) {
    for (const key of GROUP_KEYS) {
        const t = (GROUPS_DATA[key]||[]).find(x => x.id == id);
        if (t) return t;
    }
    return null;
}

// ── Groups ───────────────────────────────────────────────────────────────────
function moveTeam(letter, teamId, dir) {
    if (SUBMITTED) return;
    const order = groupOrder[letter];
    const idx = order.indexOf(+teamId);
    if (idx < 0) return;
    const ni = idx + dir;
    if (ni < 0 || ni >= order.length) return;
    [order[idx], order[ni]] = [order[ni], order[idx]];
    renderGroup(letter);
    updateThirdsForGroup(letter);
}

function renderGroup(letter) {
    const c = document.getElementById('gt_' + letter);
    if (!c) return;
    const order = groupOrder[letter];
    const rankCls = ['rank-1','rank-2','rank-3','rank-4'];
    const badges  = [
        '<span class="advance-badge advance-1">1º</span>',
        '<span class="advance-badge advance-2">2º</span>',
        '<span class="advance-badge advance-3">3º?</span>',
        ''
    ];
    c.innerHTML = '';
    order.forEach((tid, idx) => {
        const t = getTeamById(tid);
        if (!t) return;
        const div = document.createElement('div');
        div.className = 'team-row ' + rankCls[idx];
        div.dataset.teamId   = t.id;
        div.dataset.teamName = t.name;
        div.dataset.teamFlag = t.flag || '🏳';
        if (SUBMITTED) div.style.cursor = 'default';
        div.innerHTML =
            `<span class="team-rank">${idx+1}</span>` +
            `<span class="team-flag">${t.flag||'🏳'}</span>` +
            `<span class="team-name-sm">${escH(t.name)}</span>` +
            badges[idx] +
            (!SUBMITTED ? `<div class="team-arrows">
                <button class="arrow-btn" onclick="moveTeam('${letter}',${t.id},-1)" ${idx===0?'disabled':''}>▲</button>
                <button class="arrow-btn" onclick="moveTeam('${letter}',${t.id},1)"  ${idx===3?'disabled':''}>▼</button>
            </div>` : '');
        c.appendChild(div);
    });
}

function updateThirdsForGroup(letter) {
    const t = getTeamById(groupOrder[letter][2]);
    if (!t) return;
    const fe = document.getElementById('t3flag_' + letter);
    const ne = document.getElementById('t3name_' + letter);
    if (fe) fe.textContent = t.flag || '🏳';
    if (ne) ne.textContent = t.name;
}

// ── Thirds ───────────────────────────────────────────────────────────────────
function toggleThird(el) {
    if (SUBMITTED) return;
    const gid = +el.dataset.groupId;
    const check = el.querySelector('.third-check');
    if (selectedThirds.has(gid)) {
        selectedThirds.delete(gid);
        el.classList.remove('selected');
        if (check) check.style.display = 'none';
    } else {
        if (selectedThirds.size >= 8) { alert('Máximo 8 terceiros colocados.'); return; }
        selectedThirds.add(gid);
        el.classList.add('selected');
        if (check) check.style.display = 'block';
    }
    document.getElementById('thirdsCount').textContent = selectedThirds.size;
}

// ── Bracket ──────────────────────────────────────────────────────────────────
const ROUND_NAMES = {r32:'16 avos',r16:'Oitavas',qf:'Quartas',sf:'Semifinal',final:'Final'};
const ROUND_ORDER = ['r32','r16','qf','sf','final'];

let bracketMatchups = {r32:[],r16:[],qf:[],sf:[],final:[]};

function buildR32() {
    const f={}, s={};
    GROUP_KEYS.forEach(l => { f[l]=getTeamById(groupOrder[l][0]); s[l]=getTeamById(groupOrder[l][1]); });
    return [
        [f['A'],s['B']],[f['C'],s['D']],[f['E'],s['F']],[f['G'],s['H']],
        [f['I'],s['J']],[f['K'],s['L']],[f['B'],s['A']],[f['D'],s['C']],
        [f['F'],s['E']],[f['H'],s['G']],[f['J'],s['I']],[f['L'],s['K']],
        [null,null],[null,null],[null,null],[null,null],
    ];
}

function buildBracket() {
    bracketMatchups.r32 = buildR32();
    ['r16','qf','sf','final'].forEach(round => {
        const size = {r16:8,qf:4,sf:2,final:1}[round];
        bracketMatchups[round] = Array.from({length:size}, ()=>[null,null]);
    });
    // Re-apply saved winners
    Object.keys(bracketState).forEach(key => {
        const m = key.match(/^([a-z0-9]+)_(\d+)$/);
        if (!m) return;
        const [, round, idxStr] = m;
        const idx = +idxStr;
        const team = bracketState[key];
        if (!team || !bracketMatchups[round]) return;
        const nextRound = ROUND_ORDER[ROUND_ORDER.indexOf(round)+1];
        if (nextRound && bracketMatchups[nextRound]) {
            const ni = Math.floor(idx/2), ns = idx%2;
            if (!bracketMatchups[nextRound][ni]) bracketMatchups[nextRound][ni]=[null,null];
            bracketMatchups[nextRound][ni][ns] = team;
        }
    });
    renderBracket();
}

function getWinner(round, idx) {
    const w = bracketState[round+'_'+idx];
    return (w && typeof w === 'object') ? w : null;
}

function setWinner(round, matchIdx, teamJson) {
    if (SUBMITTED) return;
    const team = JSON.parse(teamJson);
    bracketState[round+'_'+matchIdx] = team;
    const nextRound = ROUND_ORDER[ROUND_ORDER.indexOf(round)+1];
    if (nextRound) {
        const ni = Math.floor(matchIdx/2), ns = matchIdx%2;
        if (!bracketMatchups[nextRound][ni]) bracketMatchups[nextRound][ni]=[null,null];
        bracketMatchups[nextRound][ni][ns] = team;
    }
    renderBracket();
}

function renderBracket() {
    const el = document.getElementById('bracketEl');
    if (!el) return;
    el.innerHTML = '';
    ROUND_ORDER.forEach(round => {
        const matches = bracketMatchups[round];
        if (!matches) return;
        const col = document.createElement('div');
        col.className = 'bracket-round';
        col.innerHTML = `<div class="bracket-round-title">${ROUND_NAMES[round]||round}</div>`;
        matches.forEach((match, idx) => {
            const [t1,t2] = match;
            const w = getWinner(round, idx);
            const teamJson1 = t1 ? JSON.stringify(t1).replace(/"/g,'&quot;') : '';
            const teamJson2 = t2 ? JSON.stringify(t2).replace(/"/g,'&quot;') : '';
            const div = document.createElement('div');
            div.className = 'bracket-match';
            div.innerHTML =
                `<div class="bracket-slot ${w&&t1&&w.id===t1.id?'winner':''} ${!t1?'tbd':''}"
                      onclick="${t1&&!SUBMITTED?`setWinner('${round}',${idx},'${teamJson1}')`:''}" >
                    <span class="bracket-slot-flag">${t1?t1.flag||'🏳':''}</span>
                    <span class="bracket-slot-name">${t1?escH(t1.name):'A definir'}</span>
                </div>
                <div class="bracket-divider"></div>
                <div class="bracket-slot ${w&&t2&&w.id===t2.id?'winner':''} ${!t2?'tbd':''}"
                      onclick="${t2&&!SUBMITTED?`setWinner('${round}',${idx},'${teamJson2}')`:''}" >
                    <span class="bracket-slot-flag">${t2?t2.flag||'🏳':''}</span>
                    <span class="bracket-slot-name">${t2?escH(t2.name):'A definir'}</span>
                </div>`;
            col.appendChild(div);
        });
        el.appendChild(col);
    });
}

// ── Payload ───────────────────────────────────────────────────────────────────
function buildPayload(action) {
    const groups = {};
    GROUP_KEYS.forEach(l => groups[l] = groupOrder[l]);
    return {
        action,
        groups,
        thirds:   [...selectedThirds],
        bracket:  bracketState,
        champion: document.getElementById('award_champion')?.value  || '',
        top_scorer:  document.getElementById('award_scorer')?.value || '',
        best_player: document.getElementById('award_player')?.value || '',
        revelation:  document.getElementById('award_revelation')?.value || '',
    };
}

async function saveDraft() {
    const res = await fetch('copa26.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(buildPayload('save'))});
    const d = await res.json();
    if (d.ok) showToast('Rascunho salvo!');
    else showToast('Erro ao salvar.', true);
}

async function submitPrediction() {
    if (!confirm('Tem certeza? Após enviar não é possível editar seus palpites.')) return;
    await fetch('copa26.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(buildPayload('save'))});
    const res = await fetch('copa26.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'submit'})});
    const d = await res.json();
    if (d.ok) location.reload();
    else showToast('Erro ao enviar.', true);
}

// ── Scores ───────────────────────────────────────────────────────────────────
async function saveScores() {
    const scores = {};
    document.querySelectorAll('.score-card[data-match]').forEach(card => {
        const mid = card.dataset.match;
        const h = document.getElementById('sh_'+mid)?.value;
        const a = document.getElementById('sa_'+mid)?.value;
        if (h !== undefined && a !== undefined) scores[mid] = {h: +h||0, a: +a||0};
    });
    const res = await fetch('copa26.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'scores', scores})});
    const d = await res.json();
    showToast(d.ok ? 'Placares salvos!' : 'Erro ao salvar.', !d.ok);
}

async function setResult(matchId) {
    const h = prompt('Gols do time da casa:');
    if (h === null) return;
    const a = prompt('Gols do time visitante:');
    if (a === null) return;
    const res = await fetch('copa26.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'set_result', match_id:matchId, home:+h, away:+a})});
    const d = await res.json();
    if (d.ok) location.reload();
}

// ── Tabs ──────────────────────────────────────────────────────────────────────
document.querySelectorAll('.copa-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.copa-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.copa-pane').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('pane-'+btn.dataset.tab)?.classList.add('active');
        if (btn.dataset.tab === 'bracket') buildBracket();
    });
});

function nextTab(tab) {
    if (!SUBMITTED) {
        fetch('copa26.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(buildPayload('save'))}).catch(()=>{});
    }
    document.querySelectorAll('.copa-tab').forEach(b => b.classList.toggle('active', b.dataset.tab===tab));
    document.querySelectorAll('.copa-pane').forEach(p => p.classList.toggle('active', p.id==='pane-'+tab));
    if (tab === 'bracket') buildBracket();
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, err=false) {
    let t = document.getElementById('fba-toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'fba-toast';
        t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);padding:10px 20px;border-radius:8px;font-size:13px;font-weight:600;z-index:9999;transition:opacity .3s;font-family:var(--font)';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.background = err ? '#fc0025' : '#22c55e';
    t.style.color = '#fff';
    t.style.opacity = '1';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.style.opacity='0', 2500);
}

// ── Sidebar ───────────────────────────────────────────────────────────────────
function openSidebar()  { document.getElementById('sidebar').classList.add('open');    document.getElementById('sbOverlay').classList.add('open'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('sbOverlay').classList.remove('open'); }

// ── Init ──────────────────────────────────────────────────────────────────────
<?php if ($seeded): ?>
GROUP_KEYS.forEach(l => renderGroup(l));
<?php endif; ?>
</script>
</body>
</html>
