<?php
session_start();
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php"); exit;
}
$user_id = (int)$_SESSION['user_id'];
$PONTOS_VITORIA = 150 * getGamePointsMultiplier($pdo, 'grade');

// --- Criar tabela ---
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

// --- Dataset de jogadores ---
$PLAYERS = [
    ['n'=>'LeBron James',           't'=>['CLE','MIA','LAL'],                    'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Stephen Curry',          't'=>['GSW'],                                 'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Kevin Durant',           't'=>['OKC','GSW','BKN','PHX'],              'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','SCORING']],
    ['n'=>'Giannis Antetokounmpo',  't'=>['MIL'],                                 'c'=>'GRE','a'=>['MVP','CHAMPION','ALLSTAR','DPOY','FINALS_MVP']],
    ['n'=>'Nikola Jokic',           't'=>['DEN'],                                 'c'=>'SER','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Luka Doncic',            't'=>['DAL','LAL'],                           'c'=>'SLO','a'=>['ALLSTAR','ROY']],
    ['n'=>'Joel Embiid',            't'=>['PHI','LAL'],                           'c'=>'CAM','a'=>['MVP','ALLSTAR','SCORING']],
    ['n'=>'Kawhi Leonard',          't'=>['SAS','TOR','LAC'],                     'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY','FINALS_MVP']],
    ['n'=>'Kobe Bryant',            't'=>['LAL'],                                 'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','SCORING']],
    ['n'=>'Shaquille O\'Neal',      't'=>['LAL','MIA','PHO','CLE','BOS'],        'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Dwyane Wade',            't'=>['MIA','CHI','CLE'],                     'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP','SCORING']],
    ['n'=>'Chris Bosh',             't'=>['TOR','MIA'],                           'c'=>'CAN','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Dirk Nowitzki',          't'=>['DAL'],                                 'c'=>'GER','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Tim Duncan',             't'=>['SAS'],                                 'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY']],
    ['n'=>'Tony Parker',            't'=>['SAS','CHA'],                           'c'=>'FRA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Manu Ginobili',          't'=>['SAS'],                                 'c'=>'ARG','a'=>['CHAMPION','ALLSTAR','SIXTHMAN']],
    ['n'=>'Kevin Garnett',          't'=>['MIN','BOS','BKN'],                    'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Paul Pierce',            't'=>['BOS','BKN','WAS','LAC','CLE'],        'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Ray Allen',              't'=>['MIL','SEA','BOS','MIA'],              'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Allen Iverson',          't'=>['PHI','DEN','DET','MEM'],              'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING','ROY']],
    ['n'=>'Vince Carter',           't'=>['TOR','NJN','ORL','PHX','DAL','OKC','MEM','ATL','SAC'],'c'=>'CAN','a'=>['ALLSTAR','ROY']],
    ['n'=>'Tracy McGrady',          't'=>['TOR','ORL','HOU','NYK','DET','ATL','SAS'],'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Carmelo Anthony',        't'=>['DEN','NYK','OKC','HOU','POR','LAL'],  'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Russell Westbrook',      't'=>['OKC','HOU','WAS','LAL','LAC','UTA'],  'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING']],
    ['n'=>'James Harden',           't'=>['OKC','HOU','BKN','PHI','LAC'],        'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING']],
    ['n'=>'Derrick Rose',           't'=>['CHI','NYK','CLE','MIN','DET','MEM'],  'c'=>'USA','a'=>['MVP','ALLSTAR','ROY']],
    ['n'=>'Jimmy Butler',           't'=>['CHI','MIN','PHI','MIA','GSW'],        'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Paul George',            't'=>['IND','OKC','LAC'],                    'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Kyrie Irving',           't'=>['CLE','BOS','BKN','DAL','MIA'],        'c'=>'USA','a'=>['CHAMPION','ALLSTAR','ROY']],
    ['n'=>'Damian Lillard',         't'=>['POR','MIL'],                          'c'=>'USA','a'=>['ALLSTAR','ROY','SCORING']],
    ['n'=>'Chris Paul',             't'=>['NOH','LAC','HOU','OKC','PHX','SAC','GSW'],'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Steve Nash',             't'=>['PHX','DAL','LAL'],                    'c'=>'CAN','a'=>['MVP','ALLSTAR']],
    ['n'=>'Jason Kidd',             't'=>['DAL','PHX','NJN','NYK'],              'c'=>'USA','a'=>['CHAMPION','ALLSTAR','ROY']],
    ['n'=>'Pau Gasol',              't'=>['MEM','LAL','CHI','SAS','MIL','POR'],  'c'=>'ESP','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Rudy Gobert',            't'=>['UTA','MIN'],                          'c'=>'FRA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Nicolas Batum',          't'=>['POR','CHA','LAC','PHI'],              'c'=>'FRA','a'=>[]],
    ['n'=>'Victor Wembanyama',      't'=>['SAS'],                                'c'=>'FRA','a'=>['ROY']],
    ['n'=>'Boris Diaw',             't'=>['ATL','PHX','CHA','SAS','UTA'],        'c'=>'FRA','a'=>['CHAMPION','SIXTHMAN']],
    ['n'=>'Evan Fournier',          't'=>['DEN','ORL','BOS','NYK'],              'c'=>'FRA','a'=>[]],
    ['n'=>'Andrew Wiggins',         't'=>['MIN','GSW'],                          'c'=>'CAN','a'=>['CHAMPION','ALLSTAR','ROY']],
    ['n'=>'Jamal Murray',           't'=>['DEN'],                                'c'=>'CAN','a'=>['CHAMPION']],
    ['n'=>'Shai Gilgeous-Alexander','t'=>['LAC','OKC'],                          'c'=>'CAN','a'=>['ALLSTAR','SCORING']],
    ['n'=>'DeMar DeRozan',          't'=>['TOR','SAS','CHI'],                    'c'=>'CAN','a'=>['ALLSTAR']],
    ['n'=>'Nenê',                   't'=>['DEN','HOU','WAS'],                    'c'=>'BRA','a'=>['ALLSTAR']],
    ['n'=>'Anderson Varejão',       't'=>['CLE','POR','GSW'],                    'c'=>'BRA','a'=>[]],
    ['n'=>'Leandro Barbosa',        't'=>['PHX','TOR','IND','GSW','BOS'],        'c'=>'BRA','a'=>['SIXTHMAN']],
    ['n'=>'Tiago Splitter',         't'=>['SAS','ATL','SAC','PHX'],              'c'=>'BRA','a'=>['CHAMPION']],
    ['n'=>'Luis Scola',             't'=>['HOU','PHX','TOR','IND','NJN'],        'c'=>'ARG','a'=>['ALLSTAR']],
    ['n'=>'Goran Dragic',           't'=>['PHX','MIA','TOR','BKN','CHI'],        'c'=>'SLO','a'=>['ALLSTAR']],
    ['n'=>'Nikola Vucevic',         't'=>['PHI','ORL','CHI'],                    'c'=>'SER','a'=>['ALLSTAR']],
    ['n'=>'Bogdan Bogdanovic',      't'=>['SAC','ATL','MIL'],                    'c'=>'SER','a'=>[]],
    ['n'=>'Vlade Divac',            't'=>['LAL','CHA','SAC'],                    'c'=>'SER','a'=>['ALLSTAR']],
    ['n'=>'Dennis Schroder',        't'=>['ATL','OKC','LAL','BOS','HOU','TOR'],  'c'=>'GER','a'=>['SIXTHMAN']],
    ['n'=>'Pascal Siakam',          't'=>['TOR','IND'],                          'c'=>'CAM','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Luc Mbah a Moute',       't'=>['MIL','MIN','PHI','LAC','HOU','SAC'],  'c'=>'CAM','a'=>[]],
    ['n'=>'Draymond Green',         't'=>['GSW'],                                'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Klay Thompson',          't'=>['GSW','DAL'],                          'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Anthony Davis',          't'=>['NOP','LAL'],                          'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Jayson Tatum',           't'=>['BOS'],                                'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Bam Adebayo',            't'=>['MIA'],                                'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Donovan Mitchell',       't'=>['UTA','CLE'],                          'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Devin Booker',           't'=>['PHX'],                                'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Hakeem Olajuwon',        't'=>['HOU','TOR'],                          'c'=>'NIG','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY']],
    ['n'=>'Charles Barkley',        't'=>['PHI','PHX','HOU'],                   'c'=>'USA','a'=>['MVP','ALLSTAR']],
    ['n'=>'Scottie Pippen',         't'=>['CHI','HOU','POR'],                   'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Dennis Rodman',          't'=>['DET','SAS','CHI','LAL','DAL'],        'c'=>'USA','a'=>['CHAMPION','DPOY']],
    ['n'=>'Khris Middleton',        't'=>['DET','MIL'],                          'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Jrue Holiday',           't'=>['PHI','NOP','MIL','BOS','POR'],        'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Trae Young',             't'=>['ATL'],                                'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Karl-Anthony Towns',     't'=>['MIN','NYK'],                          'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Zion Williamson',        't'=>['NOP'],                                'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Brook Lopez',            't'=>['NJN','LAL','BKN','MIL'],             'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Kyle Lowry',             't'=>['MEM','HOU','TOR','MIA','PHI','MIN'], 'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'DeMarcus Cousins',       't'=>['SAC','NOP','GSW','LAL','HOU','LAC'], 'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Dwight Howard',          't'=>['ORL','LAL','HOU','ATL','CHA','WAS','PHI','MEM'], 'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Rajon Rondo',            't'=>['BOS','DAL','SAC','CHI','NOP','LAL','ATL','LAC','CLE'],'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Andrei Kirilenko',       't'=>['UTA','MIN','BKN'],                    'c'=>'RUS','a'=>['ALLSTAR']],
];

// --- Critérios disponíveis ---
$CRITERIA = [
    // Times
    ['id'=>'LAL','type'=>'team','label'=>'Los Angeles Lakers',     'icon'=>'🟡'],
    ['id'=>'GSW','type'=>'team','label'=>'Golden State Warriors',  'icon'=>'💛'],
    ['id'=>'BOS','type'=>'team','label'=>'Boston Celtics',         'icon'=>'🍀'],
    ['id'=>'MIA','type'=>'team','label'=>'Miami Heat',             'icon'=>'🔴'],
    ['id'=>'SAS','type'=>'team','label'=>'San Antonio Spurs',      'icon'=>'⚫'],
    ['id'=>'CHI','type'=>'team','label'=>'Chicago Bulls',          'icon'=>'🐂'],
    ['id'=>'OKC','type'=>'team','label'=>'OKC Thunder',            'icon'=>'⚡'],
    ['id'=>'DEN','type'=>'team','label'=>'Denver Nuggets',         'icon'=>'⛏️'],
    ['id'=>'DAL','type'=>'team','label'=>'Dallas Mavericks',       'icon'=>'🐎'],
    ['id'=>'TOR','type'=>'team','label'=>'Toronto Raptors',        'icon'=>'🦖'],
    ['id'=>'PHI','type'=>'team','label'=>'Philadelphia 76ers',     'icon'=>'🔔'],
    ['id'=>'MIL','type'=>'team','label'=>'Milwaukee Bucks',        'icon'=>'🦌'],
    ['id'=>'HOU','type'=>'team','label'=>'Houston Rockets',        'icon'=>'🚀'],
    ['id'=>'PHX','type'=>'team','label'=>'Phoenix Suns',           'icon'=>'☀️'],
    ['id'=>'CLE','type'=>'team','label'=>'Cleveland Cavaliers',    'icon'=>'⚔️'],
    // Nações
    ['id'=>'USA','type'=>'nation','label'=>'Estados Unidos',       'icon'=>'🇺🇸'],
    ['id'=>'BRA','type'=>'nation','label'=>'Brasil',               'icon'=>'🇧🇷'],
    ['id'=>'FRA','type'=>'nation','label'=>'França',               'icon'=>'🇫🇷'],
    ['id'=>'ARG','type'=>'nation','label'=>'Argentina',            'icon'=>'🇦🇷'],
    ['id'=>'SLO','type'=>'nation','label'=>'Eslovênia',            'icon'=>'🇸🇮'],
    ['id'=>'SER','type'=>'nation','label'=>'Sérvia',               'icon'=>'🇷🇸'],
    ['id'=>'GER','type'=>'nation','label'=>'Alemanha',             'icon'=>'🇩🇪'],
    ['id'=>'CAN','type'=>'nation','label'=>'Canadá',               'icon'=>'🇨🇦'],
    ['id'=>'CAM','type'=>'nation','label'=>'Camarões',             'icon'=>'🇨🇲'],
    ['id'=>'GRE','type'=>'nation','label'=>'Grécia',               'icon'=>'🇬🇷'],
    // Conquistas
    ['id'=>'MVP',      'type'=>'award','label'=>'MVP da Temporada',     'icon'=>'🏆'],
    ['id'=>'CHAMPION', 'type'=>'award','label'=>'Campeão NBA',          'icon'=>'💍'],
    ['id'=>'ALLSTAR',  'type'=>'award','label'=>'All-Star',             'icon'=>'⭐'],
    ['id'=>'DPOY',     'type'=>'award','label'=>'Melhor Defensor',      'icon'=>'🛡️'],
    ['id'=>'FINALS_MVP','type'=>'award','label'=>'MVP das Finais',      'icon'=>'🎖️'],
    ['id'=>'ROY',      'type'=>'award','label'=>'Calouro do Ano',       'icon'=>'🌟'],
    ['id'=>'SCORING',  'type'=>'award','label'=>'Artilheiro da Temporada','icon'=>'🎯'],
];

function playerMatchesCriteria(array $p, array $c): bool {
    if ($c['type'] === 'team')   return in_array($c['id'], $p['t'], true);
    if ($c['type'] === 'nation') return $p['c'] === $c['id'];
    if ($c['type'] === 'award')  return in_array($c['id'], $p['a'], true);
    return false;
}

function getValidPlayers(array $players, array $c1, array $c2): array {
    return array_values(array_filter($players, fn($p) => playerMatchesCriteria($p, $c1) && playerMatchesCriteria($p, $c2)));
}

function generateDailyGrid(array $players, array $allCriteria): array {
    // Tenta até 500 combinações com a seed do dia para achar uma grade válida
    $byId = [];
    foreach ($allCriteria as $c) $byId[$c['id']] = $c;

    for ($attempt = 0; $attempt < 500; $attempt++) {
        $idx = [];
        $used = [];
        while (count($idx) < 4) {
            $pick = rand(0, count($allCriteria) - 1);
            if (!in_array($pick, $used, true)) { $used[] = $pick; $idx[] = $pick; }
        }
        $row0 = $allCriteria[$idx[0]];
        $row1 = $allCriteria[$idx[1]];
        $col0 = $allCriteria[$idx[2]];
        $col1 = $allCriteria[$idx[3]];
        if (
            count(getValidPlayers($players, $row0, $col0)) > 0 &&
            count(getValidPlayers($players, $row0, $col1)) > 0 &&
            count(getValidPlayers($players, $row1, $col0)) > 0 &&
            count(getValidPlayers($players, $row1, $col1)) > 0
        ) {
            return ['rows'=>[$row0,$row1],'cols'=>[$col0,$col1]];
        }
    }
    // Fallback garantido
    return [
        'rows' => [$byId['LAL'],$byId['BOS']],
        'cols' => [$byId['CHAMPION'],$byId['ALLSTAR']],
    ];
}

// Seed diário — mesma grade para todos os usuários no mesmo dia
srand((int)floor(time() / 86400));
$grid = generateDailyGrid($PLAYERS, $CRITERIA);

// Montar mapa de respostas válidas por célula (para validação JS)
$validMap = [];
foreach ([0,1] as $r) {
    foreach ([0,1] as $c) {
        $key = "{$r}_{$c}";
        $valids = getValidPlayers($PLAYERS, $grid['rows'][$r], $grid['cols'][$c]);
        $validMap[$key] = array_map(fn($p) => mb_strtolower($p['n']), $valids);
    }
}

// --- AJAX handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    header('Content-Type: application/json');

    if ($action === 'save') {
        $respostas  = json_encode(json_decode($_POST['respostas'] ?? '[]', true) ?: []);
        $concluido  = (int)($_POST['concluido'] ?? 0);
        $desistiu   = (int)($_POST['desistiu'] ?? 0);
        $pontos     = (int)($_POST['pontos'] ?? 0);
        $hoje       = date('Y-m-d');
        try {
            $stmt = $pdo->prepare("SELECT id, pontos_ganhos FROM grade_historico WHERE id_usuario=? AND data_jogo=?");
            $stmt->execute([$user_id, $hoje]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $pdo->prepare("UPDATE grade_historico SET respostas=?,concluido=?,desistiu=?,pontos_ganhos=? WHERE id=?")
                    ->execute([$respostas,$concluido,$desistiu,$pontos,$row['id']]);
                // Dar pontos só se ainda não deu e ganhou
                if ($concluido && $row['pontos_ganhos'] == 0 && $pontos > 0) {
                    $pdo->prepare("UPDATE usuarios SET pontos=pontos+? WHERE id=?")->execute([$pontos,$user_id]);
                }
            } else {
                $pdo->prepare("INSERT INTO grade_historico (id_usuario,data_jogo,respostas,concluido,desistiu,pontos_ganhos) VALUES(?,?,?,?,?,?)")
                    ->execute([$user_id,$hoje,$respostas,$concluido,$desistiu,$pontos]);
                if ($concluido && $pontos > 0) {
                    $pdo->prepare("UPDATE usuarios SET pontos=pontos+? WHERE id=?")->execute([$pontos,$user_id]);
                }
            }
            echo json_encode(['ok'=>true]);
        } catch (PDOException $e) {
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        }
        exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

// --- Carregar estado do dia ---
$hoje       = date('Y-m-d');
$dadosHoje  = null;
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
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased}

/* TOP BAR */
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 20px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.topbar-left{display:flex;align-items:center;gap:10px}
.back-btn{display:flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:10px;border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;transition:.2s}
.back-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.game-title{font-size:15px;font-weight:800;color:var(--text)}
.game-title span{color:var(--red)}
.topbar-chips{display:flex;align-items:center;gap:8px}
.chip{display:flex;align-items:center;gap:5px;padding:5px 10px;border-radius:20px;background:var(--panel2);border:1px solid var(--border);font-size:12px;font-weight:700;color:var(--text)}
.chip i{font-size:12px}
.timer-chip{border-color:var(--border2)}
.timer-chip.warn{border-color:var(--amber);color:var(--amber)}
.timer-chip.danger{border-color:var(--red);color:var(--red)}

/* LAYOUT */
.main{max-width:520px;margin:0 auto;padding:24px 16px 60px}

/* GRADE */
.grade-wrap{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.grade-table{display:grid;grid-template-columns:1fr 1fr 1fr;grid-template-rows:auto auto auto}

.corner-cell{background:var(--panel);border-right:1px solid var(--border2);border-bottom:1px solid var(--border2)}
.header-col{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;padding:14px 10px;background:var(--panel2);border-bottom:1px solid var(--border2);text-align:center}
.header-col:first-of-type{border-left:none}
.header-row{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;padding:10px 12px;background:var(--panel2);border-right:1px solid var(--border2);text-align:center}
.header-icon{font-size:1.4rem;line-height:1}
.header-type{font-size:8px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text3)}
.header-label{font-size:10px;font-weight:700;color:var(--text2);line-height:1.3}
.header-label.team{font-size:9px}

.cell{display:flex;align-items:center;justify-content:center;min-height:100px;border:1px solid var(--border);cursor:pointer;position:relative;transition:.18s;background:var(--panel)}
.cell:hover:not(.filled):not(.disabled){background:var(--panel3);border-color:var(--border2)}
.cell.selected{background:var(--red-soft);border-color:var(--red)!important;box-shadow:inset 0 0 0 2px var(--red)}
.cell.filled{background:rgba(34,197,94,.08);border-color:rgba(34,197,94,.35)!important;cursor:default}
.cell.wrong{animation:shake .4s}
.cell-content{display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 6px;text-align:center}
.cell-plus{font-size:1.6rem;color:var(--text3)}
.cell-player-name{font-size:10px;font-weight:700;color:var(--green);line-height:1.3}
.cell-check{font-size:1.2rem;color:var(--green)}

@keyframes shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-6px)}40%{transform:translateX(6px)}60%{transform:translateX(-4px)}80%{transform:translateX(4px)}}

/* SEARCH */
.search-area{padding:16px}
.search-label{font-size:11px;font-weight:700;color:var(--text2);margin-bottom:8px;display:flex;align-items:center;gap:6px}
.search-label i{color:var(--red)}
.search-wrap{position:relative}
.search-input{width:100%;padding:11px 14px;border-radius:10px;border:1px solid var(--border2);background:var(--panel3);color:var(--text);font-family:var(--font);font-size:13px;font-weight:600;outline:none;transition:.18s}
.search-input:focus{border-color:var(--red);background:var(--panel2)}
.search-input::placeholder{color:var(--text3)}
.autocomplete-list{position:absolute;left:0;right:0;top:calc(100% + 4px);background:var(--panel2);border:1px solid var(--border2);border-radius:10px;overflow:hidden;z-index:100;max-height:200px;overflow-y:auto}
.ac-item{padding:10px 14px;font-size:12px;font-weight:600;cursor:pointer;transition:.15s;display:flex;align-items:center;gap:8px}
.ac-item:hover,.ac-item.active{background:var(--red-soft);color:var(--red)}
.ac-flag{font-size:14px}

/* STATUS MESSAGES */
.status-banner{padding:14px 18px;border-radius:10px;font-size:13px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:10px}
.status-banner.success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:var(--green)}
.status-banner.error{background:var(--red-soft);border:1px solid var(--red-glow);color:var(--red)}
.status-banner.info{background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.3);color:var(--blue)}

/* RESULT PANEL */
.result-panel{background:var(--panel2);border:1px solid var(--border2);border-radius:var(--radius);padding:24px;text-align:center;margin-top:20px}
.result-icon{font-size:3rem;margin-bottom:12px}
.result-title{font-size:20px;font-weight:800;margin-bottom:8px}
.result-sub{font-size:13px;color:var(--text2);margin-bottom:20px}
.result-points{font-size:28px;font-weight:800;color:var(--amber);margin-bottom:4px}
.result-points-label{font-size:11px;color:var(--text2)}

/* BUTTONS */
.btn-red{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:10px;border:none;background:var(--red);color:#fff;font-family:var(--font);font-size:13px;font-weight:700;cursor:pointer;transition:.18s}
.btn-red:hover{background:#e0001f;transform:translateY(-1px)}
.btn-ghost{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:10px;border:1px solid var(--border2);background:transparent;color:var(--text2);font-family:var(--font);font-size:12px;font-weight:600;cursor:pointer;transition:.18s}
.btn-ghost:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}

/* HINT ROW */
.hint-row{display:flex;align-items:center;justify-content:center;gap:6px;padding:10px 16px;border-top:1px solid var(--border);font-size:11px;color:var(--text3)}
.hint-dot{width:8px;height:8px;border-radius:50%;background:var(--text3)}
.hint-dot.green{background:var(--green)}
.hint-dot.empty{background:var(--border2);border:1px solid var(--text3)}

/* DAILY BADGE */
.daily-badge{display:inline-flex;align-items:center;gap:5px;font-size:9px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;padding:3px 8px;border-radius:999px;background:var(--red-soft);border:1px solid var(--red-glow);color:var(--red);margin-left:8px}

@media(max-width:480px){
  .main{padding:16px 10px 50px}
  .header-label{font-size:9px}
  .cell{min-height:80px}
  .header-icon{font-size:1.1rem}
}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <a href="../games.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
    <div class="game-title">Grade <span>NBA</span><span class="daily-badge"><i class="bi bi-calendar3"></i>Diário</span></div>
  </div>
  <div class="topbar-chips">
    <div class="chip timer-chip" id="timerChip"><i class="bi bi-clock"></i><span id="timerDisplay">3:00</span></div>
    <div class="chip"><i class="bi bi-grid-3x3-gap" style="color:var(--green)"></i><span id="cellsCount">0</span>/4</div>
  </div>
</div>

<div class="main">

<?php if ($jaTerminou): ?>
<!-- Estado restaurado do banco -->
<div id="resultPanel" class="result-panel">
  <?php if ($dadosHoje['concluido']): ?>
    <div class="result-icon">🏆</div>
    <div class="result-title">Você completou hoje!</div>
    <div class="result-sub">Volte amanhã para um novo desafio.</div>
    <div class="result-points"><?= number_format($dadosHoje['pontos_ganhos'],0,',','.') ?></div>
    <div class="result-points-label">moedas ganhas</div>
  <?php else: ?>
    <div class="result-icon">😔</div>
    <div class="result-title">Desistência registrada</div>
    <div class="result-sub">Volte amanhã para tentar novamente.</div>
  <?php endif; ?>
  <div style="margin-top:20px">
    <a href="../games.php" class="btn-ghost"><i class="bi bi-house"></i>Voltar ao menu</a>
  </div>
</div>

<!-- Grade congelada (resultado) -->
<div class="grade-wrap" style="margin-top:20px">
  <div class="grade-table">
    <div class="corner-cell" style="padding:10px;display:flex;align-items:center;justify-content:center">
      <span style="font-size:1.3rem">🏀</span>
    </div>
    <?php foreach ($grid['cols'] as $col): ?>
    <div class="header-col">
      <span class="header-icon"><?= $col['icon'] ?></span>
      <span class="header-type"><?= $col['type'] === 'team' ? 'time' : ($col['type'] === 'nation' ? 'nação' : 'conquista') ?></span>
      <span class="header-label <?= $col['type']==='team'?'team':'' ?>"><?= htmlspecialchars($col['label']) ?></span>
    </div>
    <?php endforeach; ?>
    <?php
      $respostasMap = [];
      foreach ($respostasIniciais as $r) $respostasMap[$r['cell']] = $r['player'];
    ?>
    <?php foreach ($grid['rows'] as $ri => $row): ?>
    <div class="header-row">
      <span class="header-icon"><?= $row['icon'] ?></span>
      <span class="header-type"><?= $row['type'] === 'team' ? 'time' : ($row['type'] === 'nation' ? 'nação' : 'conquista') ?></span>
      <span class="header-label <?= $row['type']==='team'?'team':'' ?>"><?= htmlspecialchars($row['label']) ?></span>
    </div>
    <?php foreach ($grid['cols'] as $ci => $col): ?>
    <?php $k = "{$ri}_{$ci}"; $ans = $respostasMap[$k] ?? null; ?>
    <div class="cell <?= $ans ? 'filled' : '' ?>">
      <div class="cell-content">
        <?php if ($ans): ?>
          <span class="cell-check">✅</span>
          <span class="cell-player-name"><?= htmlspecialchars($ans) ?></span>
        <?php else: ?>
          <span class="cell-plus">—</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
</div>
<?php else: ?>
<!-- Jogo ativo -->
<div id="statusMsg" style="display:none" class="status-banner"></div>

<div class="grade-wrap" id="gradeWrap">
  <div class="grade-table">
    <!-- Canto -->
    <div class="corner-cell" style="padding:10px;display:flex;align-items:center;justify-content:center">
      <span style="font-size:1.3rem">🏀</span>
    </div>
    <!-- Headers colunas -->
    <?php foreach ($grid['cols'] as $ci => $col): ?>
    <div class="header-col">
      <span class="header-icon"><?= $col['icon'] ?></span>
      <span class="header-type"><?= $col['type'] === 'team' ? 'time' : ($col['type'] === 'nation' ? 'nação' : 'conquista') ?></span>
      <span class="header-label <?= $col['type']==='team'?'team':'' ?>"><?= htmlspecialchars($col['label']) ?></span>
    </div>
    <?php endforeach; ?>
    <!-- Linhas -->
    <?php foreach ($grid['rows'] as $ri => $row): ?>
    <div class="header-row">
      <span class="header-icon"><?= $row['icon'] ?></span>
      <span class="header-type"><?= $row['type'] === 'team' ? 'time' : ($row['type'] === 'nation' ? 'nação' : 'conquista') ?></span>
      <span class="header-label <?= $row['type']==='team'?'team':'' ?>"><?= htmlspecialchars($row['label']) ?></span>
    </div>
    <?php foreach ($grid['cols'] as $ci => $col): ?>
    <div class="cell" id="cell_<?= $ri ?>_<?= $ci ?>" data-cell="<?= $ri ?>_<?= $ci ?>" onclick="selectCell('<?= $ri ?>_<?= $ci ?>')">
      <div class="cell-content">
        <span class="cell-plus">+</span>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
  <div class="hint-row">
    <span id="hintDot_0_0" class="hint-dot empty"></span>
    <span id="hintDot_0_1" class="hint-dot empty"></span>
    <span id="hintDot_1_0" class="hint-dot empty"></span>
    <span id="hintDot_1_1" class="hint-dot empty"></span>
    <span style="font-size:10px;color:var(--text3);margin-left:4px">Preencha os 4 quadrantes</span>
  </div>
</div>

<div class="search-area" id="searchArea" style="display:none">
  <div class="search-label"><i class="bi bi-search"></i>Buscar jogador para: <strong id="searchForLabel" style="color:var(--text)"></strong></div>
  <div class="search-wrap">
    <input type="text" class="search-input" id="searchInput" placeholder="Nome do jogador NBA..." autocomplete="off" autocorrect="off" spellcheck="false">
    <div class="autocomplete-list" id="autocompleteList" style="display:none"></div>
  </div>
  <div style="margin-top:10px;display:flex;gap:8px">
    <button class="btn-ghost" onclick="cancelSelection()"><i class="bi bi-x"></i>Cancelar</button>
    <button class="btn-ghost" id="giveUpBtn" onclick="giveUp()"><i class="bi bi-flag"></i>Desistir</button>
  </div>
</div>

<div id="completedPanel" style="display:none" class="result-panel">
  <div class="result-icon">🏆</div>
  <div class="result-title">Grade completa!</div>
  <div class="result-sub" id="resultSubText">Você encontrou todos os jogadores!</div>
  <div class="result-points" id="resultPoints">+<?= $PONTOS_VITORIA ?></div>
  <div class="result-points-label">moedas ganhas</div>
  <div style="margin-top:20px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
    <a href="../games.php" class="btn-ghost"><i class="bi bi-house"></i>Menu</a>
  </div>
</div>

<div id="gaveUpPanel" style="display:none" class="result-panel">
  <div class="result-icon">😔</div>
  <div class="result-title">Desistência</div>
  <div class="result-sub">Você preencheu <strong id="gaveUpCount">0</strong>/4 quadrantes.<br>Volte amanhã!</div>
  <div style="margin-top:20px">
    <a href="../games.php" class="btn-ghost"><i class="bi bi-house"></i>Menu</a>
  </div>
</div>

<?php endif; ?>
</div><!-- /main -->

<script>
const VALID_MAP   = <?= json_encode($validMap) ?>;
const PLAYERS_ALL = <?= json_encode(array_map(fn($p)=>['n'=>$p['n'],'c'=>$p['c']],$PLAYERS)) ?>;
const PONTOS_VITORIA = <?= $PONTOS_VITORIA ?>;
const TIMER_SECONDS  = 3 * 60;
const NATION_FLAGS   = <?= json_encode(array_column(array_filter($CRITERIA,fn($c)=>$c['type']==='nation'),'icon','id')) ?>;
const GRID_ROWS      = <?= json_encode(array_map(fn($r)=>$r['label'],$grid['rows'])) ?>;
const GRID_COLS      = <?= json_encode(array_map(fn($c)=>$c['label'],$grid['cols'])) ?>;

let selectedCell  = null;
let answers       = {};   // { "0_0": "LeBron James", ... }
let timerInterval = null;
let secondsLeft   = TIMER_SECONDS;
let gameOver      = false;
let usedPlayers   = new Set();

// Restaurar estado inicial se houver (nunca chegou aqui se jaTerminou=true no PHP)
const initialAnswers = <?= json_encode($respostasIniciais) ?>;
initialAnswers.forEach(r => {
    answers[r.cell] = r.player;
    usedPlayers.add(r.player.toLowerCase());
    fillCell(r.cell, r.player, false);
});
updateCount();

// --- Timer ---
function startTimer() {
    timerInterval = setInterval(() => {
        secondsLeft--;
        updateTimerDisplay();
        if (secondsLeft <= 0) {
            clearInterval(timerInterval);
            if (!gameOver) giveUp();
        }
    }, 1000);
}

function updateTimerDisplay() {
    const m = Math.floor(secondsLeft / 60);
    const s = secondsLeft % 60;
    const chip = document.getElementById('timerChip');
    document.getElementById('timerDisplay').textContent = `${m}:${String(s).padStart(2,'0')}`;
    chip.className = 'chip timer-chip' + (secondsLeft <= 30 ? ' danger' : secondsLeft <= 60 ? ' warn' : '');
}

if (!gameOver && Object.keys(answers).length < 4) startTimer();

// --- Seleção de célula ---
function selectCell(cellId) {
    if (gameOver) return;
    if (answers[cellId]) return; // já preenchida
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

// --- Autocomplete ---
document.getElementById('searchInput').addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    const list = document.getElementById('autocompleteList');
    if (q.length < 2) { list.style.display = 'none'; return; }

    const matches = PLAYERS_ALL.filter(p => {
        if (usedPlayers.has(p.n.toLowerCase())) return false;
        return normalize(p.n).includes(normalize(q));
    }).slice(0, 8);

    if (!matches.length) { list.style.display = 'none'; return; }

    list.innerHTML = matches.map(p => {
        const flag = NATION_FLAGS[p.c] || '';
        return `<div class="ac-item" onclick="submitPlayer('${escHtml(p.n)}')">${flag} <span>${escHtml(p.n)}</span></div>`;
    }).join('');
    list.style.display = 'block';
});

document.getElementById('searchInput').addEventListener('keydown', function(e) {
    const items = document.querySelectorAll('.ac-item');
    let active = document.querySelector('.ac-item.active');
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!active) items[0]?.classList.add('active');
        else { active.classList.remove('active'); (active.nextElementSibling || items[0])?.classList.add('active'); }
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (!active) items[items.length-1]?.classList.add('active');
        else { active.classList.remove('active'); (active.previousElementSibling || items[items.length-1])?.classList.add('active'); }
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (active) active.click();
        else if (items.length === 1) items[0].click();
    } else if (e.key === 'Escape') {
        cancelSelection();
    }
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('.search-wrap') && !e.target.closest('.cell')) {
        document.getElementById('autocompleteList').style.display = 'none';
    }
});

// --- Submeter jogador ---
function submitPlayer(name) {
    if (!selectedCell) return;
    const key   = selectedCell;
    const valid = VALID_MAP[key] || [];
    const lower = name.toLowerCase();

    if (!valid.includes(lower)) {
        flashCell(key, 'wrong');
        showStatus('❌ ' + name + ' não atende aos dois critérios desta célula.', 'error');
        document.getElementById('autocompleteList').style.display = 'none';
        document.getElementById('searchInput').value = '';
        return;
    }
    if (usedPlayers.has(lower)) {
        showStatus('⚠️ ' + name + ' já foi usado em outra célula.', 'error');
        return;
    }

    answers[key] = name;
    usedPlayers.add(lower);
    fillCell(key, name, true);
    cancelSelection();
    updateCount();
    saveProgress();

    if (Object.keys(answers).length >= 4) {
        setTimeout(() => endGame(true), 400);
    }
}

function fillCell(cellId, playerName, animate) {
    const cell = document.getElementById('cell_' + cellId);
    if (!cell) return;
    cell.classList.add('filled');
    cell.classList.remove('selected','wrong');
    cell.innerHTML = `<div class="cell-content"><span class="cell-check">✅</span><span class="cell-player-name">${escHtml(playerName)}</span></div>`;
    document.getElementById('hintDot_' + cellId).className = 'hint-dot green';
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
    el.className = 'status-banner ' + type;
    el.innerHTML = msg;
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(() => el.style.display = 'none', 3000);
}

// --- Fim de jogo ---
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
            `Tempo restante: ${Math.floor(secondsLeft/60)}:${String(secondsLeft%60).padStart(2,'0')} • Bônus +${timeBonus}`;
        document.getElementById('completedPanel').style.display = 'block';
    } else {
        document.getElementById('gaveUpCount').textContent = filled;
        document.getElementById('gaveUpPanel').style.display = 'block';
    }
}

function giveUp() {
    if (gameOver) return;
    endGame(false);
}

// --- Salvar no banco ---
function saveProgress(concluido=false, desistiu=false, pontos=0) {
    const payload = new FormData();
    payload.append('action',    'save');
    payload.append('respostas', JSON.stringify(Object.entries(answers).map(([cell,player])=>({cell,player}))));
    payload.append('concluido', concluido ? 1 : 0);
    payload.append('desistiu',  desistiu ? 1 : 0);
    payload.append('pontos',    pontos);
    fetch(location.href, {method:'POST',body:payload}).catch(()=>{});
}

// --- Utils ---
function normalize(s) {
    return s.normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase();
}
function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
