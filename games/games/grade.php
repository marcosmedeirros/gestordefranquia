<?php
session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php"); exit;
}
$user_id = (int)$_SESSION['user_id'];
$PONTOS_VITORIA = 200 * getGamePointsMultiplier($pdo, 'grade');

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS grade_historico (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        data_jogo DATE NOT NULL,
        respostas TEXT DEFAULT '[]',
        pontos_ganhos INT DEFAULT 0,
        concluido TINYINT DEFAULT 0,
        desistiu TINYINT DEFAULT 0,
        streak_count INT DEFAULT 0,
        UNIQUE KEY uq (id_usuario, data_jogo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

$PLAYERS = [
    ['n'=>'LeBron James',           't'=>['CLE','MIA','LAL'],                         'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Stephen Curry',          't'=>['GSW'],                                      'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Kevin Durant',           't'=>['OKC','GSW','BKN','PHX'],                   'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','SCORING']],
    ['n'=>'Giannis Antetokounmpo',  't'=>['MIL'],                                      'c'=>'GRE','a'=>['MVP','CHAMPION','ALLSTAR','DPOY','FINALS_MVP']],
    ['n'=>'Nikola Jokic',           't'=>['DEN'],                                      'c'=>'SER','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Luka Doncic',            't'=>['DAL','LAL'],                                'c'=>'SLO','a'=>['ALLSTAR','ROY']],
    ['n'=>'Joel Embiid',            't'=>['PHI','LAL'],                                'c'=>'CAM','a'=>['MVP','ALLSTAR','SCORING']],
    ['n'=>'Kawhi Leonard',          't'=>['SAS','TOR','LAC'],                          'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY','FINALS_MVP']],
    ['n'=>'Kobe Bryant',            't'=>['LAL'],                                      'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','SCORING']],
    ['n'=>'Shaquille O\'Neal',      't'=>['LAL','MIA','PHX','CLE','BOS'],             'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Dwyane Wade',            't'=>['MIA','CHI','CLE'],                          'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP','SCORING']],
    ['n'=>'Chris Bosh',             't'=>['TOR','MIA'],                                'c'=>'CAN','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Dirk Nowitzki',          't'=>['DAL'],                                      'c'=>'GER','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Tim Duncan',             't'=>['SAS'],                                      'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY']],
    ['n'=>'Tony Parker',            't'=>['SAS','CHA'],                                'c'=>'FRA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Manu Ginobili',          't'=>['SAS'],                                      'c'=>'ARG','a'=>['CHAMPION','ALLSTAR','SIXTHMAN']],
    ['n'=>'Kevin Garnett',          't'=>['MIN','BOS','BKN'],                         'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Paul Pierce',            't'=>['BOS','BKN','WAS','LAC','CLE'],             'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Ray Allen',              't'=>['MIL','SEA','BOS','MIA'],                   'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Allen Iverson',          't'=>['PHI','DEN','DET','MEM'],                   'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING','ROY']],
    ['n'=>'Vince Carter',           't'=>['TOR','NJN','ORL','PHX','DAL','OKC','MEM','ATL','SAC'],'c'=>'CAN','a'=>['ALLSTAR','ROY']],
    ['n'=>'Tracy McGrady',          't'=>['TOR','ORL','HOU','NYK','DET','ATL','SAS'], 'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Carmelo Anthony',        't'=>['DEN','NYK','OKC','HOU','POR','LAL'],       'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Russell Westbrook',      't'=>['OKC','HOU','WAS','LAL','LAC','UTA'],       'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING']],
    ['n'=>'James Harden',           't'=>['OKC','HOU','BKN','PHI','LAC'],             'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING']],
    ['n'=>'Derrick Rose',           't'=>['CHI','NYK','CLE','MIN','DET','MEM'],       'c'=>'USA','a'=>['MVP','ALLSTAR','ROY']],
    ['n'=>'Jimmy Butler',           't'=>['CHI','MIN','PHI','MIA','GSW'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Paul George',            't'=>['IND','OKC','LAC'],                         'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Kyrie Irving',           't'=>['CLE','BOS','BKN','DAL','MIA'],             'c'=>'USA','a'=>['CHAMPION','ALLSTAR','ROY']],
    ['n'=>'Damian Lillard',         't'=>['POR','MIL'],                               'c'=>'USA','a'=>['ALLSTAR','ROY','SCORING']],
    ['n'=>'Chris Paul',             't'=>['NOH','LAC','HOU','OKC','PHX','SAC','GSW'],'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Steve Nash',             't'=>['PHX','DAL','LAL'],                         'c'=>'CAN','a'=>['MVP','ALLSTAR']],
    ['n'=>'Jason Kidd',             't'=>['DAL','PHX','NJN','NYK'],                   'c'=>'USA','a'=>['CHAMPION','ALLSTAR','ROY']],
    ['n'=>'Pau Gasol',              't'=>['MEM','LAL','CHI','SAS','MIL','POR'],       'c'=>'ESP','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Rudy Gobert',            't'=>['UTA','MIN'],                               'c'=>'FRA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Nicolas Batum',          't'=>['POR','CHA','LAC','PHI'],                   'c'=>'FRA','a'=>[]],
    ['n'=>'Victor Wembanyama',      't'=>['SAS'],                                      'c'=>'FRA','a'=>['ROY']],
    ['n'=>'Boris Diaw',             't'=>['ATL','PHX','CHA','SAS','UTA'],             'c'=>'FRA','a'=>['CHAMPION','SIXTHMAN']],
    ['n'=>'Evan Fournier',          't'=>['DEN','ORL','BOS','NYK'],                   'c'=>'FRA','a'=>[]],
    ['n'=>'Andrew Wiggins',         't'=>['MIN','GSW'],                               'c'=>'CAN','a'=>['CHAMPION','ALLSTAR','ROY']],
    ['n'=>'Jamal Murray',           't'=>['DEN'],                                      'c'=>'CAN','a'=>['CHAMPION']],
    ['n'=>'Shai Gilgeous-Alexander','t'=>['LAC','OKC'],                                'c'=>'CAN','a'=>['ALLSTAR','SCORING']],
    ['n'=>'DeMar DeRozan',          't'=>['TOR','SAS','CHI'],                         'c'=>'CAN','a'=>['ALLSTAR']],
    ['n'=>'Nenê',                   't'=>['DEN','HOU','WAS'],                         'c'=>'BRA','a'=>['ALLSTAR']],
    ['n'=>'Anderson Varejão',       't'=>['CLE','POR','GSW'],                         'c'=>'BRA','a'=>[]],
    ['n'=>'Leandro Barbosa',        't'=>['PHX','TOR','IND','GSW','BOS'],             'c'=>'BRA','a'=>['SIXTHMAN']],
    ['n'=>'Tiago Splitter',         't'=>['SAS','ATL','SAC','PHX'],                   'c'=>'BRA','a'=>['CHAMPION']],
    ['n'=>'Luis Scola',             't'=>['HOU','PHX','TOR','IND','NJN'],             'c'=>'ARG','a'=>['ALLSTAR']],
    ['n'=>'Goran Dragic',           't'=>['PHX','MIA','TOR','BKN','CHI'],             'c'=>'SLO','a'=>['ALLSTAR']],
    ['n'=>'Nikola Vucevic',         't'=>['PHI','ORL','CHI'],                         'c'=>'SER','a'=>['ALLSTAR']],
    ['n'=>'Bogdan Bogdanovic',      't'=>['SAC','ATL','MIL'],                         'c'=>'SER','a'=>[]],
    ['n'=>'Vlade Divac',            't'=>['LAL','CHA','SAC'],                         'c'=>'SER','a'=>['ALLSTAR']],
    ['n'=>'Dennis Schroder',        't'=>['ATL','OKC','LAL','BOS','HOU','TOR'],       'c'=>'GER','a'=>['SIXTHMAN']],
    ['n'=>'Pascal Siakam',          't'=>['TOR','IND'],                               'c'=>'CAM','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Luc Mbah a Moute',       't'=>['MIL','MIN','PHI','LAC','HOU','SAC'],       'c'=>'CAM','a'=>[]],
    ['n'=>'Draymond Green',         't'=>['GSW'],                                      'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Klay Thompson',          't'=>['GSW','DAL'],                                'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Anthony Davis',          't'=>['NOP','LAL'],                               'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Jayson Tatum',           't'=>['BOS'],                                      'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Bam Adebayo',            't'=>['MIA'],                                      'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Donovan Mitchell',       't'=>['UTA','CLE'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Devin Booker',           't'=>['PHX'],                                      'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Hakeem Olajuwon',        't'=>['HOU','TOR'],                               'c'=>'NIG','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY']],
    ['n'=>'Charles Barkley',        't'=>['PHI','PHX','HOU'],                         'c'=>'USA','a'=>['MVP','ALLSTAR']],
    ['n'=>'Scottie Pippen',         't'=>['CHI','HOU','POR'],                         'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Dennis Rodman',          't'=>['DET','SAS','CHI','LAL','DAL'],             'c'=>'USA','a'=>['CHAMPION','DPOY']],
    ['n'=>'Khris Middleton',        't'=>['DET','MIL'],                               'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Jrue Holiday',           't'=>['PHI','NOP','MIL','BOS','POR'],             'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Trae Young',             't'=>['ATL'],                                      'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Karl-Anthony Towns',     't'=>['MIN','NYK'],                               'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Zion Williamson',        't'=>['NOP'],                                      'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Brook Lopez',            't'=>['NJN','LAL','BKN','MIL'],                   'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Kyle Lowry',             't'=>['MEM','HOU','TOR','MIA','PHI','MIN'],       'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Dwight Howard',          't'=>['ORL','LAL','HOU','ATL','CHA','WAS','PHI'],'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Rajon Rondo',            't'=>['BOS','DAL','SAC','CHI','NOP','LAL','ATL','LAC','CLE'],'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'DeMarcus Cousins',       't'=>['SAC','NOP','GSW','LAL','HOU','LAC'],       'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Andrei Kirilenko',       't'=>['UTA','MIN','BKN'],                         'c'=>'RUS','a'=>['ALLSTAR']],
    ['n'=>'Luol Deng',              't'=>['CHI','CLE','MIA','LAL','MIN'],             'c'=>'SSD','a'=>['ALLSTAR']],
    ['n'=>'Metta World Peace',      't'=>['CHI','IND','LAL','NYK','BOS','LAL'],       'c'=>'USA','a'=>['CHAMPION','DPOY']],
    ['n'=>'Derek Fisher',           't'=>['LAL','OKC','DAL','MEM','NYK'],             'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Reggie Miller',          't'=>['IND'],                                      'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Patrick Ewing',          't'=>['NYK','SEA','ORL','ATL'],                   'c'=>'USA','a'=>['ROY','ALLSTAR']],
    ['n'=>'Dikembe Mutombo',        't'=>['DEN','ATL','PHI','NJN','NYK','HOU'],       'c'=>'COD','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Grant Hill',             't'=>['DET','ORL','PHX','LAC'],                   'c'=>'USA','a'=>['ROY','ALLSTAR']],
    ['n'=>'Vince Carter',           't'=>['TOR','NJN','ORL','PHX','DAL','OKC'],      'c'=>'CAN','a'=>['ALLSTAR','ROY']],
];

// Remove duplicatas pelo nome
$seen = [];
$PLAYERS = array_values(array_filter($PLAYERS, function($p) use (&$seen) {
    if (isset($seen[$p['n']])) return false;
    $seen[$p['n']] = true;
    return true;
}));

$CRITERIA = [
    ['id'=>'LAL','type'=>'team',  'label'=>'Los Angeles Lakers',      'icon'=>'🟡'],
    ['id'=>'GSW','type'=>'team',  'label'=>'Golden State Warriors',   'icon'=>'💛'],
    ['id'=>'BOS','type'=>'team',  'label'=>'Boston Celtics',          'icon'=>'🍀'],
    ['id'=>'MIA','type'=>'team',  'label'=>'Miami Heat',              'icon'=>'🔴'],
    ['id'=>'SAS','type'=>'team',  'label'=>'San Antonio Spurs',       'icon'=>'⚫'],
    ['id'=>'CHI','type'=>'team',  'label'=>'Chicago Bulls',           'icon'=>'🐂'],
    ['id'=>'OKC','type'=>'team',  'label'=>'OKC Thunder',             'icon'=>'⚡'],
    ['id'=>'DEN','type'=>'team',  'label'=>'Denver Nuggets',          'icon'=>'⛏️'],
    ['id'=>'DAL','type'=>'team',  'label'=>'Dallas Mavericks',        'icon'=>'🐎'],
    ['id'=>'TOR','type'=>'team',  'label'=>'Toronto Raptors',         'icon'=>'🦖'],
    ['id'=>'PHI','type'=>'team',  'label'=>'Philadelphia 76ers',      'icon'=>'🔔'],
    ['id'=>'MIL','type'=>'team',  'label'=>'Milwaukee Bucks',         'icon'=>'🦌'],
    ['id'=>'HOU','type'=>'team',  'label'=>'Houston Rockets',         'icon'=>'🚀'],
    ['id'=>'PHX','type'=>'team',  'label'=>'Phoenix Suns',            'icon'=>'☀️'],
    ['id'=>'CLE','type'=>'team',  'label'=>'Cleveland Cavaliers',     'icon'=>'⚔️'],
    ['id'=>'USA','type'=>'nation','label'=>'Estados Unidos',          'icon'=>'🇺🇸'],
    ['id'=>'BRA','type'=>'nation','label'=>'Brasil',                  'icon'=>'🇧🇷'],
    ['id'=>'FRA','type'=>'nation','label'=>'França',                  'icon'=>'🇫🇷'],
    ['id'=>'ARG','type'=>'nation','label'=>'Argentina',               'icon'=>'🇦🇷'],
    ['id'=>'SLO','type'=>'nation','label'=>'Eslovênia',               'icon'=>'🇸🇮'],
    ['id'=>'SER','type'=>'nation','label'=>'Sérvia',                  'icon'=>'🇷🇸'],
    ['id'=>'GER','type'=>'nation','label'=>'Alemanha',                'icon'=>'🇩🇪'],
    ['id'=>'CAN','type'=>'nation','label'=>'Canadá',                  'icon'=>'🇨🇦'],
    ['id'=>'CAM','type'=>'nation','label'=>'Camarões',                'icon'=>'🇨🇲'],
    ['id'=>'GRE','type'=>'nation','label'=>'Grécia',                  'icon'=>'🇬🇷'],
    ['id'=>'MVP',       'type'=>'award','label'=>'MVP da Temporada',       'icon'=>'🏆'],
    ['id'=>'CHAMPION',  'type'=>'award','label'=>'Campeão NBA',            'icon'=>'💍'],
    ['id'=>'ALLSTAR',   'type'=>'award','label'=>'All-Star',               'icon'=>'⭐'],
    ['id'=>'DPOY',      'type'=>'award','label'=>'Melhor Defensor',        'icon'=>'🛡️'],
    ['id'=>'FINALS_MVP','type'=>'award','label'=>'MVP das Finais',         'icon'=>'🎖️'],
    ['id'=>'ROY',       'type'=>'award','label'=>'Calouro do Ano',         'icon'=>'🌟'],
    ['id'=>'SCORING',   'type'=>'award','label'=>'Artilheiro da Temporada','icon'=>'🎯'],
];

function playerMatchesCriteria(array $p, array $c): bool {
    if ($c['type'] === 'team')   return in_array($c['id'], $p['t'], true);
    if ($c['type'] === 'nation') return $p['c'] === $c['id'];
    if ($c['type'] === 'award')  return in_array($c['id'], $p['a'], true);
    return false;
}
function getValidPlayers(array $players, array $c1, array $c2): array {
    return array_values(array_filter($players, fn($p) => playerMatchesCriteria($p,$c1) && playerMatchesCriteria($p,$c2)));
}

function generateDailyGrid(array $players, array $allCriteria): array {
    $byId = array_column($allCriteria, null, 'id');
    for ($attempt = 0; $attempt < 3000; $attempt++) {
        $idx = [];
        $used = [];
        while (count($idx) < 6) {
            $pick = rand(0, count($allCriteria) - 1);
            if (!in_array($pick, $used, true)) { $used[] = $pick; $idx[] = $pick; }
        }
        $rows = [$allCriteria[$idx[0]], $allCriteria[$idx[1]], $allCriteria[$idx[2]]];
        $cols = [$allCriteria[$idx[3]], $allCriteria[$idx[4]], $allCriteria[$idx[5]]];

        // Evita nação × nação (impossível um jogador ter duas nações)
        $rowNations = count(array_filter($rows, fn($c) => $c['type'] === 'nation'));
        $colNations = count(array_filter($cols, fn($c) => $c['type'] === 'nation'));
        if ($rowNations > 0 && $colNations > 0) continue;

        $valid = true;
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                if (count(getValidPlayers($players, $r, $c)) === 0) { $valid = false; break 2; }
            }
        }
        if ($valid) return ['rows' => $rows, 'cols' => $cols];
    }
    // Fallback garantido (award × time)
    return [
        'rows' => [$byId['MVP'], $byId['CHAMPION'], $byId['ALLSTAR']],
        'cols' => [$byId['LAL'], $byId['GSW'],      $byId['BOS']],
    ];
}

srand((int)floor(time() / 86400));
$grid = generateDailyGrid($PLAYERS, $CRITERIA);

$validMap = [];
foreach ([0,1,2] as $r) {
    foreach ([0,1,2] as $c) {
        $key = "{$r}_{$c}";
        $validMap[$key] = array_map(fn($p) => mb_strtolower($p['n']), getValidPlayers($PLAYERS, $grid['rows'][$r], $grid['cols'][$c]));
    }
}

// --- AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    header('Content-Type: application/json');
    if ($action === 'save') {
        $respostas = json_encode(json_decode($_POST['respostas'] ?? '[]', true) ?: []);
        $concluido = (int)($_POST['concluido'] ?? 0);
        $desistiu  = (int)($_POST['desistiu'] ?? 0);
        $pontos    = (int)($_POST['pontos'] ?? 0);
        $hoje      = date('Y-m-d');
        try {
            $stmt = $pdo->prepare("SELECT id, pontos_ganhos FROM grade_historico WHERE id_usuario=? AND data_jogo=?");
            $stmt->execute([$user_id, $hoje]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $pdo->prepare("UPDATE grade_historico SET respostas=?,concluido=?,desistiu=?,pontos_ganhos=? WHERE id=?")
                    ->execute([$respostas,$concluido,$desistiu,$pontos,$row['id']]);
                if ($concluido && $row['pontos_ganhos'] == 0 && $pontos > 0)
                    $pdo->prepare("UPDATE usuarios SET pontos=pontos+? WHERE id=?")->execute([$pontos,$user_id]);
            } else {
                $pdo->prepare("INSERT INTO grade_historico (id_usuario,data_jogo,respostas,concluido,desistiu,pontos_ganhos) VALUES(?,?,?,?,?,?)")
                    ->execute([$user_id,$hoje,$respostas,$concluido,$desistiu,$pontos]);
                if ($concluido && $pontos > 0)
                    $pdo->prepare("UPDATE usuarios SET pontos=pontos+? WHERE id=?")->execute([$pontos,$user_id]);
            }
            echo json_encode(['ok'=>true]);
        } catch (PDOException $e) { echo json_encode(['ok'=>false]); }
        exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

$hoje      = date('Y-m-d');
$dadosHoje = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM grade_historico WHERE id_usuario=? AND data_jogo=?");
    $stmt->execute([$user_id, $hoje]);
    $dadosHoje = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$respostasIniciais = $dadosHoje ? (json_decode($dadosHoje['respostas'],true) ?: []) : [];
$jaTerminou = $dadosHoje && ($dadosHoje['concluido'] || $dadosHoje['desistiu']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Grade NBA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#07070a;--panel:#101013;--panel2:#16161a;--panel3:#1c1c21;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.13);
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);--red-glow:rgba(252,0,37,.25);
  --text:#f0f0f3;--text2:#868690;--text3:#3c3c44;
  --green:#22c55e;--amber:#f59e0b;--blue:#3b82f6;
  --radius:14px;--font:'Poppins',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;overflow-x:hidden}

/* TOP BAR */
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.topbar-left{display:flex;align-items:center;gap:10px}
.back-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;font-size:14px;transition:.2s;flex-shrink:0}
.back-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.game-title{font-size:14px;font-weight:800;color:var(--text)}
.game-title span{color:var(--red)}
.daily-badge{display:inline-flex;align-items:center;gap:4px;font-size:8px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;padding:2px 7px;border-radius:999px;background:var(--red-soft);border:1px solid var(--red-glow);color:var(--red);margin-left:6px}
.topbar-chips{display:flex;align-items:center;gap:6px}
.chip{display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:var(--panel2);border:1px solid var(--border);font-size:11px;font-weight:700;color:var(--text);white-space:nowrap}
.timer-chip.warn{border-color:var(--amber)!important;color:var(--amber)!important}
.timer-chip.danger{border-color:var(--red)!important;color:var(--red)!important}
.timer-chip.stopped{border-color:var(--border);color:var(--text3)}

/* LAYOUT */
.main{max-width:600px;margin:0 auto;padding:16px 12px 60px}

/* ── START MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(6px);z-index:200;display:flex;align-items:center;justify-content:center;padding:20px}
.modal-overlay.hidden{display:none}
.start-card{background:var(--panel);border:1px solid var(--border2);border-radius:20px;padding:32px 28px;max-width:420px;width:100%;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,.6)}
.start-icon{font-size:3.5rem;margin-bottom:16px;display:block}
.start-title{font-size:22px;font-weight:800;margin-bottom:6px}
.start-title span{color:var(--red)}
.start-desc{font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:20px}
.start-preview{display:grid;grid-template-columns:auto 1fr 1fr 1fr;gap:3px;margin-bottom:24px}
.sp-corner{background:var(--panel3);border-radius:6px}
.sp-col{background:var(--panel2);border-radius:6px;padding:7px 4px;text-align:center;font-size:9px;font-weight:700;color:var(--text2);display:flex;flex-direction:column;align-items:center;gap:2px}
.sp-col .sp-icon{font-size:1.1rem}
.sp-row{background:var(--panel2);border-radius:6px;padding:6px 8px;text-align:center;font-size:9px;font-weight:700;color:var(--text2);display:flex;flex-direction:column;align-items:center;gap:2px}
.sp-row .sp-icon{font-size:1rem}
.sp-cell{background:var(--panel3);border:1px solid var(--border);border-radius:6px;height:36px;display:flex;align-items:center;justify-content:center}
.sp-plus{font-size:1rem;color:var(--text3)}
.btn-start{width:100%;padding:14px;border-radius:12px;border:none;background:var(--red);color:#fff;font-family:var(--font);font-size:15px;font-weight:800;cursor:pointer;transition:.18s;display:flex;align-items:center;justify-content:center;gap:8px;letter-spacing:.3px}
.btn-start:hover{background:#e0001f;transform:translateY(-1px);box-shadow:0 6px 20px var(--red-glow)}
.timer-preview{display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:20px;font-size:13px;color:var(--text2)}
.timer-preview i{color:var(--amber)}

/* GRADE */
.grade-wrap{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.grade-table{display:grid;grid-template-columns:72px repeat(3,1fr);grid-template-rows:auto auto auto auto}

.corner-cell{background:var(--panel2);border-right:1px solid var(--border2);border-bottom:1px solid var(--border2);display:flex;align-items:center;justify-content:center;padding:10px;font-size:1.4rem}
.header-col{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;padding:10px 6px;background:var(--panel2);border-bottom:1px solid var(--border2);text-align:center;min-height:70px}
.header-row{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;padding:8px 6px;background:var(--panel2);border-right:1px solid var(--border2);text-align:center;min-height:80px}
.header-icon{font-size:1.2rem;line-height:1}
.header-type{font-size:7px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text3)}
.header-label{font-size:9px;font-weight:700;color:var(--text2);line-height:1.3;word-break:break-word}

.cell{display:flex;align-items:center;justify-content:center;min-height:80px;border:1px solid var(--border);cursor:pointer;position:relative;transition:.15s;background:var(--panel)}
.cell:hover:not(.filled):not(.disabled){background:var(--panel3);border-color:var(--border2)}
.cell.selected{background:var(--red-soft);border-color:var(--red)!important;box-shadow:inset 0 0 0 2px var(--red)}
.cell.filled{background:rgba(34,197,94,.07);border-color:rgba(34,197,94,.3)!important;cursor:default}
.cell.wrong{animation:shake .4s}
.cell-content{display:flex;flex-direction:column;align-items:center;gap:3px;padding:8px 4px;text-align:center;width:100%}
.cell-plus{font-size:1.4rem;color:var(--text3)}
.cell-player-name{font-size:9px;font-weight:700;color:var(--green);line-height:1.3;word-break:break-word;max-width:100%}
.cell-check{font-size:1rem}
@keyframes shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-5px)}40%{transform:translateX(5px)}60%{transform:translateX(-3px)}80%{transform:translateX(3px)}}

/* PROGRESS */
.progress-row{display:flex;align-items:center;gap:6px;padding:10px 14px;border-top:1px solid var(--border)}
.pdot{width:10px;height:10px;border-radius:50%;background:var(--border2);border:1px solid var(--text3);transition:.3s;flex-shrink:0}
.pdot.done{background:var(--green);border-color:var(--green)}
.progress-label{font-size:10px;color:var(--text3);margin-left:4px}

/* SEARCH */
.search-area{background:var(--panel2);border:1px solid var(--border2);border-radius:var(--radius);padding:14px;margin-top:12px}
.search-label{font-size:11px;font-weight:700;color:var(--text2);margin-bottom:8px;display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.search-label strong{color:var(--text)}
.search-label i{color:var(--red)}
.search-wrap{position:relative}
.search-input{width:100%;padding:10px 14px;border-radius:10px;border:1px solid var(--border2);background:var(--panel3);color:var(--text);font-family:var(--font);font-size:13px;font-weight:600;outline:none;transition:.18s}
.search-input:focus{border-color:var(--red)}
.search-input::placeholder{color:var(--text3)}
.autocomplete-list{position:absolute;left:0;right:0;top:calc(100% + 4px);background:var(--panel2);border:1px solid var(--border2);border-radius:10px;overflow:hidden;z-index:100;max-height:200px;overflow-y:auto;box-shadow:0 8px 24px rgba(0,0,0,.4)}
.ac-item{padding:9px 14px;font-size:12px;font-weight:600;cursor:pointer;transition:.12s;display:flex;align-items:center;gap:8px}
.ac-item:hover,.ac-item.active{background:var(--red-soft);color:var(--red)}
.search-actions{display:flex;gap:8px;margin-top:10px}

/* STATUS */
.status-banner{padding:12px 16px;border-radius:10px;font-size:12px;font-weight:700;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.status-banner.success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:var(--green)}
.status-banner.error{background:var(--red-soft);border:1px solid var(--red-glow);color:var(--red)}

/* RESULT */
.result-panel{background:var(--panel2);border:1px solid var(--border2);border-radius:var(--radius);padding:24px;text-align:center;margin-top:16px}
.result-icon{font-size:3rem;margin-bottom:10px}
.result-title{font-size:18px;font-weight:800;margin-bottom:6px}
.result-sub{font-size:12px;color:var(--text2);margin-bottom:16px;line-height:1.6}
.result-points{font-size:26px;font-weight:800;color:var(--amber)}
.result-points-label{font-size:10px;color:var(--text2);margin-bottom:20px}
.btn-ghost{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:9px;border:1px solid var(--border2);background:transparent;color:var(--text2);font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;transition:.18s}
.btn-ghost:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.btn-red{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:9px;border:none;background:var(--red);color:#fff;font-family:var(--font);font-size:12px;font-weight:700;cursor:pointer;transition:.18s}
.btn-red:hover{background:#e0001f}

@media(max-width:480px){
  .main{padding:12px 8px 50px}
  .grade-table{grid-template-columns:58px repeat(3,1fr)}
  .cell{min-height:64px}
  .header-col,.header-row{min-height:60px;padding:6px 4px}
  .header-icon{font-size:1rem}
  .header-label{font-size:8px}
  .corner-cell{font-size:1.1rem;padding:6px}
  .start-card{padding:24px 18px}
  .start-title{font-size:18px}
}
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
  <div class="topbar-left">
    <a href="../games.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
    <div class="game-title">Grade <span>NBA</span><span class="daily-badge"><i class="bi bi-calendar3"></i>Diário</span></div>
  </div>
  <div class="topbar-chips">
    <div class="chip timer-chip stopped" id="timerChip"><i class="bi bi-clock"></i><span id="timerDisplay">3:00</span></div>
    <div class="chip"><i class="bi bi-grid-3x3-gap" style="color:var(--green)"></i><span id="cellsCount">0</span>/9</div>
  </div>
</div>

<?php if (!$jaTerminou): ?>
<!-- ── START MODAL ── -->
<div class="modal-overlay" id="startModal">
  <div class="start-card">
    <span class="start-icon">🏀</span>
    <div class="start-title">Grade <span>NBA</span></div>
    <div class="start-desc">
      Preencha a grade 3×3 encontrando jogadores que se encaixam nos dois critérios de cada célula.<br>
      Cada jogador só pode ser usado uma vez.
    </div>

    <!-- Mini-preview da grade -->
    <div class="start-preview">
      <div class="sp-corner"></div>
      <?php foreach ($grid['cols'] as $col): ?>
      <div class="sp-col">
        <span class="sp-icon"><?= $col['icon'] ?></span>
        <span><?= htmlspecialchars(explode(' ',$col['label'])[0]) ?></span>
      </div>
      <?php endforeach; ?>
      <?php foreach ($grid['rows'] as $row): ?>
      <div class="sp-row">
        <span class="sp-icon"><?= $row['icon'] ?></span>
        <span><?= htmlspecialchars(explode(' ',$row['label'])[0]) ?></span>
      </div>
      <div class="sp-cell"><span class="sp-plus">+</span></div>
      <div class="sp-cell"><span class="sp-plus">+</span></div>
      <div class="sp-cell"><span class="sp-plus">+</span></div>
      <?php endforeach; ?>
    </div>

    <div class="timer-preview">
      <i class="bi bi-clock-fill"></i>
      O cronômetro de <strong>&nbsp;3 minutos&nbsp;</strong> começa ao clicar em Iniciar
    </div>

    <button class="btn-start" onclick="startGame()">
      <i class="bi bi-play-fill"></i> Iniciar Partida
    </button>
  </div>
</div>
<?php endif; ?>

<div class="main">

<?php if ($jaTerminou): ?>
<!-- Já terminou hoje -->
<div class="result-panel">
  <?php if ($dadosHoje['concluido']): ?>
    <div class="result-icon">🏆</div>
    <div class="result-title">Concluído hoje!</div>
    <div class="result-sub">Você completou a grade de hoje.<br>Volte amanhã para um novo desafio.</div>
    <div class="result-points"><?= number_format($dadosHoje['pontos_ganhos'],0,',','.') ?></div>
    <div class="result-points-label">moedas ganhas</div>
  <?php else: ?>
    <div class="result-icon">😔</div>
    <div class="result-title">Desistência registrada</div>
    <div class="result-sub">Volte amanhã para tentar novamente.</div>
  <?php endif; ?>
  <div style="margin-top:20px">
    <a href="../games.php" class="btn-ghost"><i class="bi bi-house"></i>Menu</a>
  </div>
</div>
<!-- Grade congelada -->
<div class="grade-wrap" style="margin-top:16px">
  <div class="grade-table">
    <div class="corner-cell">🏀</div>
    <?php foreach ($grid['cols'] as $col): ?>
    <div class="header-col">
      <span class="header-icon"><?= $col['icon'] ?></span>
      <span class="header-type"><?= $col['type']==='team'?'time':($col['type']==='nation'?'nação':'conquista') ?></span>
      <span class="header-label"><?= htmlspecialchars($col['label']) ?></span>
    </div>
    <?php endforeach; ?>
    <?php
      $respostasMap = [];
      foreach ($respostasIniciais as $r) $respostasMap[$r['cell']] = $r['player'];
    ?>
    <?php foreach ($grid['rows'] as $ri => $row): ?>
    <div class="header-row">
      <span class="header-icon"><?= $row['icon'] ?></span>
      <span class="header-type"><?= $row['type']==='team'?'time':($row['type']==='nation'?'nação':'conquista') ?></span>
      <span class="header-label"><?= htmlspecialchars($row['label']) ?></span>
    </div>
    <?php foreach ($grid['cols'] as $ci => $col): ?>
    <?php $ans = $respostasMap["{$ri}_{$ci}"] ?? null; ?>
    <div class="cell <?= $ans ? 'filled' : '' ?>">
      <div class="cell-content">
        <?php if ($ans): ?><span class="cell-check">✅</span><span class="cell-player-name"><?= htmlspecialchars($ans) ?></span>
        <?php else: ?><span class="cell-plus">—</span><?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
</div>

<?php else: ?>
<!-- JOGO ATIVO -->
<div id="statusMsg" style="display:none" class="status-banner"></div>

<div class="grade-wrap" id="gradeWrap">
  <div class="grade-table">
    <div class="corner-cell">🏀</div>
    <?php foreach ($grid['cols'] as $ci => $col): ?>
    <div class="header-col">
      <span class="header-icon"><?= $col['icon'] ?></span>
      <span class="header-type"><?= $col['type']==='team'?'time':($col['type']==='nation'?'nação':'conquista') ?></span>
      <span class="header-label"><?= htmlspecialchars($col['label']) ?></span>
    </div>
    <?php endforeach; ?>
    <?php foreach ($grid['rows'] as $ri => $row): ?>
    <div class="header-row">
      <span class="header-icon"><?= $row['icon'] ?></span>
      <span class="header-type"><?= $row['type']==='team'?'time':($row['type']==='nation'?'nação':'conquista') ?></span>
      <span class="header-label"><?= htmlspecialchars($row['label']) ?></span>
    </div>
    <?php foreach ($grid['cols'] as $ci => $col): ?>
    <div class="cell" id="cell_<?= $ri ?>_<?= $ci ?>" data-cell="<?= $ri ?>_<?= $ci ?>" onclick="selectCell('<?= $ri ?>_<?= $ci ?>')">
      <div class="cell-content"><span class="cell-plus">+</span></div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
  <div class="progress-row">
    <?php for ($r=0;$r<3;$r++) for ($c=0;$c<3;$c++): ?>
    <span id="pdot_<?= $r ?>_<?= $c ?>" class="pdot"></span>
    <?php endfor; ?>
    <span class="progress-label">Preencha os 9 quadrantes</span>
  </div>
</div>

<div class="search-area" id="searchArea" style="display:none">
  <div class="search-label"><i class="bi bi-search"></i>Jogador para: <strong id="searchForLabel"></strong></div>
  <div class="search-wrap">
    <input type="text" class="search-input" id="searchInput" placeholder="Digite o nome do jogador..." autocomplete="off" autocorrect="off" spellcheck="false">
    <div class="autocomplete-list" id="autocompleteList" style="display:none"></div>
  </div>
  <div class="search-actions">
    <button class="btn-ghost" onclick="cancelSelection()"><i class="bi bi-x"></i>Cancelar</button>
    <button class="btn-ghost" onclick="giveUp()"><i class="bi bi-flag"></i>Desistir</button>
  </div>
</div>

<div id="completedPanel" style="display:none" class="result-panel">
  <div class="result-icon">🏆</div>
  <div class="result-title">Grade completa!</div>
  <div class="result-sub" id="resultSubText"></div>
  <div class="result-points" id="resultPoints"></div>
  <div class="result-points-label">moedas ganhas</div>
  <div style="margin-top:20px"><a href="../games.php" class="btn-ghost"><i class="bi bi-house"></i>Menu</a></div>
</div>

<div id="gaveUpPanel" style="display:none" class="result-panel">
  <div class="result-icon">😔</div>
  <div class="result-title">Desistência</div>
  <div class="result-sub">Você preencheu <strong id="gaveUpCount">0</strong>/9 quadrantes.<br>Volte amanhã!</div>
  <div style="margin-top:20px"><a href="../games.php" class="btn-ghost"><i class="bi bi-house"></i>Menu</a></div>
</div>
<?php endif; ?>

</div><!-- /main -->

<script>
const VALID_MAP   = <?= json_encode($validMap) ?>;
const PLAYERS_ALL = <?= json_encode(array_map(fn($p)=>['n'=>$p['n'],'c'=>$p['c']],$PLAYERS)) ?>;
const PONTOS_VITORIA = <?= $PONTOS_VITORIA ?>;
const TIMER_SECONDS  = 3 * 60;
const NATION_FLAGS   = <?= json_encode(array_column(array_filter($CRITERIA,fn($c)=>$c['type']==='nation'),'icon','id')) ?>;
const GRID_ROWS = <?= json_encode(array_map(fn($r)=>$r['label'],$grid['rows'])) ?>;
const GRID_COLS = <?= json_encode(array_map(fn($c)=>$c['label'],$grid['cols'])) ?>;

let selectedCell  = null;
let answers       = {};
let timerInterval = null;
let secondsLeft   = TIMER_SECONDS;
let gameOver      = false;
let gameStarted   = false;
let usedPlayers   = new Set();

// Restaurar estado salvo
const initialAnswers = <?= json_encode($respostasIniciais) ?>;
initialAnswers.forEach(r => {
    answers[r.cell] = r.player;
    usedPlayers.add(r.player.toLowerCase());
    fillCell(r.cell, r.player);
});
updateCount();

// ── INICIAR JOGO (fechar modal e começar timer) ──
function startGame() {
    document.getElementById('startModal').classList.add('hidden');
    gameStarted = true;
    startTimer();
}

// ── TIMER ──
function startTimer() {
    const chip = document.getElementById('timerChip');
    chip.classList.remove('stopped');
    timerInterval = setInterval(() => {
        secondsLeft--;
        updateTimerDisplay();
        if (secondsLeft <= 0) { clearInterval(timerInterval); if (!gameOver) giveUp(); }
    }, 1000);
}

function updateTimerDisplay() {
    const m = Math.floor(secondsLeft / 60);
    const s = secondsLeft % 60;
    const chip = document.getElementById('timerChip');
    document.getElementById('timerDisplay').textContent = `${m}:${String(s).padStart(2,'0')}`;
    chip.className = 'chip timer-chip' + (secondsLeft <= 30 ? ' danger' : secondsLeft <= 60 ? ' warn' : '');
}

// ── SELEÇÃO ──
function selectCell(cellId) {
    if (gameOver || !gameStarted) return;
    if (answers[cellId]) return;
    selectedCell = cellId;
    document.querySelectorAll('.cell').forEach(c => c.classList.remove('selected'));
    document.getElementById('cell_' + cellId).classList.add('selected');
    const [ri, ci] = cellId.split('_').map(Number);
    document.getElementById('searchForLabel').textContent = GRID_ROWS[ri] + ' × ' + GRID_COLS[ci];
    document.getElementById('searchArea').style.display = 'block';
    document.getElementById('searchInput').value = '';
    document.getElementById('autocompleteList').style.display = 'none';
    setTimeout(() => document.getElementById('searchInput').focus(), 50);
}

function cancelSelection() {
    selectedCell = null;
    document.querySelectorAll('.cell').forEach(c => c.classList.remove('selected'));
    document.getElementById('searchArea').style.display = 'none';
    document.getElementById('autocompleteList').style.display = 'none';
}

// ── AUTOCOMPLETE ──
document.getElementById('searchInput').addEventListener('input', function() {
    const q = this.value.trim();
    const list = document.getElementById('autocompleteList');
    if (q.length < 2) { list.style.display = 'none'; return; }
    const matches = PLAYERS_ALL.filter(p => {
        if (usedPlayers.has(p.n.toLowerCase())) return false;
        return norm(p.n).includes(norm(q));
    }).slice(0, 8);
    if (!matches.length) { list.style.display = 'none'; return; }
    list.innerHTML = matches.map(p =>
        `<div class="ac-item" onclick="submitPlayer('${esc(p.n)}')">${NATION_FLAGS[p.c]||''} <span>${esc(p.n)}</span></div>`
    ).join('');
    list.style.display = 'block';
});

document.getElementById('searchInput').addEventListener('keydown', function(e) {
    const items = document.querySelectorAll('.ac-item');
    let active = document.querySelector('.ac-item.active');
    if (e.key==='ArrowDown') { e.preventDefault(); active ? (active.classList.remove('active'),(active.nextElementSibling||items[0]).classList.add('active')) : items[0]?.classList.add('active'); }
    else if (e.key==='ArrowUp') { e.preventDefault(); active ? (active.classList.remove('active'),(active.previousElementSibling||items[items.length-1]).classList.add('active')) : items[items.length-1]?.classList.add('active'); }
    else if (e.key==='Enter') { e.preventDefault(); (active||items.length===1?active||items[0]:null)?.click(); }
    else if (e.key==='Escape') cancelSelection();
});

document.addEventListener('click', e => {
    if (!e.target.closest('.search-wrap') && !e.target.closest('.cell'))
        document.getElementById('autocompleteList').style.display = 'none';
});

// ── SUBMETER ──
function submitPlayer(name) {
    if (!selectedCell) return;
    const valid = VALID_MAP[selectedCell] || [];
    const lower = name.toLowerCase();
    if (!valid.includes(lower)) {
        flashCell(selectedCell, 'wrong');
        showStatus('❌ ' + name + ' não atende aos dois critérios desta célula.', 'error');
        document.getElementById('autocompleteList').style.display = 'none';
        document.getElementById('searchInput').value = '';
        return;
    }
    if (usedPlayers.has(lower)) { showStatus('⚠️ ' + name + ' já foi usado.', 'error'); return; }
    answers[selectedCell] = name;
    usedPlayers.add(lower);
    fillCell(selectedCell, name);
    cancelSelection();
    updateCount();
    saveProgress();
    if (Object.keys(answers).length >= 9) setTimeout(() => endGame(true), 400);
}

function fillCell(cellId, playerName) {
    const cell = document.getElementById('cell_' + cellId);
    if (!cell) return;
    cell.classList.add('filled');
    cell.classList.remove('selected','wrong');
    cell.innerHTML = `<div class="cell-content"><span class="cell-check">✅</span><span class="cell-player-name">${esc(playerName)}</span></div>`;
    const dot = document.getElementById('pdot_' + cellId);
    if (dot) dot.classList.add('done');
}

function flashCell(cellId, cls) {
    const cell = document.getElementById('cell_' + cellId);
    if (!cell) return;
    cell.classList.add(cls);
    setTimeout(() => cell.classList.remove(cls), 600);
}

function updateCount() {
    document.getElementById('cellsCount').textContent = Object.keys(answers).length;
}

function showStatus(msg, type) {
    const el = document.getElementById('statusMsg');
    if (!el) return;
    el.className = 'status-banner ' + type;
    el.innerHTML = msg;
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(() => el.style.display = 'none', 3000);
}

// ── FIM ──
function endGame(won) {
    gameOver = true;
    clearInterval(timerInterval);
    document.getElementById('searchArea').style.display = 'none';
    document.querySelectorAll('.cell').forEach(c => c.classList.remove('selected'));
    const filled = Object.keys(answers).length;
    const timeBonus = won ? Math.floor(secondsLeft / 10) * 5 : 0;
    const totalPts  = won ? PONTOS_VITORIA + timeBonus : 0;
    saveProgress(won, !won, totalPts);
    if (won) {
        document.getElementById('resultPoints').textContent = '+' + totalPts.toLocaleString('pt-BR');
        document.getElementById('resultSubText').textContent =
            `Tempo restante: ${Math.floor(secondsLeft/60)}:${String(secondsLeft%60).padStart(2,'0')} • Bônus de tempo +${timeBonus}`;
        document.getElementById('completedPanel').style.display = 'block';
        document.getElementById('gradeWrap').style.opacity = '.5';
    } else {
        document.getElementById('gaveUpCount').textContent = filled;
        document.getElementById('gaveUpPanel').style.display = 'block';
    }
}

function giveUp() {
    if (gameOver) return;
    if (!confirm('Deseja realmente desistir? Você não poderá jogar novamente hoje.')) return;
    endGame(false);
}

function saveProgress(concluido=false, desistiu=false, pontos=0) {
    const payload = new FormData();
    payload.append('action',    'save');
    payload.append('respostas', JSON.stringify(Object.entries(answers).map(([cell,player])=>({cell,player}))));
    payload.append('concluido', concluido ? 1 : 0);
    payload.append('desistiu',  desistiu ? 1 : 0);
    payload.append('pontos',    pontos);
    fetch(location.href, {method:'POST',body:payload}).catch(()=>{});
}

function norm(s){ return s.normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase(); }
function esc(s){ return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
</script>
</body>
</html>
