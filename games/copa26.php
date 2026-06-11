<?php
session_start();
require 'core/conexao.php';
if (!isset($_SESSION['user_id'])) { header("Location: auth/login.php"); exit; }

$user_id = (int)$_SESSION['user_id'];
try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN copa26_pago TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e){}
$stmt = $pdo->prepare("SELECT nome, pontos, is_admin, fba_points, COALESCE(numero_tapas,0) as numero_tapas, tapas_disponiveis, COALESCE(copa26_pago,0) as copa26_pago FROM usuarios WHERE id=?");
$stmt->execute([$user_id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);
$isAdmin = !empty($usuario['is_admin']);
$canAccess = $isAdmin || !empty($usuario['copa26_pago']);

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
        sort_order TINYINT NOT NULL DEFAULT 0,
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
        neymar_goals VARCHAR(10) NULL,
        points INT NOT NULL DEFAULT 0,
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS copa26_official (
        id INT NOT NULL DEFAULT 1 PRIMARY KEY,
        groups_json TEXT NULL,
        thirds_json TEXT NULL,
        bracket_json TEXT NULL,
        top_scorer VARCHAR(100) NULL,
        best_player VARCHAR(100) NULL,
        revelation VARCHAR(100) NULL,
        neymar_goals VARCHAR(10) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// migrate old tables if needed
foreach (['neymar_goals VARCHAR(10) NULL AFTER revelation','points INT NOT NULL DEFAULT 0 AFTER neymar_goals',
          'champion VARCHAR(100) NULL AFTER points','vice VARCHAR(100) NULL AFTER champion'] as $col) {
    try { $pdo->exec("ALTER TABLE copa26_predictions ADD COLUMN $col"); } catch(Exception $e){}
}
foreach (['champion VARCHAR(100) NULL AFTER neymar_goals','vice VARCHAR(100) NULL AFTER champion'] as $col) {
    try { $pdo->exec("ALTER TABLE copa26_official ADD COLUMN $col"); } catch(Exception $e){}
}
try { $pdo->exec("ALTER TABLE copa26_official ADD COLUMN bracket_open TINYINT(1) NOT NULL DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE copa26_official ADD COLUMN groups_open TINYINT(1) NOT NULL DEFAULT 1"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE copa26_predictions ADD COLUMN bracket_submitted_at TIMESTAMP NULL DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE copa26_teams ADD COLUMN sort_order TINYINT NOT NULL DEFAULT 0 AFTER flag"); } catch(Exception $e){}
// migrate sort_order = 0 rows to position based on name (one-time fix)
try {
    if ((int)$pdo->query("SELECT COUNT(*) FROM copa26_teams WHERE sort_order=0")->fetchColumn() > 0) {
        $pdo->exec("SET @rn:=0,@prev:=0");
        $pdo->exec("UPDATE copa26_teams t JOIN (
            SELECT id, group_id, ROW_NUMBER() OVER (PARTITION BY group_id ORDER BY id) rn FROM copa26_teams
        ) ranked ON ranked.id=t.id SET t.sort_order=ranked.rn WHERE t.sort_order=0");
    }
} catch(Exception $e){}

// migrate emoji flags → ISO codes
try {
    $flagMigrate=[
        // old teams
        '🇲🇽'=>'mx','🇯🇲'=>'jm','🇻🇪'=>'ve','🇪🇨'=>'ec','🇺🇸'=>'us','🇧🇴'=>'bo','🇵🇦'=>'pa','🇺🇾'=>'uy',
        '🇨🇦'=>'ca','🇭🇳'=>'hn','🇨🇴'=>'co','🇵🇾'=>'py','🇧🇷'=>'br','🇯🇵'=>'jp','🇨🇿'=>'cz','🇨🇩'=>'cd',
        '🇪🇸'=>'es','🇭🇷'=>'hr','🇲🇦'=>'ma','🇺🇿'=>'uz','🇦🇷'=>'ar','🇨🇱'=>'cl','🇹🇷'=>'tr','🇦🇴'=>'ao',
        '🇫🇷'=>'fr','🇧🇪'=>'be','🇩🇿'=>'dz','🇳🇿'=>'nz','🇵🇹'=>'pt','🇵🇱'=>'pl','🇸🇳'=>'sn','🇬🇹'=>'gt',
        '🇳🇱'=>'nl','🇵🇪'=>'pe','🇭🇹'=>'ht','🇸🇦'=>'sa','🇩🇪'=>'de','🇷🇸'=>'rs','🇨🇷'=>'cr','🇨🇲'=>'cm',
        '🇮🇹'=>'it','🇶🇦'=>'qa','🇳🇮'=>'ni','🇦🇺'=>'au','🏴󠁧󠁢󠁥󠁮󠁧󠁿'=>'gb-eng','🇮🇷'=>'ir','🇸🇰'=>'sk','🇹🇭'=>'th',
        // new teams
        '🇿🇦'=>'za','🇰🇷'=>'kr','🇧🇦'=>'ba','🇨🇭'=>'ch','🏴󠁧󠁢󠁳󠁣󠁴󠁿'=>'gb-sct',
        '🇨🇼'=>'cw','🇨🇮'=>'ci','🇸🇪'=>'se','🇹🇳'=>'tn','🇪🇬'=>'eg','🇨🇻'=>'cv',
        '🇮🇶'=>'iq','🇳🇴'=>'no','🇦🇹'=>'at','🇯🇴'=>'jo','🇬🇭'=>'gh',
    ];
    $fmig=$pdo->prepare("UPDATE copa26_teams SET flag=? WHERE flag=?");
    foreach ($flagMigrate as $emoji=>$code) $fmig->execute([$code,$emoji]);
} catch(Exception $e){}

// helper
function flagImg(string $code, int $w=20): string {
    $h = round($w * .75);
    if (!$code) return '<span style="display:inline-block;width:'.$w.'px;height:'.$h.'px;background:rgba(255,255,255,.08);border-radius:2px;vertical-align:middle"></span>';
    return '<span class="fi fi-'.htmlspecialchars($code).'" style="width:'.$w.'px;height:'.$h.'px;border-radius:2px;vertical-align:middle;display:inline-block;background-size:cover;flex-shrink:0"></span>';
}

// ── Seed ─────────────────────────────────────────────────────────────────────
function seedCopa26(PDO $pdo): void {
    $pdo->exec("DELETE FROM copa26_teams");
    $pdo->exec("DELETE FROM copa26_groups");
    $data = [
        'A'=>[['México','mx'],['África do Sul','za'],['Coreia do Sul','kr'],['Tchéquia','cz']],
        'B'=>[['Canadá','ca'],['Bósnia','ba'],['Catar','qa'],['Suíça','ch']],
        'C'=>[['Brasil','br'],['Marrocos','ma'],['Haiti','ht'],['Escócia','gb-sct']],
        'D'=>[['EUA','us'],['Paraguai','py'],['Austrália','au'],['Turquia','tr']],
        'E'=>[['Alemanha','de'],['Curaçau','cw'],['Costa do Marfim','ci'],['Equador','ec']],
        'F'=>[['Holanda','nl'],['Japão','jp'],['Suécia','se'],['Tunísia','tn']],
        'G'=>[['Bélgica','be'],['Egito','eg'],['Irã','ir'],['Nova Zelândia','nz']],
        'H'=>[['Espanha','es'],['Cabo Verde','cv'],['Arábia Saudita','sa'],['Uruguai','uy']],
        'I'=>[['França','fr'],['Senegal','sn'],['Iraque','iq'],['Noruega','no']],
        'J'=>[['Argentina','ar'],['Argélia','dz'],['Áustria','at'],['Jordânia','jo']],
        'K'=>[['Portugal','pt'],['RD do Congo','cd'],['Uzbequistão','uz'],['Colômbia','co']],
        'L'=>[['Inglaterra','gb-eng'],['Croácia','hr'],['Gana','gh'],['Panamá','pa']],
    ];
    $sg=$pdo->prepare("INSERT INTO copa26_groups (letter) VALUES (?)");
    $st=$pdo->prepare("INSERT INTO copa26_teams (group_id,name,flag,sort_order) VALUES (?,?,?,?)");
    foreach ($data as $letter=>$teams) {
        $sg->execute([$letter]); $gid=$pdo->lastInsertId();
        foreach ($teams as $i=>[$n,$f]) $st->execute([$gid,$n,$f,$i+1]);
    }
}

// ── Point calculation ─────────────────────────────────────────────────────────
function calcAllPoints(PDO $pdo, array $off): void {
    $og  = json_decode($off['groups_json']  ?? '[]', true) ?: [];
    $ot  = json_decode($off['thirds_json']  ?? '[]', true) ?: [];
    $ob  = json_decode($off['bracket_json'] ?? '{}', true) ?: [];
    $rows = $pdo->query("SELECT * FROM copa26_predictions WHERE submitted_at IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    $upd  = $pdo->prepare("UPDATE copa26_predictions SET points=? WHERE user_id=?");
    foreach ($rows as $p) {
        $pts = 0;
        $ug = json_decode($p['groups_json'] ?? '[]', true) ?: [];
        foreach ($og as $l => $offOrder) {
            $uo = $ug[$l] ?? [];
            if (isset($offOrder[0],$uo[0]) && $offOrder[0]==$uo[0]) $pts++;
            if (isset($offOrder[1],$uo[1]) && $offOrder[1]==$uo[1]) $pts++;
            if (isset($offOrder[2],$uo[2]) && $offOrder[2]==$uo[2]) $pts++;
        }
        $ut = json_decode($p['thirds_json'] ?? '[]', true) ?: [];
        // 1pt por cada terceiro que avançou corretamente + 1pt bônus se posição exata
        foreach ($ot as $pos => $gid) {
            if (in_array($gid, $ut)) {
                $pts++;
                if (isset($ut[$pos]) && $ut[$pos] == $gid) $pts++;
            }
        }
        $ub = json_decode($p['bracket_json'] ?? '{}', true) ?: [];
        foreach ($ob as $key=>$ot2) {
            if (!$ot2||!is_array($ot2)) continue;
            $ut2=$ub[$key]??null;
            if ($ut2&&is_array($ut2)&&($ut2['id']??null)==($ot2['id']??null)) $pts++;
        }
        // prizes 3pts each
        $cmp = fn($a,$b)=>trim(mb_strtolower($a??''))===trim(mb_strtolower($b??''))&&trim($a??'')!=='';
        if ($cmp($off['champion'],    $p['champion']))    $pts+=5;
        if ($cmp($off['vice'],        $p['vice']))        $pts+=3;
        if ($cmp($off['top_scorer'],  $p['top_scorer']))  $pts+=3;
        if ($cmp($off['best_player'], $p['best_player'])) $pts+=3;
        if ($cmp($off['revelation'],  $p['revelation']))  $pts+=3;
        if (trim($off['neymar_goals']??'')!==''&&trim($p['neymar_goals']??'')==trim($off['neymar_goals'])) $pts+=3;
        $upd->execute([$pts,$p['user_id']]);
    }
}

// ── Fixture seed ─────────────────────────────────────────────────────────────
// [date, time, group_letter, home_pos(0-based), away_pos(0-based)]
// Positions follow the seed order: 0=1st team, 1=2nd, 2=3rd, 3=4th
const COPA26_SCHEDULE = [
    // ── Matchday 1 ──────────────────────────────
    ['2026-06-11','16:00','A',0,1],['2026-06-11','23:00','A',2,3],
    ['2026-06-12','16:00','B',0,1],['2026-06-12','22:00','D',0,1],
    ['2026-06-13','01:00','D',2,3],['2026-06-13','16:00','B',2,3],
    ['2026-06-13','19:00','C',0,1],['2026-06-13','22:00','C',2,3],
    ['2026-06-14','14:00','E',0,1],['2026-06-14','17:00','F',0,1],
    ['2026-06-14','20:00','E',2,3],['2026-06-14','23:00','F',2,3],
    ['2026-06-15','13:00','H',0,1],['2026-06-15','16:00','G',0,1],
    ['2026-06-15','19:00','H',2,3],['2026-06-15','22:00','G',2,3],
    ['2026-06-16','16:00','I',0,1],['2026-06-16','19:00','I',2,3],
    ['2026-06-16','22:00','J',0,1],
    ['2026-06-17','01:00','J',2,3],['2026-06-17','14:00','K',0,1],
    ['2026-06-17','17:00','L',0,1],['2026-06-17','20:00','L',2,3],
    ['2026-06-17','23:00','K',2,3],
    // ── Matchday 2 ──────────────────────────────
    ['2026-06-18','13:00','A',3,1],['2026-06-18','16:00','B',3,1],
    ['2026-06-18','19:00','B',0,2],['2026-06-18','22:00','A',0,2],
    ['2026-06-19','01:00','D',3,1],['2026-06-19','16:00','D',0,2],
    ['2026-06-19','19:00','C',3,1],['2026-06-19','22:00','C',0,2],
    ['2026-06-20','14:00','F',0,2],['2026-06-20','17:00','E',0,2],
    ['2026-06-20','21:00','E',3,1],
    ['2026-06-21','01:00','F',3,1],['2026-06-21','13:00','H',0,2],
    ['2026-06-21','16:00','G',0,2],['2026-06-21','19:00','H',3,1],
    ['2026-06-21','22:00','G',3,1],
    ['2026-06-22','14:00','J',0,2],['2026-06-22','18:00','I',0,2],
    ['2026-06-22','21:00','I',3,1],
    ['2026-06-23','00:00','J',3,1],['2026-06-23','14:00','K',0,2],
    ['2026-06-23','17:00','L',0,2],['2026-06-23','20:00','L',3,1],
    ['2026-06-23','23:00','K',3,1],
    // ── Matchday 3 (simultaneous final rounds) ──
    ['2026-06-24','16:00','B',3,0],['2026-06-24','16:00','B',1,2],
    ['2026-06-24','19:00','C',3,0],['2026-06-24','19:00','C',1,2],
    ['2026-06-24','22:00','A',3,0],['2026-06-24','22:00','A',1,2],
    ['2026-06-25','17:00','E',3,0],['2026-06-25','17:00','E',1,2],
    ['2026-06-25','20:00','F',3,0],['2026-06-25','20:00','F',1,2],
    ['2026-06-25','23:00','D',3,0],['2026-06-25','23:00','D',1,2],
    ['2026-06-26','16:00','I',3,0],['2026-06-26','16:00','I',1,2],
    ['2026-06-26','21:00','H',3,0],['2026-06-26','21:00','H',1,2],
    ['2026-06-27','00:00','G',1,2],['2026-06-27','00:00','G',3,0],
    ['2026-06-27','18:00','L',3,0],['2026-06-27','18:00','L',1,2],
    ['2026-06-27','20:30','K',3,0],['2026-06-27','20:30','K',1,2],
    ['2026-06-27','23:00','J',3,0],['2026-06-27','23:00','J',1,2],
];

function seedCopa26Fixtures(PDO $pdo): int {
    $pdo->exec("DELETE FROM copa26_matches WHERE phase='group'");
    $rows=$pdo->query("SELECT t.id,g.letter,g.id gid FROM copa26_teams t JOIN copa26_groups g ON g.id=t.group_id ORDER BY g.letter,t.sort_order,t.id")->fetchAll(PDO::FETCH_ASSOC);
    $byGroup=[]; $gids=[];
    foreach ($rows as $r){ $byGroup[$r['letter']][]=$r['id']; $gids[$r['letter']]=$r['gid']; }
    $st=$pdo->prepare("INSERT INTO copa26_matches (match_date,match_time,home_team_id,away_team_id,phase,group_id) VALUES (?,?,?,?,?,?)");
    $cnt=0;
    foreach (COPA26_SCHEDULE as [$date,$time,$letter,$hi,$ai]){
        $home=$byGroup[$letter][$hi]??null; $away=$byGroup[$letter][$ai]??null;
        if (!$home||!$away) continue;
        $st->execute([$date,$time,$home,$away,'group',$gids[$letter]??null]); $cnt++;
    }
    return $cnt;
}

// ── Early fetch needed by POST guard ─────────────────────────────────────────
$_pEarly=null; try{$s=$pdo->prepare("SELECT submitted_at FROM copa26_predictions WHERE user_id=?");$s->execute([$user_id]);$_pEarly=$s->fetch(PDO::FETCH_ASSOC)?:null;}catch(Exception $e){}
$_oEarly=null; try{$_oEarly=$pdo->query("SELECT groups_open FROM copa26_official WHERE id=1")->fetch(PDO::FETCH_ASSOC)?:null;}catch(Exception $e){}
$submitted  = !empty($_pEarly['submitted_at']);
$groupsOpen = (int)($_oEarly['groups_open'] ?? 1);

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($isAdmin && isset($_GET['action']) && $_GET['action']==='seed') {
    seedCopa26($pdo); header('Location: copa26.php'); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'),true)??[];
    $act  = $body['action']??'';

    if (($act==='save'||$act==='submit')&&!$groupsOpen&&!$submitted) {
        echo json_encode(['ok'=>false,'msg'=>'Fase de grupos encerrada.']); exit;
    }
    if ($act==='save') {
        $pdo->prepare("INSERT INTO copa26_predictions (user_id,groups_json,thirds_json,bracket_json,top_scorer,best_player,revelation,neymar_goals,champion,vice)
            VALUES (?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE groups_json=VALUES(groups_json),thirds_json=VALUES(thirds_json),bracket_json=VALUES(bracket_json),
            top_scorer=VALUES(top_scorer),best_player=VALUES(best_player),revelation=VALUES(revelation),neymar_goals=VALUES(neymar_goals),
            champion=VALUES(champion),vice=VALUES(vice)")
        ->execute([$user_id,json_encode($body['groups']??[]),json_encode($body['thirds']??[]),
            json_encode($body['bracket']??[]),$body['top_scorer']??null,$body['best_player']??null,
            $body['revelation']??null,$body['neymar_goals']??null,$body['champion']??null,$body['vice']??null]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act==='submit') {
        $pdo->prepare("UPDATE copa26_predictions SET submitted_at=NOW() WHERE user_id=? AND submitted_at IS NULL")->execute([$user_id]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act==='scores') {
        $st=$pdo->prepare("INSERT INTO copa26_score_preds (user_id,match_id,score_home,score_away) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE score_home=VALUES(score_home),score_away=VALUES(score_away)");
        $nowTs=time();
        foreach ($body['scores']??[] as $mid=>$s) {
            $mrow=$pdo->prepare("SELECT match_date,match_time FROM copa26_matches WHERE id=?");
            $mrow->execute([(int)$mid]);
            $mr=$mrow->fetch(PDO::FETCH_ASSOC);
            if(!$mr) continue;
            $matchTs=strtotime($mr['match_date'].' '.($mr['match_time']??'23:59'));
            if($nowTs>=$matchTs) continue; // jogo já começou, ignora
            $st->execute([$user_id,(int)$mid,(int)($s['h']??0),(int)($s['a']??0)]);
        }
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act==='set_result'&&$isAdmin) {
        $pdo->prepare("UPDATE copa26_matches SET score_home=?,score_away=? WHERE id=?")
            ->execute([(int)($body['home']??0),(int)($body['away']??0),(int)($body['match_id']??0)]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act==='add_match'&&$isAdmin) {
        $pdo->prepare("INSERT INTO copa26_matches (match_date,match_time,home_team_id,away_team_id,home_name,away_name,phase) VALUES (?,?,?,?,?,?,?)")
            ->execute([$body['date'],$body['time']??null,$body['home_id']??null,$body['away_id']??null,
                $body['home_name']??null,$body['away_name']??null,$body['phase']??'group']);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act==='del_match'&&$isAdmin) {
        $pdo->prepare("DELETE FROM copa26_matches WHERE id=?")->execute([(int)($body['match_id']??0)]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act==='update_match'&&$isAdmin) {
        $sh=$body['score_home']!==''&&$body['score_home']!==null?(int)$body['score_home']:null;
        $sa=$body['score_away']!==''&&$body['score_away']!==null?(int)$body['score_away']:null;
        $pdo->prepare("UPDATE copa26_matches SET match_date=?,match_time=?,home_team_id=?,away_team_id=?,score_home=?,score_away=? WHERE id=?")
            ->execute([$body['date']??null,$body['time']??null,(int)($body['home_id']??0),(int)($body['away_id']??0),$sh,$sa,(int)($body['match_id']??0)]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act==='seed_fixtures'&&$isAdmin) {
        $cnt=seedCopa26Fixtures($pdo);
        echo json_encode(['ok'=>true,'inserted'=>$cnt]); exit;
    }
    if ($act==='del_all_matches'&&$isAdmin) {
        $pdo->exec("DELETE FROM copa26_matches");
        $pdo->exec("DELETE FROM copa26_score_preds");
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act==='csv_import'&&$isAdmin) {
        $rows2=$body['rows']??[];
        $tmap=[];
        foreach ($pdo->query("SELECT t.id,LOWER(t.name) nm FROM copa26_teams t")->fetchAll(PDO::FETCH_ASSOC) as $r) $tmap[$r['nm']]=$r['id'];
        $st=$pdo->prepare("INSERT INTO copa26_matches (match_date,match_time,home_team_id,away_team_id,phase) VALUES (?,?,?,?,?)");
        $ok=0;$fail=0;
        foreach ($rows2 as $r){
            $hid=$tmap[strtolower(trim($r['home']??''))]??null;
            $aid=$tmap[strtolower(trim($r['away']??''))]??null;
            if (!$hid||!$aid||!($r['date']??'')){ $fail++; continue; }
            $st->execute([$r['date'],$r['time']??null,$hid,$aid,'group']); $ok++;
        }
        echo json_encode(['ok'=>true,'inserted'=>$ok,'failed'=>$fail]); exit;
    }
    if ($act==='save_official'&&$isAdmin) {
        $pdo->prepare("INSERT INTO copa26_official (id,groups_json,thirds_json,bracket_json,top_scorer,best_player,revelation,neymar_goals,champion,vice,updated_by)
            VALUES (1,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE groups_json=VALUES(groups_json),thirds_json=VALUES(thirds_json),bracket_json=VALUES(bracket_json),
            top_scorer=VALUES(top_scorer),best_player=VALUES(best_player),revelation=VALUES(revelation),neymar_goals=VALUES(neymar_goals),
            champion=VALUES(champion),vice=VALUES(vice),updated_by=VALUES(updated_by)")
        ->execute([json_encode($body['groups']??[]),json_encode($body['thirds']??[]),
            json_encode($body['bracket']??[]),$body['top_scorer']??null,$body['best_player']??null,
            $body['revelation']??null,$body['neymar_goals']??null,$body['champion']??null,$body['vice']??null,$user_id]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act==='calc_points'&&$isAdmin) {
        $off=$pdo->query("SELECT * FROM copa26_official WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        if ($off) { calcAllPoints($pdo,$off); echo json_encode(['ok'=>true,'msg'=>'Pontos calculados!']); }
        else echo json_encode(['ok'=>false,'msg'=>'Configure o resultado oficial primeiro.']);
        exit;
    }
    if ($act==='toggle_bracket_open'&&$isAdmin) {
        $val=(int)($body['open']??0);
        $pdo->prepare("INSERT INTO copa26_official (id,bracket_open) VALUES (1,?) ON DUPLICATE KEY UPDATE bracket_open=?")->execute([$val,$val]);
        echo json_encode(['ok'=>true,'open'=>$val]); exit;
    }
    if ($act==='toggle_groups_open'&&$isAdmin) {
        $val=(int)($body['open']??0);
        $pdo->prepare("INSERT INTO copa26_official (id,groups_open) VALUES (1,?) ON DUPLICATE KEY UPDATE groups_open=?")->execute([$val,$val]);
        echo json_encode(['ok'=>true,'open'=>$val]); exit;
    }
    if ($act==='reset_brackets'&&$isAdmin) {
        $pdo->exec("UPDATE copa26_predictions SET bracket_json=NULL, bracket_submitted_at=NULL");
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act==='save_bracket') {
        $pdo->prepare("INSERT INTO copa26_predictions (user_id,bracket_json) VALUES (?,?) ON DUPLICATE KEY UPDATE bracket_json=VALUES(bracket_json)")
            ->execute([$user_id, json_encode($body['bracket']??[])]);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($act==='submit_bracket') {
        $pdo->prepare("UPDATE copa26_predictions SET bracket_submitted_at=NOW() WHERE user_id=? AND bracket_submitted_at IS NULL")->execute([$user_id]);
        echo json_encode(['ok'=>true]); exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

// ── Load data ─────────────────────────────────────────────────────────────────
$groups=[];
try {
    $rows=$pdo->query("SELECT g.id gid,g.letter,t.id tid,t.name,t.flag FROM copa26_groups g LEFT JOIN copa26_teams t ON t.group_id=g.id ORDER BY g.letter,t.sort_order,t.id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $l=$r['letter'];
        if (!isset($groups[$l])) $groups[$l]=['id'=>$r['gid'],'teams'=>[]];
        if ($r['tid']) $groups[$l]['teams'][]=['id'=>$r['tid'],'name'=>$r['name'],'flag'=>$r['flag']??''];
    }
} catch(Exception $e){}

$allTeams=[];
foreach ($groups as $g) foreach ($g['teams'] as $t) $allTeams[$t['id']]=$t;

$pred=null;
try { $s=$pdo->prepare("SELECT * FROM copa26_predictions WHERE user_id=?"); $s->execute([$user_id]); $pred=$s->fetch(PDO::FETCH_ASSOC)?:null; } catch(Exception $e){}

$submitted    = !empty($pred['submitted_at']);
$predGroups   = $pred?(json_decode($pred['groups_json']  ??'[]',true)?:[]):[];
$predThirds   = $pred?(json_decode($pred['thirds_json']  ??'[]',true)?:[]):[];
$predBracket  = $pred?(json_decode($pred['bracket_json'] ??'{}',true)?:[]):[];

$official=null; $hasOfficial=false;
try { $official=$pdo->query("SELECT * FROM copa26_official WHERE id=1")->fetch(PDO::FETCH_ASSOC)?:null; $hasOfficial=$official&&($official['groups_json']||$official['bracket_json']); } catch(Exception $e){}
$bracketOpen  = (int)($official['bracket_open']  ?? 0);
$groupsOpen   = (int)($official['groups_open']   ?? 1); // 1 = aberto por padrão
$bracketSubmitted = !empty($pred['bracket_submitted_at']);

$offGroups  = $official?(json_decode($official['groups_json']  ??'[]',true)?:[]):[];
$offThirds  = $official?(json_decode($official['thirds_json']  ??'[]',true)?:[]):[];
$offBracket = $official?(json_decode($official['bracket_json'] ??'{}',true)?:[]):[];

$ranking=[];
try {
    $ranking=$pdo->query("SELECT u.nome,p.points,p.champion,p.submitted_at FROM copa26_predictions p JOIN usuarios u ON u.id=p.user_id WHERE p.submitted_at IS NOT NULL AND u.copa26_pago=1 ORDER BY p.points DESC,p.submitted_at ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

// all matches (for Jogos tab)
$allMatches=[];
try {
    $allMatches=$pdo->query("SELECT m.*,ht.name hname,ht.flag hflag,at.name aname,at.flag aflag FROM copa26_matches m LEFT JOIN copa26_teams ht ON ht.id=m.home_team_id LEFT JOIN copa26_teams at ON at.id=m.away_team_id ORDER BY m.match_date,m.match_time")->fetchAll(PDO::FETCH_ASSOC);
    // auto-seed group matches on first load if groups exist but no matches yet
    if (empty($allMatches) && !empty($groups)) {
        seedCopa26Fixtures($pdo);
        $allMatches=$pdo->query("SELECT m.*,ht.name hname,ht.flag hflag,at.name aname,at.flag aflag FROM copa26_matches m LEFT JOIN copa26_teams ht ON ht.id=m.home_team_id LEFT JOIN copa26_teams at ON at.id=m.away_team_id ORDER BY m.match_date,m.match_time")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch(Exception $e){}

$matchesByDate=[]; foreach ($allMatches as $m) $matchesByDate[$m['match_date']][]=$m;
$allScores=[];
if ($allMatches) {
    try {
        $ids=array_column($allMatches,'id'); $ph=implode(',',array_fill(0,count($ids),'?'));
        $s=$pdo->prepare("SELECT * FROM copa26_score_preds WHERE user_id=? AND match_id IN ($ph)");
        $s->execute(array_merge([$user_id],$ids));
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $allScores[$r['match_id']]=$r;
    } catch(Exception $e){}
}

$seeded=!empty($groups);
$nameInitial=mb_strtoupper(mb_substr($usuario['nome']??'U',0,1));
$today=date('Y-m-d');

// Jogos de hoje sem palpite preenchido e que ainda não começaram
$nowTs = time();
$pendingToday = array_values(array_filter(
    $matchesByDate[$today] ?? [],
    function($m) use ($today, $nowTs, $allScores) {
        if ($m['score_home'] !== null) return false;
        if (isset($allScores[$m['id']])) return false;
        $matchTs = strtotime($today . ' ' . ($m['match_time'] ?? '23:59'));
        return $nowTs < $matchTs;
    }
));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#fc0025">
<title>Bolão Copa 2026 · FBA Games</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/flag-icons@7.2.3/css/flag-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--red-soft:rgba(252,0,37,.10);--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;--border:rgba(255,255,255,.06);--border-md:rgba(255,255,255,.10);--border-red:rgba(252,0,37,.22);--text:#f0f0f3;--text-2:#868690;--text-3:#48484f;--green:#22c55e;--amber:#f59e0b;--blue:#3b82f6;--gold:#f59e0b;--purple:#a78bfa;--font:'Poppins',sans-serif;--radius:14px;--radius-sm:10px;--t:200ms;--ease:cubic-bezier(.2,.8,.2,1);--sw:240px}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{background:var(--bg);color:var(--text);font-family:var(--font);-webkit-font-smoothing:antialiased}
/* sidebar */
.sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sw);background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:200;overflow-y:auto;scrollbar-width:none}
.sidebar::-webkit-scrollbar{display:none}
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
.sb-link i{width:16px;text-align:center;font-size:13px}
.sb-link:hover{background:var(--panel-2);color:var(--text);border-left-color:var(--border-md)}
.sb-link.active{background:var(--red-soft);color:var(--red);border-left-color:var(--red);font-weight:700}
.sb-footer{padding:12px 14px;border-top:1px solid var(--border);margin-top:auto}
.sb-logout{display:flex;align-items:center;gap:8px;width:100%;padding:8px 12px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text-2);text-decoration:none;font-size:12px;font-weight:600;transition:all var(--t) var(--ease)}
.sb-logout:hover{background:var(--red-soft);border-color:rgba(252,0,37,.2);color:var(--red)}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:199}.sb-overlay.open{display:block}
/* layout */
.page{margin-left:var(--sw);min-height:100vh;display:flex;flex-direction:column}
.mob-bar{display:none;height:52px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 14px;gap:10px;position:sticky;top:0;z-index:100}
.mob-ham{background:none;border:1px solid var(--border);border-radius:8px;color:var(--text);width:34px;height:34px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px}
.mob-title{font-size:14px;font-weight:800;color:var(--text);flex:1}.mob-title span{color:var(--red)}
.main{flex:1;padding:20px 24px 60px;max-width:1100px;margin:0 auto;width:100%}
/* buttons */
.btn-r{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:500;border:none;cursor:pointer;transition:opacity var(--t);font-family:var(--font);text-decoration:none}
.btn-r:hover{opacity:.85}.btn-r.primary{background:var(--red);color:#fff}.btn-r.secondary{background:var(--panel-3);color:var(--text-2);border:1px solid var(--border)}
.btn-r.outline{background:transparent;color:var(--red);border:1px solid rgba(252,0,37,.3)}.btn-r.gold{background:rgba(245,158,11,.15);color:var(--gold);border:1px solid rgba(245,158,11,.3)}
.btn-r.purple{background:rgba(139,92,246,.15);color:var(--purple);border:1px solid rgba(139,92,246,.3)}.btn-r.green-btn{background:rgba(34,197,94,.15);color:var(--green);border:1px solid rgba(34,197,94,.3)}
.btn-r:disabled{opacity:.35;cursor:not-allowed}.btn-r.sm{padding:5px 12px;font-size:12px}.btn-r.lg{padding:11px 24px;font-size:14px;font-weight:700}
.tag{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600}
.tag.gray{background:var(--panel-3);color:var(--text-2)}.tag.green{background:rgba(34,197,94,.12);color:#22c55e}
/* copa hero */
.copa-hero{background:linear-gradient(135deg,#0a1628 0%,#1a0510 100%);border:1px solid rgba(252,0,37,.22);border-radius:var(--radius);padding:18px 22px;margin-bottom:20px;position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.copa-hero::before{content:'⚽';position:absolute;right:18px;top:50%;transform:translateY(-50%);font-size:72px;opacity:.05;pointer-events:none}
.copa-hero-title{font-size:22px;font-weight:800;color:#fff}.copa-hero-title span{color:var(--red)}
.copa-hero-sub{font-size:12px;color:rgba(255,255,255,.4);margin-top:2px}
/* accordion */
.accordion{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:20px;overflow:hidden}
.accordion-header{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;cursor:pointer;user-select:none;transition:background var(--t)}
.accordion-header:hover{background:var(--panel-2)}
.accordion-title{font-size:14px;font-weight:600;color:var(--text)}
.accordion-pts{font-size:13px;font-weight:700;color:var(--gold);margin-left:10px}
.accordion-chevron{font-size:16px;color:var(--text-3);transition:transform var(--t)}.accordion-chevron.open{transform:rotate(180deg)}
.accordion-body{display:none;border-top:1px solid var(--border)}.accordion-body.open{display:block}
.pred-summary{padding:16px 18px}
.pred-section{margin-bottom:16px}.pred-section:last-child{margin-bottom:0}
.pred-section-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);margin-bottom:9px}
.pred-groups-mini{display:grid;grid-template-columns:repeat(4,1fr);gap:7px}
.pred-group-mini{background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:8px 10px}
.pred-group-mini-letter{font-size:10px;font-weight:700;color:var(--red);margin-bottom:4px}
.pred-team-mini{display:flex;align-items:center;gap:4px;font-size:11px;color:var(--text-2);margin-bottom:2px}
.pred-team-mini.first{color:var(--gold);font-weight:600}.pred-team-mini.second{color:var(--blue);font-weight:600}
.pred-thirds-mini{display:flex;flex-wrap:wrap;gap:5px}
.pred-third-pill{background:var(--panel-2);border:1px solid var(--border);border-radius:6px;padding:3px 8px;font-size:11px;color:var(--text-2)}
.pred-awards{display:grid;grid-template-columns:repeat(2,1fr);gap:7px}
.pred-award{background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:9px 12px;display:flex;align-items:center;gap:8px}
.pred-award-icon{font-size:18px}.pred-award-name{font-size:12px;font-weight:600;color:var(--text)}.pred-award-label{font-size:10px;color:var(--text-3)}
/* tabs */
.copa-tabs{display:flex;border-bottom:1px solid var(--border);margin-bottom:20px;overflow-x:auto;scrollbar-width:none}
.copa-tabs::-webkit-scrollbar{display:none}
.copa-tab{padding:10px 16px;font-size:13px;font-weight:500;color:var(--text-2);border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap;font-family:var(--font);transition:color var(--t)}
.copa-tab:hover{color:var(--text)}.copa-tab.active{color:var(--red);border-bottom-color:var(--red);font-weight:600}
.copa-tab.admin-tab{color:var(--purple)}.copa-tab.admin-tab.active{color:var(--purple);border-bottom-color:var(--purple)}
.copa-pane{display:none}.copa-pane.active{display:block}
/* groups */
.groups-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.group-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden}
.group-card-header{padding:8px 12px;background:var(--panel-2);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.group-letter{font-size:12px;font-weight:700;color:var(--red)}.group-label{font-size:10px;color:var(--text-3);text-transform:uppercase}
.group-col-header{display:flex;align-items:center;padding:4px 10px;border-bottom:1px solid var(--border);background:var(--panel-2)}
.group-col-sel{font-size:9px;font-weight:600;color:var(--text-3);text-transform:uppercase;flex:1;letter-spacing:.4px}
.group-col-pos{display:flex;gap:5px;font-size:9px;font-weight:600;color:var(--text-3)}
.group-col-pos span{width:22px;text-align:center}
.group-teams{padding:0}
.team-row{display:flex;align-items:center;gap:8px;padding:7px 10px;border:none;border-radius:0;margin:0;transition:background .12s;user-select:none;border-bottom:1px solid rgba(0,0,0,.12)}
.team-row:last-child{border-bottom:none}
.team-row.rank-1{background:rgba(109,40,217,.78)}
.team-row.rank-2{background:rgba(161,107,13,.72)}
.team-row.rank-3.qthird{background:rgba(190,24,93,.62)}
.team-rank{display:none}
.team-flag{width:20px;height:15px;flex-shrink:0;display:flex;align-items:center}.team-flag img{width:20px;height:auto;border-radius:2px;display:block}
.team-name-sm{font-size:11px;font-weight:500;color:var(--text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.team-row.rank-1 .team-name-sm,.team-row.rank-2 .team-name-sm,.team-row.rank-3.qthird .team-name-sm{color:#fff}
.advance-badge{font-size:9px;font-weight:700;padding:1px 5px;border-radius:4px;flex-shrink:0}
.advance-1{background:rgba(255,255,255,.22);color:#fff}.advance-2{background:rgba(255,255,255,.22);color:#fff}
/* pos radio buttons */
.pos-btns{display:flex;gap:5px;flex-shrink:0;margin-left:auto}
.pos-btn{width:22px;height:22px;border-radius:50%;border:2px solid var(--border-md);background:transparent;padding:0;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:0;transition:border-color .12s;position:relative}
.team-row.rank-1 .pos-btn,.team-row.rank-2 .pos-btn,.team-row.rank-3.qthird .pos-btn{border-color:rgba(255,255,255,.35)}
.team-row.rank-1 .pos-btn:hover,.team-row.rank-2 .pos-btn:hover,.team-row.rank-3.qthird .pos-btn:hover{border-color:rgba(255,255,255,.7)}
.pos-btn.on{border-color:rgba(255,255,255,.95)}
.pos-btn.on::after{content:'';width:10px;height:10px;border-radius:50%;background:rgba(255,255,255,.95);position:absolute;top:50%;left:50%;transform:translate(-50%,-50%)}
/* neutral rows colored buttons */
.team-row.rank-3:not(.qthird) .pos-btn,.team-row.rank-4 .pos-btn{border-color:var(--border)}
.team-row.rank-3:not(.qthird) .pos-btn:hover,.team-row.rank-4 .pos-btn:hover{border-color:var(--text-3)}
.team-row.rank-3:not(.qthird) .pos-btn.p1.on,.team-row.rank-4 .pos-btn.p1.on{border-color:rgba(109,40,217,.85)}
.team-row.rank-3:not(.qthird) .pos-btn.p1.on::after,.team-row.rank-4 .pos-btn.p1.on::after{background:#7c3aed}
.team-row.rank-3:not(.qthird) .pos-btn.p2.on,.team-row.rank-4 .pos-btn.p2.on{border-color:rgba(161,107,13,.85)}
.team-row.rank-3:not(.qthird) .pos-btn.p2.on::after,.team-row.rank-4 .pos-btn.p2.on::after{background:#b45309}
.team-row.rank-3:not(.qthird) .pos-btn.p3.on,.team-row.rank-4 .pos-btn.p3.on{border-color:rgba(190,24,93,.85)}
.team-row.rank-3:not(.qthird) .pos-btn.p3.on::after,.team-row.rank-4 .pos-btn.p3.on::after{background:#be185d}
/* shuffle button */
.shuffle-btn{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:8px;background:rgba(34,197,94,.08);border:none;border-top:1px solid rgba(34,197,94,.15);color:var(--green);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;cursor:pointer;transition:background .15s;font-family:var(--font)}
.shuffle-btn:hover{background:rgba(34,197,94,.16)}
/* thirds */
.thirds-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:8px}
.third-slot{background:var(--panel);border:2px solid var(--border);border-radius:10px;padding:10px 6px;text-align:center;cursor:pointer;transition:all var(--t);position:relative}
.third-slot:hover{border-color:var(--border-md)}.third-slot.selected{border-color:var(--blue);background:rgba(59,130,246,.07)}
.third-slot-flag{height:22px;display:flex;justify-content:center;align-items:center;margin-bottom:3px}.third-slot-flag img{width:28px;height:auto;border-radius:2px}.third-slot-name{font-size:10px;font-weight:600;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.third-slot-group{font-size:9px;color:var(--text-3)}
.third-rank{position:absolute;top:3px;right:3px;width:18px;height:18px;border-radius:50%;background:var(--green);color:#fff;font-size:11px;display:none;align-items:center;justify-content:center;line-height:1}
/* bracket */
.bracket-wrap{overflow-x:auto;padding-bottom:16px;-webkit-overflow-scrolling:touch}
.bracket{display:flex;align-items:flex-start;gap:0;padding:8px 4px 12px;min-width:max-content}
.bracket-round{flex-shrink:0;width:190px;display:flex;flex-direction:column}
.bracket-round+.bracket-round{margin-left:42px;position:relative}
.bracket-round-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-3);text-align:center;padding:0 0 10px;border-bottom:2px solid var(--border);margin-bottom:12px}
.bracket-pair{display:flex;flex-direction:column;gap:4px;position:relative;margin-bottom:16px}
.bracket-pair:last-child{margin-bottom:0}
.bracket-pair::before{content:'';position:absolute;right:-23px;top:calc(25%);bottom:calc(25%);width:1px;background:var(--border-md);pointer-events:none}
.bracket-pair::after{content:'';position:absolute;right:-23px;top:calc(50% - 1px);width:23px;height:1px;background:var(--border-md);pointer-events:none}
.bracket-round:last-child .bracket-pair::before,.bracket-round:last-child .bracket-pair::after{display:none}
.bracket-match-wrap{position:relative}
.bracket-match{background:var(--panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;transition:border-color .2s,box-shadow .2s;box-shadow:0 2px 8px rgba(0,0,0,.2)}
.bracket-match:hover{box-shadow:0 4px 14px rgba(0,0,0,.28)}
.bracket-match.has-winner{border-color:rgba(34,197,94,.28);box-shadow:0 2px 10px rgba(34,197,94,.1)}
.bracket-slot{padding:8px 11px;display:flex;align-items:center;gap:8px;font-size:11px;font-weight:500;color:var(--text-2);min-height:34px;transition:background .15s,color .15s;cursor:default}
.bracket-slot.clickable{cursor:pointer}
.bracket-slot.clickable:hover{background:var(--panel-2);color:var(--text)}
.bracket-slot.winner{background:linear-gradient(90deg,rgba(34,197,94,.13),rgba(34,197,94,.03));color:var(--green);font-weight:700;border-left:3px solid rgba(34,197,94,.55)}
.bracket-slot.tbd .bracket-slot-name{color:var(--text-3);font-style:italic;font-size:10px;font-weight:400}
.bracket-slot-flag{width:20px;height:15px;flex-shrink:0;display:flex;align-items:center}
.bracket-slot-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bracket-divider{height:1px;background:var(--border)}
/* champion column */
.bracket-champion-col{flex-shrink:0;display:flex;flex-direction:column;justify-content:center;margin-left:42px;position:relative}
.bracket-champion-col::before{content:'';position:absolute;left:0;top:50%;width:1px;height:0}
.bracket-champion{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:18px 14px;background:linear-gradient(135deg,rgba(245,158,11,.1),rgba(245,158,11,.04));border:2px solid rgba(245,158,11,.3);border-radius:14px;position:relative;min-width:110px}
.bracket-champion::before{content:'';position:absolute;left:-23px;top:50%;width:23px;height:1px;background:rgba(245,158,11,.35);pointer-events:none}
.bracket-champion-icon{font-size:26px;line-height:1}
.bracket-champion-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--gold);opacity:.75}
.bracket-champion-name{font-size:12px;font-weight:700;color:var(--gold);text-align:center;max-width:100px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bracket-champion-flag{display:flex;align-items:center;justify-content:center}
/* prizes */
.prizes-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-top:20px}
.prize-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px}
.prize-icon{font-size:24px;margin-bottom:6px}.prize-title{font-size:13px;font-weight:600;color:var(--text);margin-bottom:3px}
.prize-sub{font-size:11px;color:var(--text-2);margin-bottom:8px}
.prize-input,.prize-select{width:100%;background:var(--panel-2);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-size:13px;font-family:var(--font)}
.prize-input:focus,.prize-select:focus{outline:none;border-color:var(--red)}.prize-input::placeholder{color:var(--text-3)}
.prize-badge{display:inline-flex;align-items:center;gap:4px;background:rgba(245,158,11,.12);color:var(--gold);border-radius:6px;padding:2px 8px;font-size:10px;font-weight:700;margin-left:8px}
/* submit bar */
.submit-bar{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:14px;margin-top:20px;flex-wrap:wrap}
.submit-bar-info{font-size:13px;color:var(--text-2)}.submit-bar-info strong{color:var(--text)}
.submitted-banner{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);border-radius:var(--radius-sm);padding:11px 16px;display:flex;align-items:center;gap:9px;margin-bottom:16px;color:var(--green);font-weight:600;font-size:13px}
/* jogos */
.date-group{margin-bottom:20px}
.date-group-header{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.date-label{font-size:12px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.08em}
.date-today{background:rgba(252,0,37,.12);color:var(--red);border-radius:6px;padding:2px 8px;font-size:10px;font-weight:700}
.score-card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);padding:11px 14px;display:flex;align-items:center;gap:10px;margin-bottom:7px;flex-wrap:wrap}
.score-team{display:flex;align-items:center;gap:7px;flex:1;min-width:80px}
.score-team-name{font-size:12px;font-weight:600;color:var(--text)}
.score-team.right{flex-direction:row-reverse}.score-team.right .score-team-name{text-align:right}
.score-input-wrap{display:flex;align-items:center;gap:5px;flex-shrink:0}
.score-input{width:42px;height:34px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:7px;color:var(--text);font-size:15px;font-weight:700;text-align:center;font-family:var(--font)}
.score-input:focus{outline:none;border-color:var(--red)}
.score-sep{color:var(--text-3);font-weight:700;font-size:12px}
.score-result-display{font-size:17px;font-weight:800;color:var(--green);flex-shrink:0;min-width:46px;text-align:center}
.score-time{font-size:10px;color:var(--text-3);flex-shrink:0}
.score-pred-result{font-size:10px;color:var(--text-3);flex-shrink:0}
.no-matches{text-align:center;padding:40px 0;color:var(--text-3);font-size:13px}
/* ranking */
.ranking-table{width:100%;border-collapse:collapse}
.ranking-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);padding:7px 12px;border-bottom:1px solid var(--border);text-align:left}
.ranking-table td{padding:10px 12px;border-bottom:1px solid var(--border);font-size:13px;color:var(--text-2)}
.ranking-table tr:last-child td{border-bottom:none}
.ranking-table tr.me td{background:rgba(252,0,37,.04);font-weight:600}
.ranking-name{font-weight:600;color:var(--text)}
.ranking-pts{font-weight:700;color:var(--gold);font-size:14px}.ranking-pts.zero{color:var(--text-3)}
/* admin */
.admin-section{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px 18px;margin-bottom:16px}
.admin-section-title{font-size:13px;font-weight:700;color:var(--text);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.admin-form{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;align-items:end}
.admin-form-group{display:flex;flex-direction:column;gap:4px}
.admin-form-group label{font-size:11px;font-weight:600;color:var(--text-3)}
.admin-input,.admin-select{background:var(--panel-2);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:7px 10px;font-size:12px;font-family:var(--font);width:100%}
.admin-input:focus,.admin-select:focus{outline:none;border-color:var(--purple)}
.match-row{display:flex;align-items:center;gap:10px;padding:8px 10px;background:var(--panel-2);border:1px solid var(--border);border-radius:8px;margin-bottom:6px;flex-wrap:wrap}
.match-row-info{flex:1;font-size:12px;color:var(--text-2)}
.match-row-teams{font-weight:600;color:var(--text);font-size:13px}
.match-row-date{font-size:10px;color:var(--text-3)}
.scoring-legend{background:var(--panel-2);border:1px solid var(--border);border-radius:8px;padding:12px 14px;font-size:12px;color:var(--text-2)}
.scoring-legend li{margin-bottom:4px;list-style:none;padding-left:4px}
/* no data */
.no-data{text-align:center;padding:60px 20px}
.no-data-icon{font-size:48px;margin-bottom:14px}.no-data-title{font-size:18px;font-weight:600;color:var(--text);margin-bottom:6px}.no-data-sub{font-size:13px;color:var(--text-2)}
.section-info{font-size:12px;color:var(--text-2);margin-bottom:14px}
.matches-table{width:100%;border-collapse:collapse;font-size:12px}
.matches-table th{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-3);padding:6px 10px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap}
.matches-table td{padding:7px 10px;border-bottom:1px solid var(--border);color:var(--text-2);vertical-align:middle}
.matches-table tr:last-child td{border-bottom:none}
.matches-table tr:hover td{background:rgba(255,255,255,.015)}
.match-edit-input{background:var(--panel-2);color:var(--text);border:1px solid var(--border-md);border-radius:6px;padding:4px 7px;font-size:11px;font-family:var(--font);width:100%}
.match-edit-input:focus{outline:none;border-color:var(--purple)}
.match-edit-select{background:var(--panel-2);color:var(--text);border:1px solid var(--border-md);border-radius:6px;padding:4px 7px;font-size:11px;font-family:var(--font)}
.csv-drop{border:2px dashed var(--border-md);border-radius:10px;padding:20px;text-align:center;cursor:pointer;transition:border-color var(--t);margin-bottom:10px}
.csv-drop:hover,.csv-drop.drag-over{border-color:var(--purple);background:rgba(139,92,246,.05)}
.csv-drop-icon{font-size:28px;margin-bottom:6px}
.csv-drop-text{font-size:13px;color:var(--text-2)}.csv-drop-sub{font-size:11px;color:var(--text-3);margin-top:3px}
@media(max-width:900px){
  .sidebar{transform:translateX(-100%);transition:transform var(--t) var(--ease)}.sidebar.open{transform:translateX(0)}
  .sb-close{display:block}.page{margin-left:0}.mob-bar{display:flex}
  .main{padding:16px 16px 50px}
  .copa-hero{padding:14px 16px}.copa-hero-title{font-size:18px}
  .groups-grid{grid-template-columns:repeat(2,1fr);gap:8px}
  .thirds-grid{grid-template-columns:repeat(4,1fr)}
  .prizes-grid{grid-template-columns:1fr}
  .awards-grid{grid-template-columns:1fr}
  .pred-groups-mini{grid-template-columns:repeat(2,1fr)}
  .pred-awards{grid-template-columns:1fr}
  .admin-form{grid-template-columns:1fr 1fr}
}

@media(max-width:540px){
  .main{padding:12px 12px 40px}
  .copa-hero{padding:12px 14px;flex-direction:column;gap:8px}.copa-hero-title{font-size:16px}
  .copa-tabs{gap:0}.copa-tab{padding:9px 11px;font-size:12px}
  .groups-grid{grid-template-columns:repeat(2,1fr);gap:6px}
  .group-card-header{padding:6px 10px}.group-col-header{padding:4px 8px}
  .team-row{padding:5px 8px;gap:6px}.pos-btn{width:20px;height:20px}.pos-btn.on::after{width:8px;height:8px}
  .team-name-sm{font-size:10px}
  .thirds-grid{grid-template-columns:repeat(3,1fr)}
  .bracket-slot{padding:5px 8px;font-size:11px}
  .prizes-grid{gap:8px}
  .prize-card{padding:12px}
  .submit-bar{flex-direction:column;align-items:stretch;gap:10px}
  .submit-bar > div:last-child{display:flex;gap:8px}
  .submit-bar > div:last-child > button{flex:1}
  .admin-form{grid-template-columns:1fr}
  .score-card{gap:6px}
  .accordion-title{font-size:13px}
  .pred-groups-mini{grid-template-columns:repeat(2,1fr);gap:5px}
  .pred-group-mini{padding:6px 8px}
}
@media(max-width:540px){.main{padding:14px 14px 40px}.groups-grid{grid-template-columns:repeat(2,1fr)}.thirds-grid{grid-template-columns:repeat(3,1fr)}}
</style>
</head>
<body>

<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-logo">FBA</div>
    <div class="sb-brand">FBA <span>Games</span></div>
    <button class="sb-close" onclick="closeSidebar()"><i class="bi bi-x-lg"></i></button>
  </div>
  <div class="sb-user">
    <div class="sb-user-name"><?=htmlspecialchars($usuario['nome']??'')?></div>
    <div class="sb-user-role"><?=$isAdmin?'Admin':'Jogador'?></div>
  </div>
  <div class="sb-stats">
    <div class="sb-stat">
      <i class="bi bi-hand-index-fill" style="color:var(--green)"></i>
      <div class="sb-stat-val"><?=number_format((int)($usuario['numero_tapas']??0),0,',','.')?></div>
      <div class="sb-stat-label">Tapas</div>
    </div>
    <div class="sb-stat">
      <img src="moeda.png" style="width:18px;height:18px;object-fit:contain;vertical-align:middle">
      <div class="sb-stat-val"><?=number_format((int)($usuario['pontos']??0),0,',','.')?></div>
      <div class="sb-stat-label">Moedas</div>
    </div>
    <div class="sb-stat">
      <img src="lebron.png" style="width:18px;height:18px;object-fit:contain;vertical-align:middle">
      <div class="sb-stat-val"><?=number_format((int)($usuario['fba_points']??0),0,',','.')?></div>
      <div class="sb-stat-label">FBA Pts</div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-nav-section">Menu</div>
    <a href="index.php" class="sb-link">
      <i class="bi bi-lightning-charge"></i>Apostas
    </a>
    <a href="games.php" class="sb-link">
      <i class="bi bi-joystick"></i>Games
    </a>
    <a href="copa26.php" class="sb-link active">
      <i class="bi bi-trophy-fill"></i>Copa 2026
    </a>
    <a href="user/ranking-geral.php" class="sb-link">
      <i class="bi bi-bar-chart-fill"></i>Ranking Geral
    </a>
    <?php if ($isAdmin): ?>
    <div class="sb-nav-section">Admin</div>
    <a href="admin/controlegames.php" class="sb-link">
      <i class="bi bi-gear-fill"></i>Controle de Jogos
    </a>
    <a href="admin/dashboard.php" class="sb-link">
      <i class="bi bi-receipt-cutoff"></i>Controle Apostas
    </a>
    <a href="admin/controle-financas.php" class="sb-link">
      <i class="bi bi-cash-coin"></i>Controle Finanças
    </a>
    <a href="admin/controle-tapas.php" class="sb-link">
      <i class="bi bi-hand-index-thumb-fill"></i>Controle de Tapas
    </a>
    <a href="admin/controle-pontuacao.php" class="sb-link">
      <i class="bi bi-coin"></i>Controle Pontuação
    </a>
    <?php endif; ?>
    <a href="admin/dadosjogadores.php" class="sb-link">
      <i class="bi bi-person-lines-fill"></i>Dados dos Jogadores
    </a>
  </nav>
  <div class="sb-footer">
    <a class="sb-logout" href="auth/logout.php"><i class="bi bi-box-arrow-right"></i>Sair</a>
  </div>
</aside>
<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<?php if (!empty($pendingToday)): ?>
<!-- ── Popup: palpites pendentes de hoje ─────────────────── -->
<div id="pendingOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9000;display:flex;align-items:center;justify-content:center;padding:16px">
  <div style="background:var(--panel);border:1px solid rgba(252,0,37,.3);border-radius:16px;width:100%;max-width:420px;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.6)">
    <div style="background:linear-gradient(135deg,#1a0510,#0a1628);padding:18px 20px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:10px">
      <span style="font-size:22px">⚽</span>
      <div>
        <div style="font-size:15px;font-weight:800;color:#fff">Jogos de hoje!</div>
        <div style="font-size:11px;color:rgba(255,255,255,.45);margin-top:1px">Preencha seu palpite antes do apito</div>
      </div>
      <button onclick="document.getElementById('pendingOverlay').style.display='none'" style="margin-left:auto;background:none;border:none;color:rgba(255,255,255,.4);font-size:18px;cursor:pointer;line-height:1">✕</button>
    </div>
    <div style="padding:14px 16px;max-height:60vh;overflow-y:auto">
      <?php foreach ($pendingToday as $pm):
          $hN=$pm['hname']?:$pm['home_name']?:'?';
          $aN=$pm['aname']?:$pm['away_name']?:'?';
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:11px 12px;background:var(--panel-2);border:1px solid var(--border);border-radius:10px;margin-bottom:8px">
        <div style="flex:1;display:flex;align-items:center;gap:7px;min-width:0">
          <?=flagImg($pm['hflag']??'',22)?>
          <span style="font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?=htmlspecialchars($hN)?></span>
        </div>
        <div style="display:flex;align-items:center;gap:5px;flex-shrink:0">
          <input type="number" min="0" max="20" class="score-input" id="pop_sh_<?=$pm['id']?>" placeholder="0" style="width:42px;text-align:center">
          <span style="color:var(--text-3);font-size:13px">×</span>
          <input type="number" min="0" max="20" class="score-input" id="pop_sa_<?=$pm['id']?>" placeholder="0" style="width:42px;text-align:center">
        </div>
        <div style="flex:1;display:flex;align-items:center;gap:7px;justify-content:flex-end;min-width:0">
          <span style="font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;direction:rtl"><?=htmlspecialchars($aN)?></span>
          <?=flagImg($pm['aflag']??'',22)?>
        </div>
        <span style="font-size:10px;color:var(--text-3);flex-shrink:0"><?=htmlspecialchars($pm['match_time']??'')?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="padding:14px 16px;border-top:1px solid var(--border);display:flex;gap:8px;justify-content:flex-end">
      <button onclick="document.getElementById('pendingOverlay').style.display='none'" style="padding:9px 16px;border-radius:8px;border:1px solid var(--border);background:none;color:var(--text-2);font-size:13px;font-weight:600;cursor:pointer">Depois</button>
      <button onclick="savePendingPopup()" style="padding:9px 18px;border-radius:8px;border:none;background:var(--red);color:#fff;font-size:13px;font-weight:700;cursor:pointer"><i class="bi bi-check-lg"></i> Salvar palpites</button>
    </div>
  </div>
</div>
<script>
const PENDING_IDS=<?=json_encode(array_column($pendingToday,'id'))?>;
async function savePendingPopup(){
  const scores={};
  PENDING_IDS.forEach(id=>{
    const h=document.getElementById('pop_sh_'+id);
    const a=document.getElementById('pop_sa_'+id);
    if(h&&a&&(h.value!==''||a.value!=='')){
      scores[id]={h:parseInt(h.value)||0,a:parseInt(a.value)||0};
      // sync to main inputs if present
      const mh=document.getElementById('sh_'+id);const ma=document.getElementById('sa_'+id);
      if(mh)mh.value=scores[id].h;if(ma)ma.value=scores[id].a;
    }
  });
  if(!Object.keys(scores).length){document.getElementById('pendingOverlay').style.display='none';return;}
  await fetch(location.href,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'scores',scores})});
  document.getElementById('pendingOverlay').style.display='none';
}
</script>
<?php endif; ?>

<div class="page">
<div class="mob-bar">
  <button class="mob-ham" onclick="openSidebar()"><i class="bi bi-list"></i></button>
  <div class="mob-title">Bolão <span>Copa 2026</span></div>
  <?php if ($submitted): ?><span class="tag green" style="font-size:10px"><i class="bi bi-check-circle-fill"></i>Enviado</span><?php endif; ?>
</div>

<div class="main">

<?php if (!$canAccess): ?>
<div style="text-align:center;padding:80px 20px;max-width:480px;margin:0 auto">
  <div style="font-size:56px;margin-bottom:20px">🏆</div>
  <h2 style="font-size:22px;font-weight:800;color:var(--text);margin-bottom:10px">Bolão Copa do Mundo 2026</h2>
  <p style="font-size:14px;color:var(--text-2);line-height:1.7;margin-bottom:24px">Para participar do bolão, é necessário realizar o pagamento de <strong style="color:var(--gold)">R$ 10,00</strong>. Entre em contato com o administrador para confirmar seu pagamento e ter acesso liberado.</p>
  <div style="background:var(--panel);border:1px solid rgba(245,158,11,.3);border-radius:12px;padding:20px;display:flex;align-items:center;gap:14px;text-align:left">
    <span style="font-size:32px;flex-shrink:0">💰</span>
    <div>
      <div style="font-size:14px;font-weight:700;color:var(--gold)">Taxa de participação: R$ 10,00</div>
      <div style="font-size:12px;color:var(--text-3);margin-top:4px">Após o pagamento, aguarde a confirmação do administrador para liberar seu acesso.</div>
    </div>
  </div>
</div>
<?php else: ?>

<!-- Copa hero -->
<div class="copa-hero">
  <div>
    <div class="copa-hero-title">Copa do Mundo <span>2026</span></div>
    <div class="copa-hero-sub">48 seleções · 12 grupos · USA, Canadá, México</div>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?php if ($submitted): ?><span class="tag green"><i class="bi bi-check-circle-fill"></i>Palpite enviado</span>
    <?php elseif ($seeded): ?><span class="tag gray">Palpites abertos</span><?php endif; ?>
  </div>
</div>

<?php if (!$seeded): ?>
<div class="no-data">
  <div class="no-data-icon">⚽</div>
  <div class="no-data-title">Bolão ainda não configurado</div>
  <div class="no-data-sub">Aguarde o administrador configurar os grupos.</div>
  <?php if ($isAdmin): ?><br><a href="copa26.php?action=seed" class="btn-r primary" style="margin-top:12px"><i class="bi bi-database-fill-add"></i>Configurar agora</a><?php endif; ?>
</div>
<?php else: ?>

<!-- Submitted banner -->
<?php if ($submitted): ?>
<div class="submitted-banner">
  <i class="bi bi-check-circle-fill" style="font-size:18px"></i>
  Palpite enviado em <?=date('d/m/Y H:i',strtotime($pred['submitted_at']??''))?>!
  <?php if ($hasOfficial): ?>&nbsp;—&nbsp;Você fez <strong style="color:var(--gold)"><?=(int)($pred['points']??0)?> pontos</strong><?php endif; ?>
</div>

<!-- Accordion: meu palpite -->
<div class="accordion" id="myPredAccordion">
  <div class="accordion-header" onclick="toggleAccordion('myPredAccordion')">
    <div style="display:flex;align-items:center;gap:8px">
      <i class="bi bi-person-check-fill" style="color:var(--gold)"></i>
      <span class="accordion-title">Meu Palpite</span>
      <?php if ($hasOfficial): ?><span class="accordion-pts"><?=(int)($pred['points']??0)?> pts</span><?php endif; ?>
    </div>
    <i class="bi bi-chevron-down accordion-chevron" id="myPredChevron"></i>
  </div>
  <div class="accordion-body" id="myPredBody">
    <div class="pred-summary">
      <!-- grupos -->
      <div class="pred-section">
        <div class="pred-section-title">Classificação dos Grupos</div>
        <div class="pred-groups-mini">
        <?php foreach ($groups as $letter => $g):
            $savedOrder = $predGroups[$letter] ?? array_column($g['teams'],'id');
            $ordTeams = array_filter(array_map(fn($id)=>$allTeams[$id]??null,$savedOrder));
        ?>
        <div class="pred-group-mini">
          <div class="pred-group-mini-letter">Grupo <?=$letter?></div>
          <?php foreach (array_values($ordTeams) as $i=>$t): ?>
          <div class="pred-team-mini <?=$i===0?'first':($i===1?'second':'')?>">
            <?=flagImg($t['flag']??'')?><span><?=htmlspecialchars($t['name'])?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        </div>
      </div>
      <!-- prêmios -->
      <div class="pred-section">
        <div class="pred-section-title">Prêmios</div>
        <div class="pred-awards">
          <div class="pred-award" style="border-color:rgba(245,158,11,.25)"><span class="pred-award-icon">🏆</span><div><div class="pred-award-label" style="color:var(--gold)">Campeão (5 pts)</div><div class="pred-award-name"><?=htmlspecialchars($pred['champion']??'—')?></div></div></div>
          <div class="pred-award"><span class="pred-award-icon">🥈</span><div><div class="pred-award-label">Vice (3 pts)</div><div class="pred-award-name"><?=htmlspecialchars($pred['vice']??'—')?></div></div></div>
          <div class="pred-award"><span class="pred-award-icon">👟</span><div><div class="pred-award-label">Artilheiro</div><div class="pred-award-name"><?=htmlspecialchars($pred['top_scorer']??'—')?></div></div></div>
          <div class="pred-award"><span class="pred-award-icon">🌟</span><div><div class="pred-award-label">Melhor Jogador</div><div class="pred-award-name"><?=htmlspecialchars($pred['best_player']??'—')?></div></div></div>
          <div class="pred-award"><span class="pred-award-icon">⭐</span><div><div class="pred-award-label">Revelação</div><div class="pred-award-name"><?=htmlspecialchars($pred['revelation']??'—')?></div></div></div>
          <div class="pred-award"><span class="pred-award-icon">🇧🇷</span><div><div class="pred-award-label">Gols do Neymar</div><div class="pred-award-name"><?=htmlspecialchars($pred['neymar_goals']??'—')?></div></div></div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// Visibilidade das abas para não-admins:
// grupos_open=1 → todos veem; grupos_open=0 → só quem submeteu vê grupos+bracket
$showGruposTab  = $isAdmin || $groupsOpen || $submitted;
$showBracketTab = ($isAdmin || $submitted) && $bracketOpen;
$defaultTab     = $showGruposTab ? 'grupos' : 'jogos';
?>
<!-- Tabs -->
<div class="copa-tabs" id="copaTabs">
  <button class="copa-tab <?= $defaultTab==='grupos'?'active':'' ?>" data-tab="grupos" <?= $showGruposTab ? '' : 'style="display:none"' ?>>⚽ Grupos</button>
  <button class="copa-tab" data-tab="bracket" <?= $showBracketTab ? '' : 'style="display:none"' ?>>🏆 Bracket</button>
  <button class="copa-tab <?= $defaultTab==='jogos'?'active':'' ?>" data-tab="jogos">⚽ Jogos</button>
  <button class="copa-tab" data-tab="ranking">📊 Ranking</button>
  <?php if ($isAdmin): ?><button class="copa-tab admin-tab" data-tab="admin">🔧 Admin</button><?php endif; ?>
</div>

<!-- ── GRUPOS ─────────────────────────────────────────────────────────────── -->
<div class="copa-pane <?= $defaultTab==='grupos'?'active':'' ?>" id="pane-grupos">
  <p class="section-info"><i class="bi bi-info-circle"></i> Clique em <strong>1</strong>, <strong>2</strong> ou <strong>3</strong> ao lado de cada time para definir sua posição no grupo. O <strong>3</strong> marca o terceiro que avança (máx. 8).</p>
  <div style="font-size:12px;color:var(--text-2);margin-bottom:10px">Terceiros marcados: <strong id="thirdsSelectedCount"><?=count($predThirds??[])?></strong>/8</div>
  <div class="groups-grid" id="groupsGrid">
  <?php foreach ($groups as $letter=>$g):
      $savedOrder=$predGroups[$letter]??null; $teams=$g['teams'];
      if ($savedOrder) usort($teams,function($a,$b) use ($savedOrder){$pa=array_search($a['id'],$savedOrder);$pb=array_search($b['id'],$savedOrder);return($pa===false?99:$pa)-($pb===false?99:$pb);});
      $thirdSel = in_array($g['id'], $predThirds??[]);
  ?>
  <div class="group-card" data-group="<?=$letter?>">
    <div class="group-card-header"><span class="group-letter">GRUPO <?=$letter?></span></div>
    <div class="group-col-header"><span class="group-col-sel">Seleção</span><div class="group-col-pos"><span>1°</span><span>2°</span><span>3°</span></div></div>
    <div class="group-teams" id="gt_<?=$letter?>">
    <?php foreach ($teams as $idx=>$t): $rank=$idx+1; $isQ3=($rank===3&&$thirdSel); ?>
    <div class="team-row rank-<?=$rank?><?=$isQ3?' qthird':''?>" data-team-id="<?=$t['id']?>" data-team-name="<?=htmlspecialchars($t['name'])?>" data-team-flag="<?=htmlspecialchars($t['flag']??'')?>">
      <span class="team-flag"><?=flagImg($t['flag']??'')?></span>
      <span class="team-name-sm"><?=htmlspecialchars($t['name'])?></span>
      <?php if (!$submitted): ?>
      <div class="pos-btns">
        <button type="button" class="pos-btn p1<?=$rank===1?' on':''?>" onclick="setGroupPos('<?=$letter?>',<?=$t['id']?>,1,false)"></button>
        <button type="button" class="pos-btn p2<?=$rank===2?' on':''?>" onclick="setGroupPos('<?=$letter?>',<?=$t['id']?>,2,false)"></button>
        <button type="button" class="pos-btn p3<?=$isQ3?' on':''?>" onclick="setGroupPos('<?=$letter?>',<?=$t['id']?>,3,false)"></button>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php if (!$submitted && $groupsOpen): ?>
    <button class="shuffle-btn" onclick="shuffleGroup('<?=$letter?>',false)"><i class="bi bi-shuffle"></i>Sorteio Aleatório</button>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>
  <?php if (!$submitted && $groupsOpen): ?>
  <div style="margin-top:14px;text-align:right">
    <button class="btn-r outline" onclick="saveDraft()"><i class="bi bi-floppy"></i>Salvar</button>
  </div>
  <?php elseif (!$submitted && !$groupsOpen): ?>
  <div style="margin-top:14px;padding:10px 14px;background:rgba(252,0,37,.06);border:1px solid rgba(252,0,37,.2);border-radius:8px;font-size:12px;color:var(--red)">
    <i class="bi bi-lock-fill"></i> Fase de grupos encerrada. Você não enviou seu palpite a tempo.
  </div>
  <?php endif; ?>
</div>

<!-- ── THIRDS (hidden — selection moved to groups tab) ───────────────────── -->
<div class="copa-pane" id="pane-thirds" style="display:none!important">
  <p style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:4px">Melhores 3ºs Colocados</p>
  <p class="section-info">Selecione os 8 grupos cujo 3º colocado você acha que avança. O chaveamento é definido automaticamente pela FIFA com base nos grupos classificados.</p>
  <div class="thirds-grid" id="thirdsGrid">
  <?php foreach ($groups as $letter=>$g):
      $teams=$g['teams'];
      $rankIdx = array_search($g['id'], $predThirds ?? []);
      $rank = $rankIdx !== false ? $rankIdx + 1 : 0;
  ?>
  <div class="third-slot <?=$rank?'selected':''?>"
       data-group="<?=$letter?>" data-group-id="<?=$g['id']?>"
       onclick="<?=$submitted?'':'rankThird(this)'?>" id="third_<?=$letter?>">
    <div class="third-rank" style="display:<?=$rank?'flex':'none'?>"><i class="bi bi-check-lg"></i></div>
    <div class="third-slot-flag" id="t3flag_<?=$letter?>"><?=flagImg($teams[2]['flag']??'')?></div>
    <div class="third-slot-name" id="t3name_<?=$letter?>"><?=htmlspecialchars($teams[2]['name']??'?')?></div>
    <div class="third-slot-group">Grupo <?=$letter?></div>
  </div>
  <?php endforeach; ?>
  </div>
  <div style="margin-top:8px;font-size:12px;color:var(--text-2)">Classificados: <strong id="thirdsCount"><?=count($predThirds??[])?></strong>/8</div>
  <?php if (!$submitted): ?>
  <div style="margin-top:14px;text-align:right">
    <button class="btn-r outline" onclick="nextTab('bracket')" style="display:none">Próximo: Bracket <i class="bi bi-arrow-right"></i></button>
  </div>
  <?php endif; ?>
</div>

<!-- ── BRACKET ────────────────────────────────────────────────────────────── -->
<div class="copa-pane" id="pane-bracket">
  <?php if ($bracketSubmitted): ?>
  <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);border-radius:8px;margin-bottom:14px;font-size:13px;color:var(--green)">
    <i class="bi bi-check-circle-fill"></i> Palpite do bracket enviado!
  </div>
  <?php else: ?>
  <p class="section-info"><i class="bi bi-info-circle"></i> Clique no time para avançá-lo. Gerado dos seus palpites de grupo.</p>
  <?php endif; ?>
  <div class="bracket-wrap"><div class="bracket" id="bracketEl"></div></div>
  <?php if (!$bracketSubmitted): ?>
  <div style="margin-top:14px;display:flex;gap:8px;justify-content:flex-end">
    <button class="btn-r secondary sm" onclick="saveBracketDraft()"><i class="bi bi-floppy"></i>Salvar rascunho</button>
    <button class="btn-r primary sm" onclick="submitBracket()"><i class="bi bi-send-fill"></i>Enviar Palpite do Bracket</button>
  </div>
  <?php endif; ?>

  <!-- Campeão / Vice -->
  <div style="margin-top:24px;padding-top:20px;border-top:1px solid var(--border)">
    <div style="font-size:14px;font-weight:700;color:var(--text);margin-bottom:4px">
      Campeão &amp; Vice
    </div>
    <p class="section-info">Campeão vale 5 pts · Vice vale 3 pts</p>
    <div class="prizes-grid" style="grid-template-columns:repeat(2,1fr)">
      <div class="prize-card" style="border-color:rgba(245,158,11,.3)">
        <div class="prize-icon">🏆</div><div class="prize-title" style="color:var(--gold)">Campeão <span class="prize-badge" style="background:rgba(245,158,11,.15);color:var(--gold);border-color:rgba(245,158,11,.3)">5 pts</span></div>
        <div class="prize-sub">Quem vai vencer a Copa 2026?</div>
        <input class="prize-input" id="award_champion" placeholder="Nome do país..." value="<?=htmlspecialchars($pred['champion']??'')?>" <?=$submitted?'readonly':''?>>
      </div>
      <div class="prize-card" style="border-color:rgba(148,163,184,.2)">
        <div class="prize-icon">🥈</div><div class="prize-title">Vice-Campeão <span class="prize-badge">3 pts</span></div>
        <div class="prize-sub">Quem vai perder na final?</div>
        <input class="prize-input" id="award_vice" placeholder="Nome do país..." value="<?=htmlspecialchars($pred['vice']??'')?>" <?=$submitted?'readonly':''?>>
      </div>
    </div>
  </div>

  <!-- Prêmios -->
  <div style="margin-top:20px;padding-top:20px;border-top:1px solid var(--border)">
    <div style="font-size:14px;font-weight:700;color:var(--text);margin-bottom:4px">
      Prêmios individuais
      <span class="prize-badge">3 pts cada</span>
    </div>
    <p class="section-info">Acertos exatos valem 3 pontos cada.</p>
    <div class="prizes-grid">
      <div class="prize-card">
        <div class="prize-icon">👟</div><div class="prize-title">Artilheiro</div><div class="prize-sub">Quem vai fazer mais gols?</div>
        <input class="prize-input" id="award_scorer" placeholder="Nome do jogador..." value="<?=htmlspecialchars($pred['top_scorer']??'')?>" <?=$submitted?'readonly':''?>>
      </div>
      <div class="prize-card">
        <div class="prize-icon">🌟</div><div class="prize-title">Melhor Jogador</div><div class="prize-sub">Quem ganha a Bola de Ouro?</div>
        <input class="prize-input" id="award_player" placeholder="Nome do jogador..." value="<?=htmlspecialchars($pred['best_player']??'')?>" <?=$submitted?'readonly':''?>>
      </div>
      <div class="prize-card">
        <div class="prize-icon">⭐</div><div class="prize-title">Revelação</div><div class="prize-sub">Quem vai ser a grande revelação?</div>
        <input class="prize-input" id="award_revelation" placeholder="Nome do jogador..." value="<?=htmlspecialchars($pred['revelation']??'')?>" <?=$submitted?'readonly':''?>>
      </div>
      <div class="prize-card">
        <div class="prize-icon">🇧🇷</div><div class="prize-title">Gols do Neymar</div><div class="prize-sub">Quantos gols o Neymar vai fazer?</div>
        <input class="prize-input" id="award_neymar" type="number" min="0" max="20" placeholder="Ex: 3" value="<?=htmlspecialchars($pred['neymar_goals']??'')?>" <?=$submitted?'readonly':''?>>
      </div>
    </div>
  </div>

  <?php if (!$submitted && $groupsOpen): ?>
  <div class="submit-bar">
    <div class="submit-bar-info"><strong>Tudo pronto?</strong> Após enviar não é possível editar.</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn-r secondary" onclick="saveDraft()"><i class="bi bi-floppy2"></i>Salvar rascunho</button>
      <button class="btn-r gold lg" onclick="submitPrediction()"><i class="bi bi-send-fill"></i>Enviar palpites</button>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ── JOGOS ──────────────────────────────────────────────────────────────── -->
<div class="copa-pane <?= $defaultTab==='jogos'?'active':'' ?>" id="pane-jogos">
  <div style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:rgba(59,130,246,.07);border:1px solid rgba(59,130,246,.2);border-radius:var(--radius-sm);font-size:12px;color:#7db4f5;margin-bottom:16px">
    <i class="bi bi-calendar-event" style="font-size:14px;flex-shrink:0"></i>
    Os jogos são liberados no dia em que acontecem. Volte a cada rodada para preencher seu palpite de placar.
  </div>
  <?php if (empty($allMatches)): ?>
  <div class="no-matches">Nenhum jogo cadastrado ainda.</div>
  <?php else: ?>
  <?php
  $datesSorted = array_keys($matchesByDate);
  sort($datesSorted);
  $orderedDates = array_values(array_filter($datesSorted, fn($d)=>$d<=$today));
  ?>
  <?php if (empty($orderedDates)): ?>
  <div style="text-align:center;padding:40px 20px;color:var(--text-3);font-size:13px">
    <i class="bi bi-clock" style="font-size:28px;display:block;margin-bottom:10px;color:var(--text-3)"></i>
    A competição começa em 11/06/2026. Os jogos aparecerão aqui no dia!
  </div>
  <?php else: ?>
  <?php foreach ($orderedDates as $date):
      $matches=$matchesByDate[$date]??[];
      $isToday=$date===$today;
      $isPast=$date<$today;
      $dtObj=DateTime::createFromFormat('Y-m-d',$date);
      $dateLabel=$dtObj?$dtObj->format('d/m/Y'):'';
  ?>
  <div class="date-group">
    <div class="date-group-header">
      <span class="date-label"><?=$dateLabel?></span>
      <?php if ($isToday): ?><span class="date-today">Hoje</span><?php endif; ?>
    </div>
    <?php foreach ($matches as $m):
        $hName=$m['hname']?:$m['home_name']?:'Time A';
        $aName=$m['aname']?:$m['away_name']?:'Time B';
        $sp=$allScores[$m['id']]??null;
        $hasResult=$m['score_home']!==null;
        $matchTs=strtotime($m['match_date'].' '.($m['match_time']??'23:59'));
        $started=$nowTs>=$matchTs;
        $canPredict=!$hasResult&&!$started;
    ?>
    <div class="score-card" data-match="<?=$m['id']?>">
      <div class="score-team">
        <?=flagImg($m['hflag']??'',22)?>
        <span class="score-team-name"><?=htmlspecialchars($hName)?></span>
      </div>
      <?php if ($hasResult): ?>
      <div class="score-result-display"><?=$m['score_home']?> – <?=$m['score_away']?></div>
      <?php if ($sp): ?>
      <div class="score-pred-result">Seu palpite: <?=$sp['score_home']?>×<?=$sp['score_away']?></div>
      <?php endif; ?>
      <?php elseif ($canPredict): ?>
      <div class="score-input-wrap">
        <input type="number" min="0" max="30" class="score-input" id="sh_<?=$m['id']?>" value="<?=$sp?$sp['score_home']:''?>" placeholder="0">
        <span class="score-sep">×</span>
        <input type="number" min="0" max="30" class="score-input" id="sa_<?=$m['id']?>" value="<?=$sp?$sp['score_away']:''?>" placeholder="0">
      </div>
      <?php else: /* started, no result yet */ ?>
      <div class="score-result-display" style="font-size:12px;color:var(--text-3)">Em andamento</div>
      <?php if ($sp): ?>
      <div class="score-pred-result">Seu palpite: <?=$sp['score_home']?>×<?=$sp['score_away']?></div>
      <?php endif; ?>
      <?php endif; ?>
      <div class="score-team right">
        <span class="score-team-name"><?=htmlspecialchars($aName)?></span>
        <?=flagImg($m['aflag']??'',22)?>
      </div>
      <span class="score-time"><?=htmlspecialchars($m['match_time']??'')?></span>
      <?php if ($isAdmin): ?>
      <div style="display:flex;gap:5px">
        <?php if (!$hasResult): ?>
        <button class="btn-r purple sm" onclick="setResult(<?=$m['id']?>)"><i class="bi bi-check2"></i>Resultado</button>
        <?php endif; ?>
        <button class="btn-r secondary sm" onclick="delMatch(<?=$m['id']?>)"><i class="bi bi-trash"></i></button>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <!-- save scores button -->
  <button class="btn-r primary" style="margin-top:4px" onclick="saveScores()"><i class="bi bi-check2-circle"></i>Salvar placares</button>
  <?php endif; // empty orderedDates ?>
  <?php endif; // empty allMatches ?>
</div>

<!-- ── RANKING ────────────────────────────────────────────────────────────── -->
<div class="copa-pane" id="pane-ranking">
  <?php if (!$hasOfficial): ?>
  <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:var(--radius-sm);padding:12px 16px;font-size:12px;color:var(--amber);margin-bottom:16px">
    <i class="bi bi-info-circle"></i> Pontos serão calculados após o admin definir os resultados oficiais.
  </div>
  <?php endif; ?>
  <!-- Tabela de pontuação -->
  <details style="margin-bottom:16px">
    <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--text-2);list-style:none;display:flex;align-items:center;gap:8px;padding:10px 14px;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm)">
      <i class="bi bi-info-circle" style="color:var(--blue)"></i> Esquema de Pontuação
      <i class="bi bi-chevron-down" style="margin-left:auto;font-size:11px"></i>
    </summary>
    <div style="background:var(--panel);border:1px solid var(--border);border-top:none;border-radius:0 0 var(--radius-sm) var(--radius-sm);padding:14px 16px">
      <table style="width:100%;border-collapse:collapse;font-size:12px">
        <thead>
          <tr style="border-bottom:1px solid var(--border)">
            <th style="text-align:left;padding:6px 8px;color:var(--text-3);font-size:10px;text-transform:uppercase;letter-spacing:.08em">Categoria</th>
            <th style="text-align:center;padding:6px 8px;color:var(--text-3);font-size:10px;text-transform:uppercase;letter-spacing:.08em">Pts</th>
            <th style="text-align:left;padding:6px 8px;color:var(--text-3);font-size:10px;text-transform:uppercase;letter-spacing:.08em">Detalhe</th>
          </tr>
        </thead>
        <tbody>
          <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:8px;color:var(--text);font-weight:600">Fase de Grupos</td>
            <td style="padding:8px;text-align:center;color:var(--blue);font-weight:700">1</td>
            <td style="padding:8px;color:var(--text-2)">Por acerto de posição (1º, 2º ou 3º) em cada grupo. 12 grupos × até 3 acertos = até 36 pts.</td>
          </tr>
          <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:8px;color:var(--text);font-weight:600">Terceiros colocados</td>
            <td style="padding:8px;text-align:center;color:var(--blue);font-weight:700">1<br><span style="font-size:10px;color:var(--gold)">+1 bônus</span></td>
            <td style="padding:8px;color:var(--text-2)">1pt se o time avançou. +1pt bônus se a posição exata no ranking dos 8 terceiros também estiver certa.</td>
          </tr>
          <tr style="border-bottom:1px solid var(--border);display:none">
            <td style="padding:8px;color:var(--text);font-weight:600">Bracket (mata-mata)</td>
            <td style="padding:8px;text-align:center;color:var(--blue);font-weight:700">1</td>
            <td style="padding:8px;color:var(--text-2)">Por time correto avançando em cada confronto — 16 avos, oitavas, quartas, semis e final.</td>
          </tr>
          <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:8px;color:var(--text);font-weight:600">Campeão</td>
            <td style="padding:8px;text-align:center;color:var(--gold);font-weight:700">5</td>
            <td style="padding:8px;color:var(--text-2)">5pts se acertar o país campeão.</td>
          </tr>
          <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:8px;color:var(--text);font-weight:600">Vice-Campeão</td>
            <td style="padding:8px;text-align:center;color:var(--gold);font-weight:700">3</td>
            <td style="padding:8px;color:var(--text-2)">3pts se acertar o país vice-campeão.</td>
          </tr>
          <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:8px;color:var(--text);font-weight:600">Artilheiro / Melhor jogador / Revelação</td>
            <td style="padding:8px;text-align:center;color:var(--gold);font-weight:700">3</td>
            <td style="padding:8px;color:var(--text-2)">3pts cada prêmio acertado (texto exato, sem distinção de maiúsculas).</td>
          </tr>
          <tr>
            <td style="padding:8px;color:var(--text);font-weight:600">Gols do Neymar</td>
            <td style="padding:8px;text-align:center;color:var(--gold);font-weight:700">3</td>
            <td style="padding:8px;color:var(--text-2)">3pts se acertar o número exato de gols.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </details>
  <?php if (empty($ranking)): ?>
  <div class="no-matches">Nenhum palpite enviado ainda.</div>
  <?php else: ?>
  <div style="background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden">
    <table class="ranking-table">
      <thead><tr>
        <th style="width:40px">#</th><th>Jogador</th><th>Campeão apostado</th><th style="text-align:right">Pontos</th>
      </tr></thead>
      <tbody>
      <?php foreach ($ranking as $i=>$r):
          $pos=$i+1; $isMe=strtolower($r['nome'])===strtolower($usuario['nome']??'');
      ?>
      <tr <?=$isMe?'class="me"':''?>>
        <td><?=$pos===1?'🥇':($pos===2?'🥈':($pos===3?'🥉':$pos))?></td>
        <td><span class="ranking-name"><?=htmlspecialchars($r['nome'])?><?=$isMe?' <span style="color:var(--red);font-size:10px">(você)</span>':''?></span></td>
        <td style="font-size:11px;color:var(--text-3)"><?=htmlspecialchars($r['champion']??'—')?></td>
        <td style="text-align:right"><span class="ranking-pts <?=(int)$r['points']===0?'zero':''?>"><?=(int)$r['points']?></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ── ADMIN ──────────────────────────────────────────────────────────────── -->
<?php if ($isAdmin): ?>
<div class="copa-pane" id="pane-admin">

  <!-- Controle do bracket -->
  <div class="admin-section">
    <div class="admin-section-title"><i class="bi bi-trophy-fill" style="color:var(--gold)"></i>Controle do Bracket (Mata-Mata)</div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <button class="btn-r <?= $groupsOpen ? 'secondary' : 'outline' ?>" id="btnToggleGroups" onclick="toggleGroupsOpen()">
        <i class="bi bi-<?= $groupsOpen ? 'lock-fill' : 'unlock' ?>"></i><?= $groupsOpen ? 'Fechar Fase de Grupos' : 'Abrir Fase de Grupos' ?>
      </button>
      <button class="btn-r <?= $bracketOpen ? 'primary' : 'outline' ?>" id="btnToggleBracket" onclick="toggleBracketOpen()">
        <i class="bi bi-<?= $bracketOpen ? 'lock-fill' : 'unlock' ?>"></i><?= $bracketOpen ? 'Fechar Bracket' : 'Abrir Bracket para palpites' ?>
      </button>
    </div>
  </div>

  <!-- Resultados oficiais dos grupos -->
  <div class="admin-section">
    <div class="admin-section-title"><i class="bi bi-check-circle-fill" style="color:var(--green)"></i>Resultados Oficiais dos Grupos</div>
    <p class="section-info">Ordene como os times realmente terminaram em cada grupo. Marque ✓ no 3º para indicar que avança.</p>
    <div style="font-size:12px;color:var(--text-2);margin-bottom:10px">Terceiros marcados: <strong id="admThirdsCount"><?=count($offThirds??[])?></strong>/8</div>
    <div class="groups-grid" id="admGroupsGrid">
    <?php foreach ($groups as $letter=>$g):
        $savedOff=$offGroups[$letter]??null; $teams=$g['teams'];
        if ($savedOff) usort($teams,function($a,$b) use ($savedOff){$pa=array_search($a['id'],$savedOff);$pb=array_search($b['id'],$savedOff);return($pa===false?99:$pa)-($pb===false?99:$pb);});
    ?>
    <div class="group-card">
      <div class="group-card-header"><span class="group-letter">GRUPO <?=$letter?></span><span class="group-label">Oficial</span></div>
      <div class="group-col-header"><span class="group-col-sel">Seleção</span><div class="group-col-pos"><span>1°</span><span>2°</span><span>3°</span></div></div>
      <div class="group-teams" id="adm_gt_<?=$letter?>">
      <?php foreach ($teams as $idx=>$t): $rank=$idx+1; $thirdSelAdm=in_array($g['id'],$offThirds??[]); $isQ3adm=($rank===3&&$thirdSelAdm); ?>
      <div class="team-row rank-<?=$rank?><?=$isQ3adm?' qthird':''?>" data-team-id="<?=$t['id']?>" data-team-name="<?=htmlspecialchars($t['name'])?>" data-team-flag="<?=htmlspecialchars($t['flag']??'')?>">
        <span class="team-flag"><?=flagImg($t['flag']??'')?></span>
        <span class="team-name-sm"><?=htmlspecialchars($t['name'])?></span>
        <div class="pos-btns">
          <button type="button" class="pos-btn p1<?=$rank===1?' on':''?>" onclick="setGroupPos('<?=$letter?>',<?=$t['id']?>,1,true)"></button>
          <button type="button" class="pos-btn p2<?=$rank===2?' on':''?>" onclick="setGroupPos('<?=$letter?>',<?=$t['id']?>,2,true)"></button>
          <button type="button" class="pos-btn p3<?=$isQ3adm?' on':''?>" onclick="setGroupPos('<?=$letter?>',<?=$t['id']?>,3,true)"></button>
        </div>
      </div>
      <?php endforeach; ?>
      </div>
      <button class="shuffle-btn" onclick="shuffleGroup('<?=$letter?>',true)"><i class="bi bi-shuffle"></i>Sorteio Aleatório</button>
    </div>
    <?php endforeach; ?>
    </div>
  </div>


  <!-- Bracket oficial -->
  <div class="admin-section" style="display:none">
    <div class="admin-section-title"><i class="bi bi-trophy-fill" style="color:var(--gold)"></i>Bracket Oficial (Mata-Mata)</div>
    <p class="section-info">Clique no time que avançou em cada confronto.</p>
    <div class="bracket-wrap"><div class="bracket" id="admBracketEl"></div></div>
  </div>

  <!-- Prêmios oficiais -->
  <div class="admin-section">
    <div class="admin-section-title"><i class="bi bi-star-fill" style="color:var(--gold)"></i>Prêmios Oficiais</div>
    <div class="prizes-grid">
      <div class="prize-card" style="border-color:rgba(245,158,11,.3)">
        <div class="prize-icon">🏆</div><div class="prize-title" style="color:var(--gold)">Campeão (5 pts)</div>
        <input class="prize-input" id="off_champion" placeholder="País campeão..." value="<?=htmlspecialchars($official['champion']??'')?>">
      </div>
      <div class="prize-card">
        <div class="prize-icon">🥈</div><div class="prize-title">Vice-Campeão (3 pts)</div>
        <input class="prize-input" id="off_vice" placeholder="País vice..." value="<?=htmlspecialchars($official['vice']??'')?>">
      </div>
      <div class="prize-card">
        <div class="prize-icon">👟</div><div class="prize-title">Artilheiro</div>
        <input class="prize-input" id="off_scorer" placeholder="Nome do artilheiro..." value="<?=htmlspecialchars($official['top_scorer']??'')?>">
      </div>
      <div class="prize-card">
        <div class="prize-icon">🌟</div><div class="prize-title">Melhor Jogador</div>
        <input class="prize-input" id="off_player" placeholder="Nome do melhor jogador..." value="<?=htmlspecialchars($official['best_player']??'')?>">
      </div>
      <div class="prize-card">
        <div class="prize-icon">⭐</div><div class="prize-title">Revelação</div>
        <input class="prize-input" id="off_revelation" placeholder="Nome da revelação..." value="<?=htmlspecialchars($official['revelation']??'')?>">
      </div>
      <div class="prize-card">
        <div class="prize-icon">🇧🇷</div><div class="prize-title">Gols do Neymar</div>
        <input class="prize-input" id="off_neymar" type="number" min="0" placeholder="Nº de gols..." value="<?=htmlspecialchars($official['neymar_goals']??'')?>">
      </div>
    </div>
    <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn-r green-btn" onclick="saveOfficial()"><i class="bi bi-cloud-check-fill"></i>Salvar resultados oficiais</button>
      <button class="btn-r gold" onclick="calcPoints()"><i class="bi bi-calculator-fill"></i>Calcular pontos</button>
    </div>
  </div>

  <!-- Jogos: seed + CSV + manual + lista -->
  <div class="admin-section">
    <div class="admin-section-title"><i class="bi bi-calendar-fill" style="color:var(--blue)"></i>Gerenciar Jogos</div>

    <!-- Quick actions -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
      <button class="btn-r secondary" style="border-color:rgba(239,68,68,.3);color:#ef4444" onclick="delAllMatches()"><i class="bi bi-trash-fill"></i>Apagar todos os jogos</button>
    </div>

    <!-- CSV upload -->
    <div style="margin-bottom:18px">
      <div style="font-size:12px;font-weight:600;color:var(--text);margin-bottom:8px">Importar via CSV</div>
      <div class="csv-drop" id="csvDrop" onclick="document.getElementById('csvFile').click()">
        <div class="csv-drop-icon">📂</div>
        <div class="csv-drop-text">Clique ou arraste um arquivo CSV</div>
        <div class="csv-drop-sub">Colunas: <code>data,horario,casa,visitante</code></div>
      </div>
      <input type="file" id="csvFile" accept=".csv,text/csv" style="display:none" onchange="handleCSV(this.files[0])">
      <div id="csvPreview" style="display:none;margin-top:8px">
        <div style="font-size:11px;color:var(--text-2);margin-bottom:6px" id="csvPreviewInfo"></div>
        <div style="max-height:180px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;margin-bottom:8px">
          <table class="matches-table" id="csvPreviewTable"></table>
        </div>
        <div style="display:flex;gap:8px">
          <button class="btn-r primary sm" onclick="importCSV()"><i class="bi bi-cloud-upload-fill"></i>Importar</button>
          <button class="btn-r secondary sm" onclick="cancelCSV()">Cancelar</button>
        </div>
      </div>
    </div>

    <!-- Add single match -->
    <div style="font-size:12px;font-weight:600;color:var(--text);margin-bottom:8px">Adicionar jogo manualmente</div>
    <div class="admin-form" style="margin-bottom:18px">
      <div class="admin-form-group"><label>Data</label><input type="date" class="admin-input" id="match_date" value="<?=$today?>"></div>
      <div class="admin-form-group"><label>Horário</label><input type="time" class="admin-input" id="match_time"></div>
      <div class="admin-form-group"><label>Casa</label>
        <select class="admin-select" id="match_home">
          <option value="">Selecione...</option>
          <?php foreach ($allTeams as $t): ?><option value="<?=$t['id']?>"><?=$t['flag']??''?> <?=htmlspecialchars($t['name'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="admin-form-group"><label>Visitante</label>
        <select class="admin-select" id="match_away">
          <option value="">Selecione...</option>
          <?php foreach ($allTeams as $t): ?><option value="<?=$t['id']?>"><?=$t['flag']??''?> <?=htmlspecialchars($t['name'])?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="admin-form-group" style="align-self:end">
        <button class="btn-r primary" onclick="addMatch()"><i class="bi bi-plus-lg"></i>Adicionar</button>
      </div>
    </div>

    <!-- Match list -->
    <div style="font-size:12px;font-weight:600;color:var(--text);margin-bottom:8px">
      Jogos cadastrados <span style="color:var(--text-3);font-weight:400">(<?=count($allMatches)?> total)</span>
    </div>
    <?php if (empty($allMatches)): ?>
    <div style="text-align:center;padding:20px;color:var(--text-3);font-size:12px">Nenhum jogo cadastrado ainda.</div>
    <?php else: ?>
    <div style="overflow-x:auto;border:1px solid var(--border);border-radius:8px">
      <table class="matches-table">
        <thead><tr>
          <th>Data</th><th>Hora</th><th>Casa</th><th>Visitante</th><th>Resultado</th><th style="width:90px">Ações</th>
        </tr></thead>
        <tbody id="adminMatchList">
        <?php
        // group by date for display
        $sortedMatches = $allMatches;
        usort($sortedMatches, fn($a,$b)=>strcmp($a['match_date'].$a['match_time'],$b['match_date'].$b['match_time']));
        foreach ($sortedMatches as $m):
            $hName=$m['hname']?:$m['home_name']?:'?';
            $aName=$m['aname']?:$m['away_name']?:'?';
            $hF=$m['hflag']??''; $aF=$m['aflag']??'';
            $res=$m['score_home']!==null?"{$m['score_home']}–{$m['score_away']}":'—';
        ?>
        <tr id="aml_<?=$m['id']?>">
          <td><?=htmlspecialchars($m['match_date']??'')?></td>
          <td><?=htmlspecialchars($m['match_time']??'—')?></td>
          <td><?=$hF?flagImg($hF,16):''?> <?=htmlspecialchars($hName)?></td>
          <td><?=$aF?flagImg($aF,16):''?> <?=htmlspecialchars($aName)?></td>
          <td><?=$res?></td>
          <td>
            <div style="display:flex;gap:4px">
              <button class="btn-r purple sm" onclick="editMatch(<?=$m['id']?>,<?=json_encode(['date'=>$m['match_date'],'time'=>$m['match_time']??'','home_id'=>$m['home_team_id'],'home_name'=>$hName,'away_id'=>$m['away_team_id'],'away_name'=>$aName,'sh'=>$m['score_home'],'sa'=>$m['score_away']])?> )">✏️</button>
              <button class="btn-r secondary sm" onclick="delMatch(<?=$m['id']?>)">🗑</button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</div>
<?php endif; ?>

<?php endif; /* seeded */ ?>
<?php endif; /* canAccess */ ?>
</div>
</div>

<script>
const SUBMITTED          = <?=json_encode($submitted)?>;
const BRACKET_OPEN       = <?=json_encode((bool)$bracketOpen)?>;
const BRACKET_SUBMITTED  = <?=json_encode($bracketSubmitted)?>;
const GROUPS_OPEN_INIT   = <?=json_encode((bool)$groupsOpen)?>;
const GROUPS_DATA= <?=json_encode(array_map(fn($g)=>$g['teams'],$groups))?>;
const GROUP_KEYS = <?=json_encode(array_keys($groups))?>;

// user order
const groupOrder={};
<?php foreach ($groups as $letter=>$g): $saved=$predGroups[$letter]??null; $ids=$saved??array_column($g['teams'],'id'); ?>
groupOrder[<?=json_encode($letter)?>]=<?=json_encode(array_map('intval',$ids))?>;
<?php endforeach; ?>

// admin official order
const admGroupOrder={};
<?php foreach ($groups as $letter=>$g): $saved=$offGroups[$letter]??null; $ids=$saved??array_column($g['teams'],'id'); ?>
admGroupOrder[<?=json_encode($letter)?>]=<?=json_encode(array_map('intval',$ids))?>;
<?php endforeach; ?>

let selectedThirds=<?=json_encode(array_map('intval',$predThirds??[]))?>;
let admSelectedThirds=<?=json_encode(array_map('intval',$offThirds??[]))?>;
const bracketState=<?=json_encode($predBracket?:(object)[])?>;
const admBracketState=<?=json_encode($offBracket?:(object)[])?>;

function escH(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function flagImg(code,w=20){const h=Math.round(w*.75);if(!code)return`<span style="display:inline-block;width:${w}px;height:${h}px;background:rgba(255,255,255,.08);border-radius:2px;vertical-align:middle;flex-shrink:0"></span>`;return`<span class="fi fi-${code}" style="width:${w}px;height:${h}px;border-radius:2px;vertical-align:middle;display:inline-block;background-size:cover;flex-shrink:0"></span>`;}
function getTeamById(id){for(const k of GROUP_KEYS){const t=(GROUPS_DATA[k]||[]).find(x=>x.id==id);if(t)return t;}return null;}

// ── Generic group render ──────────────────────────────────────────────────────
function renderGroupEl(letter,prefix,orderMap,editable){
    const c=document.getElementById(prefix+'gt_'+letter);if(!c)return;
    const o=orderMap[letter];
    const isAdm=(prefix==='adm_');
    const thirdsArr=isAdm?admSelectedThirds:selectedThirds;
    const gid=LETTER_TO_GROUP_ID[letter];
    const isQ=gid&&thirdsArr.indexOf(gid)>=0;
    c.innerHTML='';
    o.forEach((tid,idx)=>{
        const t=getTeamById(tid);if(!t)return;
        const d=document.createElement('div');
        let cls='team-row ';
        if(idx===0) cls+='rank-1';
        else if(idx===1) cls+='rank-2';
        else if(idx===2&&isQ) cls+='rank-3 qthird';
        else if(idx===2) cls+='rank-3';
        else cls+='rank-4';
        d.className=cls;
        d.dataset.teamId=t.id;
        let inner=`<span class="team-flag">${flagImg(t.flag,20)}</span><span class="team-name-sm">${escH(t.name)}</span>`;
        if(editable){
            const fn=isAdm?`setGroupPos('${letter}',${t.id},%p,true)`:`setGroupPos('${letter}',${t.id},%p,false)`;
            const on1=idx===0?' on':'', on2=idx===1?' on':'', on3=(idx===2&&isQ)?' on':'';
            inner+=`<div class="pos-btns"><button type="button" class="pos-btn p1${on1}" onclick="${fn.replace('%p',1)}"></button><button type="button" class="pos-btn p2${on2}" onclick="${fn.replace('%p',2)}"></button><button type="button" class="pos-btn p3${on3}" onclick="${fn.replace('%p',3)}"></button></div>`;
        }
        d.innerHTML=inner;
        c.appendChild(d);
    });
}
function renderGroup(l){renderGroupEl(l,'',groupOrder,!SUBMITTED);}
function renderAdmGroup(l){renderGroupEl(l,'adm_',admGroupOrder,true);}

function shuffleGroup(letter,isAdm){
    if(!isAdm&&SUBMITTED)return;
    const orderMap=isAdm?admGroupOrder:groupOrder;
    const arr=orderMap[letter];
    for(let i=arr.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[arr[i],arr[j]]=[arr[j],arr[i]];}
    if(isAdm)renderAdmGroup(letter);
    else renderGroup(letter);
}

// ── Group position click handler ──────────────────────────────────────────────
function setGroupPos(letter, teamId, posBtn, isAdm){
    if(!isAdm&&SUBMITTED)return;
    const orderMap=isAdm?admGroupOrder:groupOrder;
    const thirdsArr=isAdm?admSelectedThirds:selectedThirds;
    const gid=LETTER_TO_GROUP_ID[letter];
    const arr=orderMap[letter];
    const tid=+teamId;
    const targetIdx=posBtn-1;
    const currentIdx=arr.indexOf(tid);

    if(posBtn===3){
        const isQ=thirdsArr.indexOf(gid)>=0;
        if(currentIdx===2&&isQ){
            // toggle off: un-qualify this group
            thirdsArr.splice(thirdsArr.indexOf(gid),1);
        } else {
            // qualify this group (check max 8)
            if(!isQ){
                if(thirdsArr.length>=8){showToast('Máximo de 8 terceiros colocados.',true);return;}
                thirdsArr.push(gid);
            }
            // move team to idx 2
            if(currentIdx!==2){[arr[2],arr[currentIdx]]=[arr[currentIdx],arr[2]];}
        }
    } else {
        // pos 1 or 2: swap teams
        if(currentIdx!==targetIdx){[arr[targetIdx],arr[currentIdx]]=[arr[currentIdx],arr[targetIdx]];}
    }

    const countEl=document.getElementById(isAdm?'admThirdsCount':'thirdsSelectedCount');
    if(countEl)countEl.textContent=(isAdm?admSelectedThirds:selectedThirds).length;
    if(isAdm)renderAdmGroup(letter);
    else renderGroup(letter);
}

// ── Thirds count refresh (counters only) ─────────────────────────────────────
function refreshGroupThirdBtns(){
    const el=document.getElementById('thirdsSelectedCount');
    if(el)el.textContent=selectedThirds.length;
}
function refreshAdmGroupThirdBtns(){
    const el=document.getElementById('admThirdsCount');
    if(el)el.textContent=admSelectedThirds.length;
}

// ── Generic bracket ───────────────────────────────────────────────────────────
const ROUND_NAMES={r32:'16 avos',r16:'Oitavas',qf:'Quartas',sf:'Semifinal',final:'Final'};
const ROUND_ORDER=['r32','r16','qf','sf','final'];
let bracketMatchups={r32:[],r16:[],qf:[],sf:[],final:[]};
let admBracketMatchups={r32:[],r16:[],qf:[],sf:[],final:[]};

// map group DB id → letter  (used to resolve thirds)
const GROUP_ID_TO_LETTER = <?=json_encode(array_combine(
    array_map(fn($g)=>$g['id'], array_values($groups)),
    array_keys($groups)
))?>;
// reverse map: letter → group DB id
const LETTER_TO_GROUP_ID = <?=json_encode(array_combine(
    array_keys($groups),
    array_map(fn($g)=>$g['id'], array_values($groups))
))?>;

// ── Third-place slot assignment — bipartite matching per FIFA Annex C ────────
// Each R32 slot (by array index in buildR32) lists which groups' 3rd can fill it.
// Constraint: a 3rd from group X cannot face Winner X (same-group rematch).
const THIRD_SLOT_ELIGIBLE = {
    1:  ['A','B','C','D','F'],   // J74 idx1: vs 1E
    4:  ['C','D','F','G','H'],   // J77 idx4: vs 1I
    6:  ['C','E','F','H','I'],   // J79 idx6: vs 1A
    7:  ['E','H','I','J','K'],   // J80 idx7: vs 1L
    8:  ['B','E','F','I','J'],   // J81 idx8: vs 1D
    9:  ['A','E','H','I','J'],   // J82 idx9: vs 1G
    12: ['E','F','G','I','J'],   // J85 idx12: vs 1B
    14: ['D','E','I','J','L'],   // J87 idx14: vs 1K
};

function assignThirdsToSlots(thirdsArr, tMap) {
    const thirdByGroup = {};
    thirdsArr.forEach(gid => {
        const l = GROUP_ID_TO_LETTER[gid];
        if (l && tMap[l]) thirdByGroup[l] = tMap[l];
    });
    const qSet = new Set(Object.keys(thirdByGroup));
    // Sort slots by fewest eligible qualifying groups first (most-constrained first)
    const slotIndices = Object.keys(THIRD_SLOT_ELIGIBLE).map(Number)
        .sort((a,b) =>
            THIRD_SLOT_ELIGIBLE[a].filter(g=>qSet.has(g)).length -
            THIRD_SLOT_ELIGIBLE[b].filter(g=>qSet.has(g)).length
        );
    const result = {}, used = new Set();
    function bt(i) {
        if (i === slotIndices.length) return true;
        const slot = slotIndices[i];
        for (const g of THIRD_SLOT_ELIGIBLE[slot].filter(g=>qSet.has(g)&&!used.has(g)).sort()) {
            result[slot] = thirdByGroup[g];
            used.add(g);
            if (bt(i+1)) return true;
            delete result[slot];
            used.delete(g);
        }
        return false;
    }
    bt(0);
    return s => result[s] || null;
}

function buildR32(orderMap){
    const f={},s={},t={};
    GROUP_KEYS.forEach(l=>{
        f[l]=getTeamById(orderMap[l][0]);
        s[l]=getTeamById(orderMap[l][1]);
        t[l]=getTeamById(orderMap[l][2]);
    });
    const thirdsArr=orderMap===groupOrder?selectedThirds:admSelectedThirds;
    const nth=assignThirdsToSlots(thirdsArr,t);

    // R32 ordenado em pares que alimentam cada jogo do R16:
    // [0,1]→R16[0]  [2,3]→R16[1]  [4,5]→R16[2]  [6,7]→R16[3]
    // [8,9]→R16[4] [10,11]→R16[5] [12,13]→R16[6] [14,15]→R16[7]
    return [
        [s['A'], s['B']],   // J73 idx0: 2A vs 2B       → R16[0]
        [f['E'], nth(1)],   // J74 idx1: 1E vs 3º(A/B/C/D/F)→ R16[0]
        [f['F'], s['C']],   // J75 idx2: 1F vs 2C        → R16[1]
        [f['C'], s['F']],   // J76 idx3: 1C vs 2F        → R16[1]
        [f['I'], nth(4)],   // J77 idx4: 1I vs 3º(C/D/F/G/H)→R16[2]
        [s['E'], s['I']],   // J78 idx5: 2E vs 2I        → R16[2]
        [f['A'], nth(6)],   // J79 idx6: 1A vs 3º(C/E/F/H/I)→R16[3]
        [f['L'], nth(7)],   // J80 idx7: 1L vs 3º(E/H/I/J/K)→R16[3]
        [f['D'], nth(8)],   // J81 idx8: 1D vs 3º(B/E/F/I/J)→R16[4]
        [f['G'], nth(9)],   // J82 idx9: 1G vs 3º(A/E/H/I/J)→R16[4]
        [s['K'], s['L']],   // J83 idx10: 2K vs 2L       → R16[5]
        [f['H'], s['J']],   // J84 idx11: 1H vs 2J       → R16[5]
        [f['B'], nth(12)],  // J85 idx12: 1B vs 3º(E/F/G/I/J)→R16[6]
        [f['J'], s['H']],   // J86 idx13: 1J vs 2H       → R16[6]
        [f['K'], nth(14)],  // J87 idx14: 1K vs 3º(D/E/I/J/L)→R16[7]
        [s['D'], s['G']],   // J88 idx15: 2D vs 2G       → R16[7]
    ];
}

function buildBracketGeneric(matchupsRef,orderMap,stateRef){
    matchupsRef.r32=buildR32(orderMap);
    ['r16','qf','sf','final'].forEach(r=>{const sz={r16:8,qf:4,sf:2,final:1}[r];matchupsRef[r]=Array.from({length:sz},()=>[null,null]);});
    Object.keys(stateRef).forEach(key=>{
        const m=key.match(/^([a-z0-9]+)_(\d+)$/);if(!m)return;
        const [,round,idxStr]=m,idx=+idxStr,team=stateRef[key];
        if(!team||!matchupsRef[round])return;
        const nr=ROUND_ORDER[ROUND_ORDER.indexOf(round)+1];
        if(nr&&matchupsRef[nr]){const ni=Math.floor(idx/2),ns=idx%2;if(!matchupsRef[nr][ni])matchupsRef[nr][ni]=[null,null];matchupsRef[nr][ni][ns]=team;}
    });
}

function renderBracketGeneric(elId,matchupsRef,stateRef,editable,onWin){
    const el=document.getElementById(elId);if(!el)return;el.innerHTML='';
    ROUND_ORDER.forEach(round=>{
        const matches=matchupsRef[round];if(!matches)return;
        const col=document.createElement('div');col.className='bracket-round';
        const titleEl=document.createElement('div');titleEl.className='bracket-round-title';
        titleEl.textContent=ROUND_NAMES[round]||round;col.appendChild(titleEl);

        const pairSize=matches.length>1?2:1;
        for(let pi=0;pi<matches.length;pi+=pairSize){
            const pair=document.createElement('div');pair.className='bracket-pair';
            for(let mi=pi;mi<Math.min(pi+pairSize,matches.length);mi++){
                const [t1,t2]=matches[mi];
                const w=stateRef[round+'_'+mi];
                const wId=w&&typeof w==='object'?w.id:null;
                const mwrap=document.createElement('div');mwrap.className='bracket-match-wrap';
                const card=document.createElement('div');
                card.className='bracket-match'+(wId?' has-winner':'');
                const makeSlot=team=>{
                    const slot=document.createElement('div');
                    const isW=wId&&team&&wId==team.id;
                    slot.className='bracket-slot'+(isW?' winner':'')+(team?'':' tbd')+(team&&editable?' clickable':'');
                    if(team&&editable)slot.onclick=()=>onWin(round,mi,team);
                    const fspan=document.createElement('span');fspan.className='bracket-slot-flag';
                    if(team&&team.flag){
                        const fs=document.createElement('span');
                        fs.className='fi fi-'+team.flag;
                        fs.style.cssText='width:20px;height:15px;border-radius:2px;display:inline-block;background-size:cover;flex-shrink:0';
                        fspan.appendChild(fs);
                    }
                    const nspan=document.createElement('span');nspan.className='bracket-slot-name';
                    nspan.textContent=team?team.name:'A definir';
                    slot.appendChild(fspan);slot.appendChild(nspan);return slot;
                };
                card.appendChild(makeSlot(t1));
                const divEl=document.createElement('div');divEl.className='bracket-divider';card.appendChild(divEl);
                card.appendChild(makeSlot(t2));
                mwrap.appendChild(card);pair.appendChild(mwrap);
            }
            col.appendChild(pair);
        }
        el.appendChild(col);
    });
    // champion display column
    const finalW=stateRef['final_0'];
    const champCol=document.createElement('div');champCol.className='bracket-champion-col';
    const champDiv=document.createElement('div');champDiv.className='bracket-champion';
    let champInner=`<div class="bracket-champion-icon">🏆</div><div class="bracket-champion-label">Campeão</div>`;
    if(finalW&&finalW.flag){
        const fs=document.createElement('span');
        champInner+=`<div class="bracket-champion-flag">${flagImg(finalW.flag,30)}</div>`;
    }
    champInner+=finalW
        ?`<div class="bracket-champion-name">${escH(finalW.name)}</div>`
        :`<div class="bracket-champion-name" style="color:var(--text-3);font-style:italic;font-size:10px;font-weight:400">A definir</div>`;
    champDiv.innerHTML=champInner;
    champCol.appendChild(champDiv);
    el.appendChild(champCol);
}

function setWinnerGeneric(round,matchIdx,team,stateRef,matchupsRef){
    stateRef[round+'_'+matchIdx]=team;
    const nr=ROUND_ORDER[ROUND_ORDER.indexOf(round)+1];
    if(nr){const ni=Math.floor(matchIdx/2),ns=matchIdx%2;if(!matchupsRef[nr][ni])matchupsRef[nr][ni]=[null,null];matchupsRef[nr][ni][ns]=team;}
}

let BRACKET_EDITABLE = BRACKET_OPEN && !BRACKET_SUBMITTED;
function setWinner(r,i,team){setWinnerGeneric(r,i,team,bracketState,bracketMatchups);renderBracketGeneric('bracketEl',bracketMatchups,bracketState,BRACKET_EDITABLE,setWinner);}
function admSetWinner(r,i,team){setWinnerGeneric(r,i,team,admBracketState,admBracketMatchups);renderBracketGeneric('admBracketEl',admBracketMatchups,admBracketState,true,admSetWinner);}

function buildBracket(){buildBracketGeneric(bracketMatchups,groupOrder,bracketState);renderBracketGeneric('bracketEl',bracketMatchups,bracketState,BRACKET_EDITABLE,setWinner);}
function buildAdmBracket(){buildBracketGeneric(admBracketMatchups,admGroupOrder,admBracketState);renderBracketGeneric('admBracketEl',admBracketMatchups,admBracketState,true,admSetWinner);}

// ── Save / Submit ─────────────────────────────────────────────────────────────
function buildPayload(action){
    const groups={};GROUP_KEYS.forEach(l=>groups[l]=groupOrder[l]);
    return{action,groups,thirds:selectedThirds,bracket:bracketState,
        champion:document.getElementById('award_champion')?.value||'',
        vice:document.getElementById('award_vice')?.value||'',
        top_scorer:document.getElementById('award_scorer')?.value||'',
        best_player:document.getElementById('award_player')?.value||'',
        revelation:document.getElementById('award_revelation')?.value||'',
        neymar_goals:document.getElementById('award_neymar')?.value||''};
}
async function saveDraft(){const r=await post(buildPayload('save'));showToast(r.ok?'Rascunho salvo!':'Erro ao salvar.',!r.ok);}
async function saveBracketDraft(){
    const r=await post({action:'save_bracket',bracket:bracketState});
    showToast(r.ok?'Bracket salvo!':'Erro ao salvar.',!r.ok);
}
async function submitBracket(){
    if(!confirm('Enviar palpite do bracket? Não será possível editar depois.'))return;
    await post({action:'save_bracket',bracket:bracketState});
    const r=await post({action:'submit_bracket'});
    if(r.ok)location.reload();else showToast('Erro.',true);
}
async function submitPrediction(){
    if(!confirm('Tem certeza? Após enviar não é possível editar.'))return;
    await post(buildPayload('save'));
    const r=await post({action:'submit'});if(r.ok)location.reload();else showToast('Erro.',true);
}

// ── Scores ────────────────────────────────────────────────────────────────────
async function saveScores(){
    const scores={};
    document.querySelectorAll('.score-card[data-match]').forEach(c=>{
        const mid=c.dataset.match;
        const h=document.getElementById('sh_'+mid)?.value,a=document.getElementById('sa_'+mid)?.value;
        if(h!==undefined&&a!==undefined)scores[mid]={h:+h||0,a:+a||0};
    });
    const r=await post({action:'scores',scores});showToast(r.ok?'Placares salvos!':'Erro.',!r.ok);
}
async function setResult(matchId){
    const h=prompt('Gols casa:');if(h===null)return;const a=prompt('Gols visitante:');if(a===null)return;
    const r=await post({action:'set_result',match_id:matchId,home:+h,away:+a});if(r.ok)location.reload();
}
async function delMatch(matchId){
    if(!confirm('Remover jogo?'))return;const r=await post({action:'del_match',match_id:matchId});if(r.ok)location.reload();
}
async function addMatch(){
    const date=document.getElementById('match_date')?.value;
    const time=document.getElementById('match_time')?.value;
    const homeId=document.getElementById('match_home')?.value;
    const awayId=document.getElementById('match_away')?.value;
    if(!date||!homeId||!awayId){showToast('Preencha data e times.',true);return;}
    const r=await post({action:'add_match',date,time,home_id:+homeId,away_id:+awayId});
    if(r.ok){showToast('Jogo adicionado!');setTimeout(()=>location.reload(),800);}else showToast('Erro.',true);
}

async function seedFixtures(){
    if(!confirm('Isso vai apagar os jogos da fase de grupos e criar os 72 jogos com o calendário oficial. Continuar?'))return;
    const r=await post({action:'seed_fixtures'});
    if(r.ok){showToast(`${r.inserted} jogos criados!`);setTimeout(()=>location.reload(),1000);}
    else showToast('Erro.',true);
}
async function delAllMatches(){
    if(!confirm('Apagar TODOS os jogos e palpites de placar? Isso não pode ser desfeito.'))return;
    const r=await post({action:'del_all_matches'});
    if(r.ok){showToast('Todos os jogos apagados.');setTimeout(()=>location.reload(),800);}
    else showToast('Erro.',true);
}

// ── CSV import ────────────────────────────────────────────────────────────────
let csvRows=[];
function handleCSV(file){
    if(!file)return;
    const reader=new FileReader();
    reader.onload=e=>{
        const lines=e.target.result.replace(/\r/g,'').split('\n').filter(l=>l.trim());
        const header=lines[0].toLowerCase().split(',').map(h=>h.trim());
        csvRows=lines.slice(1).map(line=>{
            const cols=line.split(',').map(c=>c.trim().replace(/^"|"$/g,''));
            const obj={};header.forEach((h,i)=>{
                const key=h.includes('data')||h==='date'?'date':h.includes('hora')||h==='time'?'time':h.includes('casa')||h==='home'?'home':'away';
                obj[key]=cols[i]||'';
            });
            return obj;
        }).filter(r=>r.date&&r.home&&r.away);
        // preview
        const preview=document.getElementById('csvPreview');
        const info=document.getElementById('csvPreviewInfo');
        const table=document.getElementById('csvPreviewTable');
        info.textContent=`${csvRows.length} jogos encontrados`;
        table.innerHTML='<thead><tr><th>Data</th><th>Horário</th><th>Casa</th><th>Visitante</th></tr></thead><tbody>'+
            csvRows.slice(0,10).map(r=>`<tr><td>${escH(r.date)}</td><td>${escH(r.time)}</td><td>${escH(r.home)}</td><td>${escH(r.away)}</td></tr>`).join('')+
            (csvRows.length>10?`<tr><td colspan="4" style="color:var(--text-3);text-align:center">+${csvRows.length-10} mais...</td></tr>`:'')+
            '</tbody>';
        preview.style.display='block';
        // reset drag
        document.getElementById('csvDrop').classList.remove('drag-over');
    };
    reader.readAsText(file);
}
async function importCSV(){
    if(!csvRows.length){showToast('Nenhum dado.',true);return;}
    const r=await post({action:'csv_import',rows:csvRows});
    if(r.ok){showToast(`${r.inserted} importados, ${r.failed} falhas.`);if(r.inserted>0)setTimeout(()=>location.reload(),1200);}
    else showToast('Erro.',true);
}
function cancelCSV(){document.getElementById('csvPreview').style.display='none';csvRows=[];document.getElementById('csvFile').value='';}

// drag-over on csv-drop
(function(){
    const drop=document.getElementById('csvDrop');if(!drop)return;
    drop.addEventListener('dragover',e=>{e.preventDefault();drop.classList.add('drag-over');});
    drop.addEventListener('dragleave',()=>drop.classList.remove('drag-over'));
    drop.addEventListener('drop',e=>{e.preventDefault();drop.classList.remove('drag-over');if(e.dataTransfer.files[0])handleCSV(e.dataTransfer.files[0]);});
})();

// ── Match inline edit ─────────────────────────────────────────────────────────
const ALL_TEAMS_SEL = `<?php echo implode('', array_map(fn($t)=>'<option value="'.$t['id'].'">'.(htmlspecialchars($t['flag']??'')).' '.htmlspecialchars($t['name']).'</option>', $allTeams)); ?>`;

function editMatch(id, data){
    const row=document.getElementById('aml_'+id);if(!row)return;
    row.innerHTML=`
        <td><input class="match-edit-input" id="ei_date_${id}" type="date" value="${escH(data.date||'')}"></td>
        <td><input class="match-edit-input" id="ei_time_${id}" type="time" value="${escH(data.time||'')}"></td>
        <td><select class="match-edit-select" id="ei_home_${id}">${ALL_TEAMS_SEL}</select></td>
        <td><select class="match-edit-select" id="ei_away_${id}">${ALL_TEAMS_SEL}</select></td>
        <td><input class="match-edit-input" style="width:32px" id="ei_sh_${id}" type="number" min="0" value="${data.sh??''}"> – <input class="match-edit-input" style="width:32px" id="ei_sa_${id}" type="number" min="0" value="${data.sa??''}"></td>
        <td><div style="display:flex;gap:4px">
            <button class="btn-r green-btn sm" onclick="saveMatch(${id})">✓</button>
            <button class="btn-r secondary sm" onclick="location.reload()">✕</button>
        </div></td>`;
    // set selected values
    document.getElementById('ei_home_'+id).value=data.home_id||'';
    document.getElementById('ei_away_'+id).value=data.away_id||'';
}
async function saveMatch(id){
    const r=await post({action:'update_match',match_id:id,
        date:document.getElementById('ei_date_'+id)?.value||'',
        time:document.getElementById('ei_time_'+id)?.value||'',
        home_id:+document.getElementById('ei_home_'+id)?.value||0,
        away_id:+document.getElementById('ei_away_'+id)?.value||0,
        score_home:document.getElementById('ei_sh_'+id)?.value,
        score_away:document.getElementById('ei_sa_'+id)?.value});
    if(r.ok){showToast('Jogo atualizado!');setTimeout(()=>location.reload(),700);}
    else showToast('Erro.',true);
}

// ── Admin save official ───────────────────────────────────────────────────────
async function saveOfficial(){
    const groups={};GROUP_KEYS.forEach(l=>groups[l]=admGroupOrder[l]);
    const r=await post({action:'save_official',groups,thirds:admSelectedThirds,bracket:admBracketState,
        champion:document.getElementById('off_champion')?.value||'',
        vice:document.getElementById('off_vice')?.value||'',
        top_scorer:document.getElementById('off_scorer')?.value||'',
        best_player:document.getElementById('off_player')?.value||'',
        revelation:document.getElementById('off_revelation')?.value||'',
        neymar_goals:document.getElementById('off_neymar')?.value||''});
    showToast(r.ok?'Resultados oficiais salvos!':'Erro.',!r.ok);
}
async function calcPoints(){
    const r=await post({action:'calc_points'});showToast(r.ok?r.msg||'Pontos calculados!':r.msg||'Erro.',!r.ok);
    if(r.ok)setTimeout(()=>location.reload(),1200);
}
let _bracketOpen = <?=json_encode((bool)$bracketOpen)?>;
let _groupsOpen  = <?=json_encode((bool)$groupsOpen)?>;
async function toggleGroupsOpen(){
    const newVal = _groupsOpen ? 0 : 1;
    const r=await post({action:'toggle_groups_open',open:newVal});
    if(!r.ok){showToast('Erro.',true);return;}
    _groupsOpen = !!newVal;
    const btn=document.getElementById('btnToggleGroups');
    if(btn){
        btn.innerHTML=_groupsOpen
            ?'<i class="bi bi-lock-fill"></i>Fechar Fase de Grupos'
            :'<i class="bi bi-unlock"></i>Abrir Fase de Grupos';
        btn.className='btn-r '+(_groupsOpen?'secondary':'outline');
    }
    // mostrar/ocultar aba de grupos para não-admins (sem reload — apenas reflecte estado)
    document.querySelectorAll('[data-tab="grupos"]').forEach(t=>{
        if(!SUBMITTED) t.style.display=_groupsOpen?'':'none';
    });
    showToast(_groupsOpen?'Fase de grupos aberta!':'Fase de grupos encerrada.');
}
async function toggleBracketOpen(){
    const newVal = _bracketOpen ? 0 : 1;
    const r=await post({action:'toggle_bracket_open',open:newVal});
    if(!r.ok){showToast('Erro.',true);return;}
    _bracketOpen = !!newVal;
    const btn=document.getElementById('btnToggleBracket');
    if(btn){
        btn.innerHTML=_bracketOpen
            ?'<i class="bi bi-lock-fill"></i>Fechar Bracket'
            :'<i class="bi bi-unlock"></i>Abrir Bracket para palpites';
        btn.className='btn-r '+(_bracketOpen?'primary':'outline');
    }
    // show/hide bracket tab
    document.querySelectorAll('[data-tab="bracket"]').forEach(t=>t.style.display=_bracketOpen?'':'none');
    // atualiza editabilidade e re-renderiza bracket se já construído
    BRACKET_EDITABLE = _bracketOpen && !BRACKET_SUBMITTED;
    if(bracketMatchups.r32&&bracketMatchups.r32.length) renderBracketGeneric('bracketEl',bracketMatchups,bracketState,BRACKET_EDITABLE,setWinner);
    showToast(_bracketOpen?'Bracket aberto para palpites!':'Bracket fechado.');
}
async function resetBrackets(){
    if(!confirm('Resetar o bracket de TODOS os usuários? Os palpites da fase de grupos são mantidos.'))return;
    const r=await post({action:'reset_brackets'});
    showToast(r.ok?'Brackets resetados!':'Erro.',!r.ok);
}

// ── Tabs ──────────────────────────────────────────────────────────────────────
document.querySelectorAll('.copa-tab').forEach(btn=>{
    btn.addEventListener('click',()=>{
        document.querySelectorAll('.copa-tab').forEach(b=>b.classList.remove('active'));
        document.querySelectorAll('.copa-pane').forEach(p=>p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('pane-'+btn.dataset.tab)?.classList.add('active');
        if(btn.dataset.tab==='bracket')buildBracket();
        if(btn.dataset.tab==='admin')buildAdmBracket();
    });
});
function nextTab(tab){
    if(!SUBMITTED)fetch('copa26.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(buildPayload('save'))}).catch(()=>{});
    document.querySelectorAll('.copa-tab').forEach(b=>b.classList.toggle('active',b.dataset.tab===tab));
    document.querySelectorAll('.copa-pane').forEach(p=>p.classList.toggle('active',p.id==='pane-'+tab));
    if(tab==='bracket')buildBracket();
    if(tab==='admin')buildAdmBracket();
}

// ── Accordion ─────────────────────────────────────────────────────────────────
function toggleAccordion(id){
    const body=document.getElementById(id.replace('Accordion','Body'));
    const chev=document.getElementById(id.replace('Accordion','Chevron'));
    if(body)body.classList.toggle('open');if(chev)chev.classList.toggle('open');
}

// ── Utils ─────────────────────────────────────────────────────────────────────
async function post(body){
    try{const r=await fetch('copa26.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});return await r.json();}catch(e){return{ok:false};}
}
function showToast(msg,err=false){
    let t=document.getElementById('fba-toast');
    if(!t){t=document.createElement('div');t.id='fba-toast';t.style.cssText='position:fixed;bottom:24px;left:50%;transform:translateX(-50%);padding:10px 20px;border-radius:8px;font-size:13px;font-weight:600;z-index:9999;transition:opacity .3s;font-family:var(--font)';document.body.appendChild(t);}
    t.textContent=msg;t.style.background=err?'#fc0025':'#22c55e';t.style.color='#fff';t.style.opacity='1';
    clearTimeout(t._timer);t._timer=setTimeout(()=>t.style.opacity='0',2800);
}
function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('sbOverlay').classList.add('open');}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sbOverlay').classList.remove('open');}

// ── Init ──────────────────────────────────────────────────────────────────────
<?php if($seeded&&!$submitted):?>GROUP_KEYS.forEach(l=>renderGroup(l));refreshGroupThirdBtns();<?php endif;?>
<?php if($seeded&&$isAdmin):?>GROUP_KEYS.forEach(l=>renderAdmGroup(l));refreshAdmGroupThirdBtns();<?php endif;?>
</script>
</body>
</html>
