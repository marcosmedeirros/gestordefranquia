<?php
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user    = getUserSession();
$pdo     = db();
$isAdmin = hasAdminAccess($pdo, (int)$user['id']);

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

// ── Seed function ─────────────────────────────────────────────────────────────
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

// ── Actions ───────────────────────────────────────────────────────────────────
$jsonAction = $_GET['action'] ?? '';

if ($jsonAction === 'seed' && $isAdmin) {
    seedCopa26($pdo);
    header('Location: /copa26.php'); exit;
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
            $user['id'],
            json_encode($body['groups']   ?? []),
            json_encode($body['thirds']   ?? []),
            json_encode($body['bracket']  ?? []),
            $body['top_scorer']  ?? null,
            $body['best_player'] ?? null,
            $body['revelation']  ?? null,
            $body['champion']    ?? null,
        ]);
        echo json_encode(['ok' => true]); exit;
    }

    if ($act === 'submit') {
        $pdo->prepare("UPDATE copa26_predictions SET submitted_at=NOW() WHERE user_id=? AND submitted_at IS NULL")
            ->execute([$user['id']]);
        echo json_encode(['ok' => true]); exit;
    }

    if ($act === 'scores') {
        $scores = $body['scores'] ?? [];
        $stmt = $pdo->prepare("INSERT INTO copa26_score_preds (user_id,match_id,score_home,score_away) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE score_home=VALUES(score_home),score_away=VALUES(score_away)");
        foreach ($scores as $mid => $s)
            $stmt->execute([$user['id'], (int)$mid, (int)($s['h']??0), (int)($s['a']??0)]);
        echo json_encode(['ok' => true]); exit;
    }

    if ($act === 'set_result' && $isAdmin) {
        $stmt = $pdo->prepare("UPDATE copa26_matches SET score_home=?,score_away=? WHERE id=?");
        $stmt->execute([(int)($body['home']??0), (int)($body['away']??0), (int)($body['match_id']??0)]);
        echo json_encode(['ok' => true]); exit;
    }

    echo json_encode(['ok' => false]); exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────
$groups = [];
try {
    $rows = $pdo->query("SELECT g.id gid, g.letter, t.id tid, t.name, t.flag
        FROM copa26_groups g LEFT JOIN copa26_teams t ON t.group_id=g.id
        ORDER BY g.letter, t.name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $l = $r['letter'];
        if (!isset($groups[$l])) $groups[$l] = ['id'=>$r['gid'],'teams'=>[]];
        if ($r['tid']) $groups[$l]['teams'][] = ['id'=>$r['tid'],'name'=>$r['name'],'flag'=>$r['flag']??''];
    }
} catch (Exception $e) {}

$allTeams = [];
foreach ($groups as $g) foreach ($g['teams'] as $t) $allTeams[$t['id']] = $t;

$pred = null;
try {
    $s = $pdo->prepare("SELECT * FROM copa26_predictions WHERE user_id=?");
    $s->execute([$user['id']]);
    $pred = $s->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {}

$submitted = !empty($pred['submitted_at']);

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
        $ids = array_column($todayMatches,'id');
        $ph  = implode(',', array_fill(0,count($ids),'?'));
        $s   = $pdo->prepare("SELECT * FROM copa26_score_preds WHERE user_id=? AND match_id IN ($ph)");
        $s->execute(array_merge([$user['id']],$ids));
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $myScores[$r['match_id']] = $r;
    } catch (Exception $e) {}
}

$seeded = !empty($groups);
$predGroups  = $pred ? (json_decode($pred['groups_json']  ?? '[]', true) ?: []) : [];
$predThirds  = $pred ? (json_decode($pred['thirds_json']  ?? '[]', true) ?: []) : [];
$predBracket = $pred ? (json_decode($pred['bracket_json'] ?? '{}', true) ?: []) : [];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=5.0">
<script>document.documentElement.dataset.theme=localStorage.getItem('fba-theme')||'dark';</script>
<?php include __DIR__.'/includes/head-pwa.php'; ?>
<title>Bolão Copa 2026 · FBA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/styles.css">
<style>
:root{
  --red:#fc0025;--red-soft:rgba(252,0,37,.10);--red-glow:rgba(252,0,37,.18);
  --bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;
  --border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--border-red:rgba(252,0,37,.22);
  --text:#f0f0f3;--text-2:#868690;--text-3:#48484f;
  --green:#22c55e;--amber:#f59e0b;--blue:#3b82f6;
  --gold:#f59e0b;
  --sidebar-w:260px;--font:'Poppins',sans-serif;
  --radius:14px;--radius-sm:10px;--t:200ms;--ease:cubic-bezier(.2,.8,.2,1);
}
:root[data-theme="light"]{
  --bg:#f6f7fb;--panel:#fff;--panel-2:#f2f4f8;--panel-3:#e9edf4;
  --border:#e3e6ee;--border-md:#d7dbe6;--text:#111217;--text-2:#5b6270;--text-3:#8b93a5;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
.app{display:flex;min-height:100vh}
.sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:300;transition:transform var(--t) var(--ease);overflow-y:auto;scrollbar-width:none}
.sidebar::-webkit-scrollbar{display:none}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:299}
.sb-overlay.show{display:block}
.sb-team{margin:14px 14px 0;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;display:flex;align-items:center;gap:10px}
.sb-team img{width:40px;height:40px;border-radius:9px;object-fit:cover;border:1px solid var(--border-md)}
.sb-team-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.2}
.sb-team-league{font-size:11px;color:var(--red);font-weight:600}
.sb-nav{flex:1;padding:12px 10px 8px}
.sb-section{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:12px 10px 5px}
.sb-nav a{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t) var(--ease)}
.sb-nav a i{font-size:15px;width:18px;text-align:center;flex-shrink:0}
.sb-nav a:hover{background:var(--panel-2);color:var(--text)}
.sb-nav a.active{background:var(--red-soft);color:var(--red);font-weight:600}
.sb-theme-toggle{margin:0 14px 12px;padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--panel-2);color:var(--text);display:flex;align-items:center;justify-content:center;gap:8px;font-size:12px;font-weight:600;cursor:pointer}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0}
.sb-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md)}
.sb-username{font-size:12px;font-weight:500;color:var(--text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sb-logout{width:26px;height:26px;border-radius:7px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;text-decoration:none;transition:all var(--t) var(--ease)}
.sb-logout:hover{background:var(--red-soft);border-color:var(--red);color:var(--red)}
.topbar{position:fixed;top:0;left:0;right:0;z-index:240;background:var(--panel);border-bottom:1px solid var(--border);padding:0 16px;height:54px;display:none;align-items:center;gap:12px}
.topbar-menu-btn{background:none;border:none;color:var(--text-2);font-size:20px;cursor:pointer;padding:4px;display:none}
.topbar-title{font-size:14px;font-weight:600;color:var(--text)}
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh}
.page-hero{padding:28px 28px 0;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:14px}
.page-hero-title{font-size:22px;font-weight:700;color:var(--text)}
.page-hero-sub{font-size:13px;color:var(--text-2);margin-top:4px}
.content{padding:24px 28px 60px}
.btn-r{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:500;border:none;cursor:pointer;transition:opacity var(--t);font-family:var(--font)}
.btn-r:hover{opacity:.85}
.btn-r.primary{background:var(--red);color:#fff}
.btn-r.secondary{background:var(--panel-3);color:var(--text-2);border:1px solid var(--border)}
.btn-r.outline{background:transparent;color:var(--red);border:1px solid var(--border-red)}
.btn-r.gold{background:rgba(245,158,11,.15);color:var(--gold);border:1px solid rgba(245,158,11,.3)}
.btn-r:disabled{opacity:.35;cursor:not-allowed}
.btn-r.sm{padding:5px 12px;font-size:12px}
.btn-r.lg{padding:11px 24px;font-size:14px;font-weight:700}
.tag{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600}
.tag.gray{background:var(--panel-3);color:var(--text-2)}
.tag.green{background:rgba(34,197,94,.12);color:#22c55e}
.tag.gold{background:rgba(245,158,11,.12);color:var(--gold)}

/* ── Copa header ── */
.copa-hero{background:linear-gradient(135deg,#0a1628 0%,#1a0510 100%);border:1px solid var(--border-red);border-radius:var(--radius);padding:24px;margin-bottom:24px;position:relative;overflow:hidden}
.copa-hero::before{content:'⚽';position:absolute;right:20px;top:50%;transform:translateY(-50%);font-size:80px;opacity:.06;pointer-events:none}
.copa-hero-title{font-size:26px;font-weight:800;color:#fff;letter-spacing:-.02em}
.copa-hero-title span{color:var(--red)}
.copa-hero-sub{font-size:13px;color:rgba(255,255,255,.5);margin-top:4px}

/* ── Tabs ── */
.copa-tabs{display:flex;border-bottom:1px solid var(--border);margin-bottom:24px;overflow-x:auto;scrollbar-width:none}
.copa-tabs::-webkit-scrollbar{display:none}
.copa-tab{padding:10px 20px;font-size:13px;font-weight:500;color:var(--text-2);border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap;font-family:var(--font);transition:color var(--t)}
.copa-tab:hover{color:var(--text)}
.copa-tab.active{color:var(--red);border-bottom-color:var(--red);font-weight:600}
.copa-pane{display:none}
.copa-pane.active{display:block}

/* ── Daily scores ── */
.scores-header{font-size:16px;font-weight:700;color:var(--text);margin-bottom:16px;display:flex;align-items:center;gap:8px}
.score-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 18px;display:flex;align-items:center;gap:12px;margin-bottom:8px}
.score-team{display:flex;align-items:center;gap:8px;flex:1}
.score-team-name{font-size:13px;font-weight:600;color:var(--text)}
.score-team.right{flex-direction:row-reverse;justify-content:flex-start}
.score-team.right .score-team-name{text-align:right}
.score-input-wrap{display:flex;align-items:center;gap:8px;flex-shrink:0}
.score-input{width:44px;height:36px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:8px;color:var(--text);font-size:16px;font-weight:700;text-align:center;font-family:var(--font)}
.score-input:focus{outline:none;border-color:var(--red)}
.score-sep{color:var(--text-3);font-weight:700}
.score-time{font-size:11px;color:var(--text-3);text-align:center;width:44px;flex-shrink:0}
.score-result{font-size:18px;font-weight:800;color:var(--green);text-align:center;min-width:60px}

/* ── Groups grid ── */
.groups-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.group-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden}
.group-card-header{padding:10px 14px;background:var(--panel-2);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.group-letter{font-size:13px;font-weight:700;color:var(--red)}
.group-label{font-size:10px;color:var(--text-3);text-transform:uppercase;letter-spacing:.08em}
.group-teams{padding:8px}
.team-row{display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:8px;cursor:pointer;transition:background var(--t);border:1px solid transparent;margin-bottom:4px;user-select:none}
.team-row:last-child{margin-bottom:0}
.team-row:hover{background:var(--panel-2)}
.team-row.rank-1{background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.2)}
.team-row.rank-2{background:rgba(59,130,246,.06);border-color:rgba(59,130,246,.15)}
.team-row.rank-3{background:rgba(255,255,255,.03)}
.team-rank{width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0}
.rank-1 .team-rank{background:rgba(245,158,11,.2);color:var(--gold)}
.rank-2 .team-rank{background:rgba(59,130,246,.15);color:var(--blue)}
.rank-3 .team-rank{background:var(--panel-3);color:var(--text-3)}
.rank-4 .team-rank{background:var(--panel-3);color:var(--text-3)}
.team-flag{font-size:16px;flex-shrink:0}
.team-name-sm{font-size:12px;font-weight:500;color:var(--text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.team-arrows{display:flex;flex-direction:column;gap:2px;flex-shrink:0}
.arrow-btn{background:none;border:none;color:var(--text-3);cursor:pointer;padding:1px 3px;font-size:10px;line-height:1;transition:color var(--t)}
.arrow-btn:hover{color:var(--text)}
.arrow-btn:disabled{opacity:.2;cursor:default}
.advance-badge{font-size:9px;font-weight:700;padding:1px 5px;border-radius:4px}
.advance-1{background:rgba(245,158,11,.15);color:var(--gold)}
.advance-2{background:rgba(59,130,246,.12);color:var(--blue)}
.advance-3{background:rgba(255,255,255,.05);color:var(--text-3)}

/* ── 3rd place selector ── */
.thirds-section{margin-top:24px}
.thirds-title{font-size:15px;font-weight:600;color:var(--text);margin-bottom:4px}
.thirds-sub{font-size:12px;color:var(--text-2);margin-bottom:14px}
.thirds-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:8px}
.third-slot{background:var(--panel);border:2px solid var(--border);border-radius:10px;padding:10px 8px;text-align:center;cursor:pointer;transition:all var(--t);position:relative}
.third-slot:hover{border-color:var(--border-md)}
.third-slot.selected{border-color:var(--blue);background:rgba(59,130,246,.06)}
.third-slot.locked{border-color:var(--border-red);background:var(--red-soft);cursor:default}
.third-slot-flag{font-size:20px;margin-bottom:4px}
.third-slot-name{font-size:10px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.third-slot-group{font-size:9px;color:var(--text-3)}
.third-check{position:absolute;top:4px;right:4px;font-size:10px;color:var(--blue)}

/* ── Bracket ── */
.bracket-wrap{overflow-x:auto;padding-bottom:8px}
.bracket{display:flex;gap:0;align-items:stretch;min-width:900px}
.bracket-round{display:flex;flex-direction:column;justify-content:space-around;flex:1;padding:0 4px}
.bracket-round-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);text-align:center;padding:8px 0;border-bottom:1px solid var(--border);margin-bottom:8px}
.bracket-match{background:var(--panel);border:1px solid var(--border);border-radius:8px;overflow:hidden;margin:4px 0;cursor:pointer;transition:border-color var(--t)}
.bracket-match:hover{border-color:var(--border-md)}
.bracket-slot{padding:6px 10px;display:flex;align-items:center;gap:6px;font-size:11px;font-weight:500;color:var(--text);min-height:30px;transition:background var(--t)}
.bracket-slot.winner{background:rgba(34,197,94,.08);color:var(--green);font-weight:700}
.bracket-slot.tbd{color:var(--text-3)}
.bracket-slot-flag{font-size:13px;flex-shrink:0}
.bracket-slot-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bracket-divider{height:1px;background:var(--border);margin:0 8px}
.bracket-connector{width:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center}

/* ── Awards ── */
.awards-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.award-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);padding:18px}
.award-icon{font-size:28px;margin-bottom:8px}
.award-title{font-size:14px;font-weight:600;color:var(--text);margin-bottom:4px}
.award-sub{font-size:12px;color:var(--text-2);margin-bottom:12px}
.award-select{width:100%;background:var(--panel-2);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-size:13px;font-family:var(--font)}
.award-select:focus{outline:none;border-color:var(--red)}
.award-input{width:100%;background:var(--panel-2);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-size:13px;font-family:var(--font)}
.award-input::placeholder{color:var(--text-3)}
.award-input:focus{outline:none;border-color:var(--red)}

/* ── Submit bar ── */
.submit-bar{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:24px;flex-wrap:wrap}
.submit-bar-info{font-size:13px;color:var(--text-2)}
.submit-bar-info strong{color:var(--text)}
.submitted-banner{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);border-radius:var(--radius-sm);padding:14px 20px;display:flex;align-items:center;gap:10px;margin-top:24px;color:var(--green);font-weight:600}

/* ── No data ── */
.no-data{text-align:center;padding:60px 20px}
.no-data-icon{font-size:48px;margin-bottom:16px}
.no-data-title{font-size:18px;font-weight:600;color:var(--text);margin-bottom:8px}
.no-data-sub{font-size:13px;color:var(--text-2)}

/* ── Responsive ── */
@media(max-width:992px){
  :root{--sidebar-w:0px}
  .sidebar{transform:translateX(-260px)}
  .sidebar.open{transform:translateX(0)}
  .main{margin-left:0;padding-top:54px}
  .topbar{display:flex}
  .topbar-menu-btn{display:flex}
  .page-hero{padding:16px 16px 0}
  .content{padding:16px 16px 32px}
  .groups-grid{grid-template-columns:repeat(2,1fr)}
  .thirds-grid{grid-template-columns:repeat(4,1fr)}
  .awards-grid{grid-template-columns:1fr}
}
@media(max-width:576px){
  .groups-grid{grid-template-columns:repeat(2,1fr)}
  .thirds-grid{grid-template-columns:repeat(3,1fr)}
  .score-card{flex-wrap:wrap;gap:8px}
  .score-team{min-width:0}
  .copa-hero-title{font-size:20px}
}
</style>
</head>
<body>
<div class="app">

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <?php
  $myTeam = null;
  try {
      $s = $pdo->prepare('SELECT * FROM teams WHERE user_id=? LIMIT 1');
      $s->execute([$user['id']]);
      $myTeam = $s->fetch() ?: null;
  } catch(Exception $e){}
  if ($myTeam): ?>
  <div class="sb-team">
      <img src="<?=htmlspecialchars($myTeam['photo_url']??'/img/default-team.png')?>" onerror="this.src='/img/default-team.png'" alt="">
      <div>
          <div class="sb-team-name"><?=htmlspecialchars(trim(($myTeam['city']??'').' '.($myTeam['name']??'')))?></div>
          <div class="sb-team-league"><?=htmlspecialchars($myTeam['league']??'')?></div>
      </div>
  </div>
  <?php endif; ?>
  <nav class="sb-nav">
      <div class="sb-section">Principal</div>
      <a href="/dashboard.php"><i class="bi bi-house-door-fill"></i> Dashboard</a>
      <a href="/teams.php"><i class="bi bi-people-fill"></i> Times</a>
      <a href="/my-roster.php"><i class="bi bi-person-fill"></i> Meu Elenco</a>
      <a href="/players.php"><i class="bi bi-person-lines-fill"></i> Jogadores</a>
      <a href="/picks.php"><i class="bi bi-calendar-check-fill"></i> Picks</a>
      <a href="/trades.php"><i class="bi bi-arrow-left-right"></i> Trades</a>
      <a href="/mercado.php"><i class="bi bi-shop"></i> Mercado</a>
      <a href="/free-agency.php"><i class="bi bi-coin"></i> Free Agency</a>
      <div class="sb-section">Copa 2026</div>
      <a href="/copa26.php" class="active"><i class="bi bi-trophy-fill"></i> Bolão Copa 26</a>
      <div class="sb-section">Liga</div>
      <a href="/rankings.php"><i class="bi bi-bar-chart-fill"></i> Rankings</a>
      <a href="/history.php"><i class="bi bi-clock-history"></i> Histórico</a>
      <a href="/diretrizes.php"><i class="bi bi-clipboard-data"></i> Diretrizes</a>
      <a href="/mundo-fba.php"><i class="bi bi-globe2"></i> Mundo FBA</a>
      <a href="/ouvidoria.php"><i class="bi bi-chat-dots"></i> Ouvidoria</a>
      <?php if ($isAdmin): ?>
      <div class="sb-section">Admin</div>
      <a href="/admin.php"><i class="bi bi-shield-lock-fill"></i> Admin</a>
      <?php endif; ?>
      <div class="sb-section">Conta</div>
      <a href="/settings.php"><i class="bi bi-gear-fill"></i> Minha Conta</a>
  </nav>
  <button class="sb-theme-toggle" type="button" data-theme-toggle>
      <i class="bi bi-moon"></i><span>Modo escuro</span>
  </button>
  <div class="sb-footer">
      <img src="<?=htmlspecialchars($user['photo_url']??'/img/default-avatar.png')?>" class="sb-avatar" onerror="this.src='/img/default-avatar.png'" alt="">
      <span class="sb-username"><?=htmlspecialchars($user['name']??'')?></span>
      <a href="/logout.php" class="sb-logout"><i class="bi bi-box-arrow-right"></i></a>
  </div>
</aside>
<div class="sb-overlay" id="sbOverlay"></div>

<main class="main">
  <!-- Topbar -->
  <div class="topbar">
      <button class="topbar-menu-btn" id="sidebarToggle"><i class="bi bi-list"></i></button>
      <span class="topbar-title"><i class="bi bi-trophy-fill me-2" style="color:var(--gold)"></i>Bolão Copa 2026</span>
  </div>

  <!-- Hero -->
  <div class="page-hero">
      <div>
          <h1 class="page-hero-title"><i class="bi bi-trophy-fill me-2" style="color:var(--gold)"></i>Bolão Copa 2026</h1>
          <p class="page-hero-sub">Faça seus palpites, monte o bracket e acerte os placares</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <?php if ($submitted): ?>
          <span class="tag green"><i class="bi bi-check-circle-fill"></i>Enviado</span>
          <?php elseif ($seeded): ?>
          <span class="tag gray">Palpites abertos</span>
          <?php endif; ?>
          <?php if ($isAdmin): ?>
          <?php if (!$seeded): ?>
          <a href="/copa26.php?action=seed" class="btn-r outline sm"><i class="bi bi-database-fill-add"></i>Configurar grupos</a>
          <?php else: ?>
          <a href="/copa26.php?action=seed" class="btn-r secondary sm" onclick="return confirm('Reconfigurar vai apagar todos os palpites. Confirmar?')"><i class="bi bi-arrow-counterclockwise"></i>Reconfigurar</a>
          <?php endif; ?>
          <?php endif; ?>
      </div>
  </div>

  <div class="content">

  <?php if (!$seeded): ?>
  <div class="no-data">
      <div class="no-data-icon">⚽</div>
      <div class="no-data-title">Bolão ainda não configurado</div>
      <div class="no-data-sub">Aguarde o administrador configurar os grupos da Copa 2026.</div>
      <?php if ($isAdmin): ?>
      <a href="/copa26.php?action=seed" class="btn-r primary mt-3 d-inline-flex">
          <i class="bi bi-database-fill-add"></i>Configurar agora
      </a>
      <?php endif; ?>
  </div>
  <?php else: ?>

  <!-- ── Daily scores ────────────────────────────────── -->
  <?php if ($todayMatches): ?>
  <div style="margin-bottom:28px">
      <div class="scores-header">
          <i class="bi bi-calendar-event-fill" style="color:var(--red)"></i>
          Acerte o Placar — Jogos de Hoje
      </div>
      <div id="scorecardsList">
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
              <span class="score-team-flag" style="font-size:20px"><?=$hFlag?></span>
              <span class="score-team-name"><?=htmlspecialchars($hName)?></span>
          </div>
          <?php if ($hasResult): ?>
          <div class="score-result"><?=$m['score_home']?> – <?=$m['score_away']?></div>
          <?php else: ?>
          <div class="score-input-wrap">
              <input type="number" min="0" max="30" class="score-input" id="sh_<?=$m['id']?>"
                     value="<?=$sp?$sp['score_home']:''?>" placeholder="0"
                     <?=$submitted?'readonly':''?>>
              <span class="score-sep">×</span>
              <input type="number" min="0" max="30" class="score-input" id="sa_<?=$m['id']?>"
                     value="<?=$sp?$sp['score_away']:''?>" placeholder="0"
                     <?=$submitted?'readonly':''?>>
          </div>
          <?php endif; ?>
          <div class="score-team right">
              <span class="score-team-name"><?=htmlspecialchars($aName)?></span>
              <span style="font-size:20px"><?=$aFlag?></span>
          </div>
          <div class="score-time"><?=htmlspecialchars($m['match_time']??'')?></div>
          <?php if ($isAdmin && !$hasResult): ?>
          <button class="btn-r secondary sm ms-2" onclick="setResult(<?=$m['id']?>)">
              <i class="bi bi-check2"></i>Resultado
          </button>
          <?php endif; ?>
      </div>
      <?php endforeach; ?>
      </div>
      <?php if (!$submitted): ?>
      <button class="btn-r primary mt-3" onclick="saveScores()">
          <i class="bi bi-check2-circle"></i>Salvar placares
      </button>
      <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ── Copa hero ── -->
  <div class="copa-hero">
      <div class="copa-hero-title">Copa do Mundo <span>2026</span></div>
      <div class="copa-hero-sub">48 seleções · 12 grupos · Junho–Julho 2026 · USA, Canadá, México</div>
  </div>

  <!-- ── Submitted banner ── -->
  <?php if ($submitted): ?>
  <div class="submitted-banner">
      <i class="bi bi-check-circle-fill" style="font-size:20px"></i>
      Palpite enviado em <?=date('d/m/Y H:i', strtotime($pred['submitted_at'] ?? ''))?>! Boa sorte! 🎉
  </div>
  <?php endif; ?>

  <!-- ── Tabs ── -->
  <div class="copa-tabs" id="copaTabs">
      <button class="copa-tab active" data-tab="grupos">⚽ Grupos</button>
      <button class="copa-tab" data-tab="thirds">🥉 3º Lugar</button>
      <button class="copa-tab" data-tab="bracket">🏆 Bracket</button>
      <button class="copa-tab" data-tab="premios">🌟 Prêmios</button>
  </div>

  <!-- ── Grupos ── -->
  <div class="copa-pane active" id="pane-grupos">
      <p style="font-size:12px;color:var(--text-2);margin-bottom:16px">
          <i class="bi bi-info-circle me-1"></i>Clique nas setas para ordenar os times. 1º e 2º avançam direto, 3º entra na disputa de melhor terceiro.
      </p>
      <div class="groups-grid" id="groupsGrid">
      <?php foreach ($groups as $letter => $g):
          $savedOrder = $predGroups[$letter] ?? null; // array of team IDs in order
          $teams = $g['teams'];
          if ($savedOrder) {
              usort($teams, function($a,$b) use ($savedOrder) {
                  $pa = array_search($a['id'], $savedOrder);
                  $pb = array_search($b['id'], $savedOrder);
                  $pa = $pa === false ? 99 : $pa;
                  $pb = $pb === false ? 99 : $pb;
                  return $pa - $pb;
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
              $cls  = "rank-$rank";
              $badge = $rank === 1 ? '<span class="advance-badge advance-1">1º</span>' :
                      ($rank === 2 ? '<span class="advance-badge advance-2">2º</span>' :
                      ($rank === 3 ? '<span class="advance-badge advance-3">3º?</span>' : ''));
          ?>
          <div class="team-row <?=$cls?>" data-team-id="<?=$t['id']?>" data-team-name="<?=htmlspecialchars($t['name'])?>" data-team-flag="<?=htmlspecialchars($t['flag']??'🏳')?>" <?=$submitted?'style="cursor:default"':'?>'?>>
              <span class="team-rank"><?=$rank?></span>
              <span class="team-flag"><?=$t['flag']??'🏳'?></span>
              <span class="team-name-sm"><?=htmlspecialchars($t['name'])?></span>
              <?=$badge?>
              <?php if (!$submitted): ?>
              <div class="team-arrows">
                  <button class="arrow-btn" onclick="moveTeam('<?=$letter?>',<?=$t['id']?>,-1)" <?=$rank===1?'disabled':''?> title="Subir">▲</button>
                  <button class="arrow-btn" onclick="moveTeam('<?=$letter?>',<?=$t['id']?>,1)" <?=$rank===4?'disabled':''?> title="Descer">▼</button>
              </div>
              <?php endif; ?>
          </div>
          <?php endforeach; ?>
          </div>
      </div>
      <?php endforeach; ?>
      </div>
      <?php if (!$submitted): ?>
      <div style="margin-top:16px;text-align:right">
          <button class="btn-r outline" onclick="nextTab('thirds')">
              Próximo: 3º Lugar <i class="bi bi-arrow-right"></i>
          </button>
      </div>
      <?php endif; ?>
  </div>

  <!-- ── 3º Lugar ── -->
  <div class="copa-pane" id="pane-thirds">
      <div class="thirds-section">
          <div class="thirds-title">Melhores 3ºs Colocados</div>
          <div class="thirds-sub">Selecione 8 seleções que avançam como melhores terceiros colocados (uma por grupo)</div>
          <div class="thirds-grid" id="thirdsGrid">
          <?php foreach ($groups as $letter => $g):
              $teams = $g['teams'];
              // The 3rd place team based on current user rankings
          ?>
          <div class="third-slot <?=in_array($g['id'], $predThirds??[])?'selected':''?>"
               data-group="<?=$letter?>"
               data-group-id="<?=$g['id']?>"
               onclick="<?=$submitted?'':'toggleThird(this)'?>"
               id="third_<?=$letter?>">
              <div class="third-check" style="display:<?=in_array($g['id'],$predThirds??[])?'block':'none'?>"><i class="bi bi-check-circle-fill"></i></div>
              <div class="third-slot-flag" id="t3flag_<?=$letter?>"><?=$teams[2]['flag']??'🏳'?></div>
              <div class="third-slot-name" id="t3name_<?=$letter?>"><?=htmlspecialchars($teams[2]['name']??'?')?></div>
              <div class="third-slot-group">Grupo <?=$letter?></div>
          </div>
          <?php endforeach; ?>
          </div>
          <div style="margin-top:8px;font-size:12px;color:var(--text-2)">
              Selecionados: <strong id="thirdsCount"><?=count($predThirds??[])?></strong>/8
          </div>
          <?php if (!$submitted): ?>
          <div style="margin-top:16px;text-align:right">
              <button class="btn-r outline" onclick="nextTab('bracket')">
                  Próximo: Bracket <i class="bi bi-arrow-right"></i>
              </button>
          </div>
          <?php endif; ?>
      </div>
  </div>

  <!-- ── Bracket ── -->
  <div class="copa-pane" id="pane-bracket">
      <p style="font-size:12px;color:var(--text-2);margin-bottom:16px">
          <i class="bi bi-info-circle me-1"></i>Clique no time para avançá-lo na próxima fase. O bracket é gerado automaticamente dos seus palpites de grupo.
      </p>
      <div class="bracket-wrap">
          <div class="bracket" id="bracketEl"></div>
      </div>
      <?php if (!$submitted): ?>
      <div style="margin-top:16px;text-align:right">
          <button class="btn-r outline" onclick="nextTab('premios')">
              Próximo: Prêmios <i class="bi bi-arrow-right"></i>
          </button>
      </div>
      <?php endif; ?>
  </div>

  <!-- ── Prêmios ── -->
  <div class="copa-pane" id="pane-premios">
      <div class="awards-grid">
          <div class="award-card">
              <div class="award-icon">🥇</div>
              <div class="award-title">Campeão</div>
              <div class="award-sub">Quem vai ser campeão do mundo?</div>
              <select class="award-select" id="award_champion" <?=$submitted?'disabled':''?>>
                  <option value="">Selecione...</option>
                  <?php foreach ($allTeams as $t): ?>
                  <option value="<?=htmlspecialchars($t['name'])?>" <?=($pred['champion']??'')===$t['name']?'selected':''?>>
                      <?=$t['flag']??''?> <?=htmlspecialchars($t['name'])?>
                  </option>
                  <?php endforeach; ?>
              </select>
          </div>
          <div class="award-card">
              <div class="award-icon">👟</div>
              <div class="award-title">Artilheiro</div>
              <div class="award-sub">Quem vai fazer mais gols na Copa?</div>
              <input class="award-input" id="award_scorer" placeholder="Nome do jogador..."
                     value="<?=htmlspecialchars($pred['top_scorer']??'')?>"
                     <?=$submitted?'readonly':''?>>
          </div>
          <div class="award-card">
              <div class="award-icon">🌟</div>
              <div class="award-title">Melhor Jogador</div>
              <div class="award-sub">Quem vai ganhar a Bola de Ouro da Copa?</div>
              <input class="award-input" id="award_player" placeholder="Nome do jogador..."
                     value="<?=htmlspecialchars($pred['best_player']??'')?>"
                     <?=$submitted?'readonly':''?>>
          </div>
          <div class="award-card">
              <div class="award-icon">⭐</div>
              <div class="award-title">Revelação</div>
              <div class="award-sub">Quem vai ser o grande revelação do torneio?</div>
              <input class="award-input" id="award_revelation" placeholder="Nome do jogador..."
                     value="<?=htmlspecialchars($pred['revelation']??'')?>"
                     <?=$submitted?'readonly':''?>>
          </div>
      </div>

      <?php if (!$submitted): ?>
      <!-- Submit bar -->
      <div class="submit-bar">
          <div class="submit-bar-info">
              <strong>Tudo pronto?</strong> Após enviar, não será possível editar os palpites.
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn-r secondary" onclick="saveDraft()">
                  <i class="bi bi-floppy2"></i>Salvar rascunho
              </button>
              <button class="btn-r gold lg" onclick="submitPrediction()">
                  <i class="bi bi-send-fill"></i>Enviar palpites
              </button>
          </div>
      </div>
      <?php endif; ?>
  </div>

  <?php endif; /* seeded */ ?>
  </div><!-- /content -->
</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── State ────────────────────────────────────────────────────────────────────
const SUBMITTED = <?=json_encode($submitted)?>;
const GROUPS_DATA = <?=json_encode(array_map(function($g){ return $g['teams']; }, $groups))?>;
const GROUP_KEYS  = <?=json_encode(array_keys($groups))?>;

// groups order: letter → [teamId, teamId, ...]
const groupOrder = {};
<?php foreach ($groups as $letter => $g):
    $saved = $predGroups[$letter] ?? null;
    $ids   = $saved ?: array_column($g['teams'], 'id');
?>
groupOrder['<?=$letter?>'] = <?=json_encode(array_map('intval', $ids))?>;
<?php endforeach; ?>

// thirds
let selectedThirds = new Set(<?=json_encode(array_map('intval', $predThirds??[]))?>);

// bracket: round → matchIndex → winnerName
const bracketState = <?=json_encode($predBracket ?: (object)[])?>;

// ── Groups ───────────────────────────────────────────────────────────────────
function getTeamById(id) {
    for (const key of GROUP_KEYS) {
        const arr = GROUPS_DATA[key];
        if (!arr) continue;
        const t = arr.find(x => x.id == id);
        if (t) return t;
    }
    return null;
}

function moveTeam(letter, teamId, dir) {
    if (SUBMITTED) return;
    const order = groupOrder[letter];
    const idx = order.indexOf(+teamId);
    if (idx < 0) return;
    const newIdx = idx + dir;
    if (newIdx < 0 || newIdx >= order.length) return;
    [order[idx], order[newIdx]] = [order[newIdx], order[idx]];
    renderGroup(letter);
    updateThirdsForGroup(letter);
}

function renderGroup(letter) {
    const container = document.getElementById('gt_' + letter);
    if (!container) return;
    const order = groupOrder[letter];
    const rankCls = ['rank-1','rank-2','rank-3','rank-4'];
    const badges = [
        '<span class="advance-badge advance-1">1º</span>',
        '<span class="advance-badge advance-2">2º</span>',
        '<span class="advance-badge advance-3">3º?</span>',
        ''
    ];
    container.innerHTML = '';
    order.forEach((tid, idx) => {
        const t = getTeamById(tid);
        if (!t) return;
        const rank = idx + 1;
        const div = document.createElement('div');
        div.className = 'team-row ' + rankCls[idx];
        div.dataset.teamId   = t.id;
        div.dataset.teamName = t.name;
        div.dataset.teamFlag = t.flag || '🏳';
        if (SUBMITTED) div.style.cursor = 'default';
        div.innerHTML = `
            <span class="team-rank">${rank}</span>
            <span class="team-flag">${t.flag||'🏳'}</span>
            <span class="team-name-sm">${escH(t.name)}</span>
            ${badges[idx]}
            ${!SUBMITTED ? `<div class="team-arrows">
                <button class="arrow-btn" onclick="moveTeam('${letter}',${t.id},-1)" ${idx===0?'disabled':''} title="Subir">▲</button>
                <button class="arrow-btn" onclick="moveTeam('${letter}',${t.id},1)"  ${idx===3?'disabled':''} title="Descer">▼</button>
            </div>` : ''}
        `;
        container.appendChild(div);
    });
}

function updateThirdsForGroup(letter) {
    const order = groupOrder[letter];
    const thirdId = order[2];
    const t = getTeamById(thirdId);
    if (!t) return;
    const flagEl = document.getElementById('t3flag_' + letter);
    const nameEl = document.getElementById('t3name_' + letter);
    if (flagEl) flagEl.textContent = t.flag || '🏳';
    if (nameEl) nameEl.textContent = t.name;
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
        if (selectedThirds.size >= 8) { alert('Selecione no máximo 8 terceiros colocados.'); return; }
        selectedThirds.add(gid);
        el.classList.add('selected');
        if (check) check.style.display = 'block';
    }
    document.getElementById('thirdsCount').textContent = selectedThirds.size;
}

// ── Bracket ──────────────────────────────────────────────────────────────────
// Rounds: r32 (16 matches), r16 (8), qf (4), sf (2), final (1)
const ROUND_NAMES = {r32:'16 avos',r16:'Oitavas',qf:'Quartas',sf:'Semifinal',final:'Final'};
const ROUND_ORDER = ['r32','r16','qf','sf','final'];

function getGroupQualifiers() {
    const firsts = {}, seconds = {}, thirds = {};
    GROUP_KEYS.forEach(letter => {
        const order = groupOrder[letter];
        firsts[letter]  = getTeamById(order[0]);
        seconds[letter] = getTeamById(order[1]);
        thirds[letter]  = getTeamById(order[2]);
    });
    return { firsts, seconds, thirds };
}

// Copa 2026 R32 seeding (simplified)
function buildR32(firsts, seconds) {
    return [
        [firsts['A'], seconds['B']],
        [firsts['C'], seconds['D']],
        [firsts['E'], seconds['F']],
        [firsts['G'], seconds['H']],
        [firsts['I'], seconds['J']],
        [firsts['K'], seconds['L']],
        [firsts['B'], seconds['A']],
        [firsts['D'], seconds['C']],
        [firsts['F'], seconds['E']],
        [firsts['H'], seconds['G']],
        [firsts['J'], seconds['I']],
        [firsts['L'], seconds['K']],
        // 4 slots for best 3rd place — filled later
        [null, null], [null, null], [null, null], [null, null],
    ];
}

let bracketMatchups = { r32: [], r16: [], qf: [], sf: [], final: [] };

function buildBracket() {
    const { firsts, seconds } = getGroupQualifiers();
    bracketMatchups.r32 = buildR32(firsts, seconds);
    // Build subsequent rounds from saved state or empty
    ['r16','qf','sf','final'].forEach(round => {
        const size = {r16:8,qf:4,sf:2,final:1}[round];
        bracketMatchups[round] = Array.from({length:size}, () => [null,null]);
    });
    renderBracket();
}

function getWinner(round, idx) {
    const key = round + '_' + idx;
    return (bracketState[key] && typeof bracketState[key] === 'object')
        ? bracketState[key] : null;
}

function setWinner(round, matchIdx, team) {
    if (SUBMITTED || !team) return;
    const key = round + '_' + matchIdx;
    bracketState[key] = team;

    // Propagate to next round
    const nextRound = ROUND_ORDER[ROUND_ORDER.indexOf(round) + 1];
    if (nextRound) {
        const nextMatchIdx = Math.floor(matchIdx / 2);
        const nextSlot = matchIdx % 2;
        if (!bracketMatchups[nextRound]) return;
        if (!bracketMatchups[nextRound][nextMatchIdx]) bracketMatchups[nextRound][nextMatchIdx] = [null,null];
        bracketMatchups[nextRound][nextMatchIdx][nextSlot] = team;
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
            const [t1, t2] = match;
            const w = getWinner(round, idx);
            const div = document.createElement('div');
            div.className = 'bracket-match';
            div.innerHTML = `
                <div class="bracket-slot ${w && t1 && w.id===t1.id?'winner':''} ${!t1?'tbd':''}"
                     onclick="${t1&&!SUBMITTED?`setWinner('${round}',${idx},${JSON.stringify(t1).replace(/"/g,'&quot;')})`:''}" title="${t1?t1.name:'A definir'}">
                    <span class="bracket-slot-flag">${t1?t1.flag||'🏳':''}</span>
                    <span class="bracket-slot-name">${t1?escH(t1.name):'A definir'}</span>
                </div>
                <div class="bracket-divider"></div>
                <div class="bracket-slot ${w && t2 && w.id===t2.id?'winner':''} ${!t2?'tbd':''}"
                     onclick="${t2&&!SUBMITTED?`setWinner('${round}',${idx},${JSON.stringify(t2).replace(/"/g,'&quot;')})`:''}" title="${t2?t2.name:'A definir'}">
                    <span class="bracket-slot-flag">${t2?t2.flag||'🏳':''}</span>
                    <span class="bracket-slot-name">${t2?escH(t2.name):'A definir'}</span>
                </div>`;
            col.appendChild(div);
        });
        el.appendChild(col);
    });
}

// ── Save/Submit ───────────────────────────────────────────────────────────────
function buildPayload() {
    const groups = {};
    GROUP_KEYS.forEach(l => { groups[l] = groupOrder[l]; });
    return {
        action: 'save',
        groups,
        thirds: [...selectedThirds],
        bracket: bracketState,
        top_scorer:  document.getElementById('award_scorer')?.value  || null,
        best_player: document.getElementById('award_player')?.value  || null,
        revelation:  document.getElementById('award_revelation')?.value || null,
        champion:    document.getElementById('award_champion')?.value || null,
    };
}

async function saveDraft() {
    const btn = event.currentTarget;
    btn.disabled = true;
    btn.innerHTML = '<i class="bi bi-hourglass-split"></i>Salvando...';
    try {
        const r = await fetch('/copa26.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(buildPayload())});
        const d = await r.json();
        if (d.ok) {
            btn.innerHTML = '<i class="bi bi-check2"></i>Salvo!';
            setTimeout(() => { btn.disabled=false; btn.innerHTML='<i class="bi bi-floppy2"></i>Salvar rascunho'; }, 2000);
        } else throw new Error();
    } catch(e) { btn.disabled=false; btn.innerHTML='<i class="bi bi-floppy2"></i>Salvar rascunho'; alert('Erro ao salvar.'); }
}

async function submitPrediction() {
    if (!confirm('Após enviar não será possível editar. Confirmar envio?')) return;
    // Save first
    await fetch('/copa26.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(buildPayload())});
    // Then submit
    const r = await fetch('/copa26.php', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'submit'})});
    const d = await r.json();
    if (d.ok) window.location.reload();
    else alert('Erro ao enviar palpites.');
}

// ── Daily scores ──────────────────────────────────────────────────────────────
async function saveScores() {
    const scores = {};
    document.querySelectorAll('.score-card[data-match]').forEach(card => {
        const mid = card.dataset.match;
        const sh  = card.querySelector(`#sh_${mid}`);
        const sa  = card.querySelector(`#sa_${mid}`);
        if (sh && sa && sh.value !== '' && sa.value !== '') {
            scores[mid] = { h: +sh.value, a: +sa.value };
        }
    });
    const r = await fetch('/copa26.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'scores',scores})});
    const d = await r.json();
    if (d.ok) { const b=event.currentTarget; b.innerHTML='<i class="bi bi-check2"></i>Salvo!'; setTimeout(()=>{b.innerHTML='<i class="bi bi-check2-circle"></i>Salvar placares';},2000); }
}

async function setResult(matchId) {
    const sh = document.getElementById('sh_'+matchId)?.value;
    const sa = document.getElementById('sa_'+matchId)?.value;
    if (sh===undefined||sa===undefined) return;
    const r = await fetch('/copa26.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'set_result',match_id:matchId,home:+sh,away:+sa})});
    const d = await r.json();
    if (d.ok) window.location.reload();
}

// ── Tabs ──────────────────────────────────────────────────────────────────────
document.querySelectorAll('.copa-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.copa-tab').forEach(b=>b.classList.remove('active'));
        document.querySelectorAll('.copa-pane').forEach(p=>p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('pane-'+btn.dataset.tab)?.classList.add('active');
        if (btn.dataset.tab === 'bracket') buildBracket();
    });
});

function nextTab(tab) {
    if (!SUBMITTED) {
        // Auto-save on tab change
        fetch('/copa26.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(buildPayload())}).catch(()=>{});
    }
    document.querySelectorAll('.copa-tab').forEach(b=>{ b.classList.toggle('active', b.dataset.tab===tab); });
    document.querySelectorAll('.copa-pane').forEach(p=>{ p.classList.toggle('active', p.id==='pane-'+tab); });
    if (tab === 'bracket') buildBracket();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function escH(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── Sidebar + Theme ───────────────────────────────────────────────────────────
(function(){
    const sb=document.getElementById('sidebar'), ov=document.getElementById('sbOverlay'), btn=document.getElementById('sidebarToggle');
    if(btn) btn.addEventListener('click',()=>{sb.classList.add('open');ov.classList.add('show');});
    if(ov)  ov.addEventListener('click',()=>{sb.classList.remove('open');ov.classList.remove('show');});
})();
(function(){
    const key='fba-theme', btn=document.querySelector('[data-theme-toggle]');
    const apply=t=>{
        document.documentElement.dataset.theme=t;
        localStorage.setItem(key,t);
        if(btn) btn.innerHTML=t==='light'?'<i class="bi bi-sun"></i><span>Modo claro</span>':'<i class="bi bi-moon"></i><span>Modo escuro</span>';
    };
    apply(localStorage.getItem(key)||'dark');
    if(btn) btn.addEventListener('click',()=>apply(document.documentElement.dataset.theme==='light'?'dark':'light'));
})();

// ── Init ──────────────────────────────────────────────────────────────────────
// Render all groups with saved order
<?php if ($seeded): ?>
GROUP_KEYS.forEach(l => renderGroup(l));
<?php endif; ?>
</script>
</body>
</html>
