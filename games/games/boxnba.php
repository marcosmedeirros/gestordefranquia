<?php
// boxnba.php - Box NBA: jogo diário estilo Box2Box para basquete
// session_start já chamado em games/index.php
require '../core/conexao.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = (int)$_SESSION['user_id'];
$PONTOS_VITORIA = 150 * getGamePointsMultiplier($pdo, 'boxnba');

// Cria tabela se não existir
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS boxnba_historico (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        data_jogo DATE NOT NULL,
        respostas TEXT DEFAULT '[]',
        tentativas_restantes INT DEFAULT 9,
        pontos_ganhos INT DEFAULT 0,
        concluido TINYINT DEFAULT 0,
        desistiu TINYINT DEFAULT 0,
        streak_count INT DEFAULT 0,
        UNIQUE KEY uq (id_usuario, data_jogo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

// Insere controle de pontos para boxnba
try {
    $pdo->exec("INSERT IGNORE INTO fba_game_controls (game_key, is_double) VALUES ('boxnba', 0)");
} catch (PDOException $e) {}

// --- BANCO DE JOGADORES ---
$PLAYERS = [
    ['n'=>'LeBron James',            't'=>['CLE','MIA','LAL'],                         'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Stephen Curry',           't'=>['GSW'],                                      'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Kevin Durant',            't'=>['OKC','GSW','BKN','PHX'],                   'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','SCORING']],
    ['n'=>'Giannis Antetokounmpo',   't'=>['MIL'],                                      'c'=>'GRE','a'=>['MVP','CHAMPION','ALLSTAR','DPOY','FINALS_MVP']],
    ['n'=>'Nikola Jokic',            't'=>['DEN'],                                      'c'=>'SER','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Luka Doncic',             't'=>['DAL','LAL'],                                'c'=>'SLO','a'=>['ALLSTAR','ROY']],
    ['n'=>'Joel Embiid',             't'=>['PHI','LAL'],                                'c'=>'CAM','a'=>['MVP','ALLSTAR','SCORING']],
    ['n'=>'Kawhi Leonard',           't'=>['SAS','TOR','LAC'],                          'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY','FINALS_MVP']],
    ['n'=>'Kobe Bryant',             't'=>['LAL'],                                      'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','SCORING']],
    ['n'=>'Shaquille O\'Neal',       't'=>['LAL','MIA','PHX','CLE','BOS'],             'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Dwyane Wade',             't'=>['MIA','CHI','CLE'],                          'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP','SCORING']],
    ['n'=>'Chris Bosh',              't'=>['TOR','MIA'],                                'c'=>'CAN','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Dirk Nowitzki',           't'=>['DAL'],                                      'c'=>'GER','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Tim Duncan',              't'=>['SAS'],                                      'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY']],
    ['n'=>'Tony Parker',             't'=>['SAS','CHA'],                                'c'=>'FRA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Manu Ginobili',           't'=>['SAS'],                                      'c'=>'ARG','a'=>['CHAMPION','ALLSTAR','SIXTHMAN']],
    ['n'=>'Kevin Garnett',           't'=>['MIN','BOS','BKN'],                         'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Paul Pierce',             't'=>['BOS','BKN','WAS','LAC','CLE'],             'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Ray Allen',               't'=>['MIL','SEA','BOS','MIA'],                   'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Allen Iverson',           't'=>['PHI','DEN','DET','MEM'],                   'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING','ROY']],
    ['n'=>'Vince Carter',            't'=>['TOR','NJN','ORL','PHX','DAL','OKC','MEM','ATL','SAC'],'c'=>'CAN','a'=>['ALLSTAR','ROY']],
    ['n'=>'Tracy McGrady',           't'=>['TOR','ORL','HOU','NYK','DET','ATL','SAS'], 'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Carmelo Anthony',         't'=>['DEN','NYK','OKC','HOU','POR','LAL'],       'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Russell Westbrook',       't'=>['OKC','HOU','WAS','LAL','LAC','UTA'],       'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING']],
    ['n'=>'James Harden',            't'=>['OKC','HOU','BKN','PHI','LAC'],             'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING']],
    ['n'=>'Derrick Rose',            't'=>['CHI','NYK','CLE','MIN','DET','MEM'],       'c'=>'USA','a'=>['MVP','ALLSTAR','ROY']],
    ['n'=>'Jimmy Butler',            't'=>['CHI','MIN','PHI','MIA','GSW'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Paul George',             't'=>['IND','OKC','LAC'],                         'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Kyrie Irving',            't'=>['CLE','BOS','BKN','DAL','MIA'],             'c'=>'USA','a'=>['CHAMPION','ALLSTAR','ROY']],
    ['n'=>'Damian Lillard',          't'=>['POR','MIL'],                               'c'=>'USA','a'=>['ALLSTAR','ROY','SCORING']],
    ['n'=>'Chris Paul',              't'=>['NOH','LAC','HOU','OKC','PHX','SAC','GSW'],'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Steve Nash',              't'=>['PHX','DAL','LAL'],                         'c'=>'CAN','a'=>['MVP','ALLSTAR']],
    ['n'=>'Jason Kidd',              't'=>['DAL','PHX','NJN','NYK'],                   'c'=>'USA','a'=>['CHAMPION','ALLSTAR','ROY']],
    ['n'=>'Pau Gasol',               't'=>['MEM','LAL','CHI','SAS','MIL','POR'],       'c'=>'ESP','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Rudy Gobert',             't'=>['UTA','MIN'],                               'c'=>'FRA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Victor Wembanyama',       't'=>['SAS'],                                      'c'=>'FRA','a'=>['ROY']],
    ['n'=>'Nicolas Batum',           't'=>['POR','CHA','LAC','PHI'],                   'c'=>'FRA','a'=>[]],
    ['n'=>'Boris Diaw',              't'=>['ATL','PHX','CHA','SAS','UTA'],             'c'=>'FRA','a'=>['CHAMPION','SIXTHMAN']],
    ['n'=>'Evan Fournier',           't'=>['DEN','ORL','BOS','NYK'],                   'c'=>'FRA','a'=>[]],
    ['n'=>'Andrew Wiggins',          't'=>['MIN','GSW'],                               'c'=>'CAN','a'=>['CHAMPION','ALLSTAR','ROY']],
    ['n'=>'Jamal Murray',            't'=>['DEN'],                                      'c'=>'CAN','a'=>['CHAMPION']],
    ['n'=>'Shai Gilgeous-Alexander', 't'=>['LAC','OKC'],                               'c'=>'CAN','a'=>['ALLSTAR','SCORING']],
    ['n'=>'DeMar DeRozan',           't'=>['TOR','SAS','CHI'],                         'c'=>'CAN','a'=>['ALLSTAR']],
    ['n'=>'Nenê',                    't'=>['DEN','HOU','WAS'],                         'c'=>'BRA','a'=>['ALLSTAR']],
    ['n'=>'Anderson Varejão',        't'=>['CLE','POR','GSW'],                         'c'=>'BRA','a'=>[]],
    ['n'=>'Leandro Barbosa',         't'=>['PHX','TOR','IND','GSW','BOS'],             'c'=>'BRA','a'=>['SIXTHMAN']],
    ['n'=>'Tiago Splitter',          't'=>['SAS','ATL','SAC','PHX'],                   'c'=>'BRA','a'=>['CHAMPION']],
    ['n'=>'Luis Scola',              't'=>['HOU','PHX','TOR','IND','NJN'],             'c'=>'ARG','a'=>['ALLSTAR']],
    ['n'=>'Goran Dragic',            't'=>['PHX','MIA','TOR','BKN','CHI'],             'c'=>'SLO','a'=>['ALLSTAR']],
    ['n'=>'Nikola Vucevic',          't'=>['PHI','ORL','CHI'],                         'c'=>'SER','a'=>['ALLSTAR']],
    ['n'=>'Bogdan Bogdanovic',       't'=>['SAC','ATL','MIL'],                         'c'=>'SER','a'=>[]],
    ['n'=>'Vlade Divac',             't'=>['LAL','CHA','SAC'],                         'c'=>'SER','a'=>['ALLSTAR']],
    ['n'=>'Dennis Schroder',         't'=>['ATL','OKC','LAL','BOS','HOU','TOR'],       'c'=>'GER','a'=>['SIXTHMAN']],
    ['n'=>'Pascal Siakam',           't'=>['TOR','IND'],                               'c'=>'CAM','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Draymond Green',          't'=>['GSW'],                                      'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Klay Thompson',           't'=>['GSW','DAL'],                                'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Anthony Davis',           't'=>['NOP','LAL'],                               'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Jayson Tatum',            't'=>['BOS'],                                      'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Bam Adebayo',             't'=>['MIA'],                                      'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Donovan Mitchell',        't'=>['UTA','CLE'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Devin Booker',            't'=>['PHX'],                                      'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Hakeem Olajuwon',         't'=>['HOU','TOR'],                               'c'=>'NIG','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY']],
    ['n'=>'Charles Barkley',         't'=>['PHI','PHX','HOU'],                         'c'=>'USA','a'=>['MVP','ALLSTAR']],
    ['n'=>'Scottie Pippen',          't'=>['CHI','HOU','POR'],                         'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Dennis Rodman',           't'=>['DET','SAS','CHI','LAL','DAL'],             'c'=>'USA','a'=>['CHAMPION','DPOY']],
    ['n'=>'Khris Middleton',         't'=>['DET','MIL'],                               'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Jrue Holiday',            't'=>['PHI','NOP','MIL','BOS','POR'],             'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Trae Young',              't'=>['ATL'],                                      'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Karl-Anthony Towns',      't'=>['MIN','NYK'],                               'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Zion Williamson',         't'=>['NOP'],                                      'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Kyle Lowry',              't'=>['MEM','HOU','TOR','MIA','PHI','MIN'],       'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Dwight Howard',           't'=>['ORL','LAL','HOU','ATL','CHA','WAS','PHI'],'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Rajon Rondo',             't'=>['BOS','DAL','SAC','CHI','NOP','LAL','ATL','LAC','CLE'],'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'DeMarcus Cousins',        't'=>['SAC','NOP','GSW','LAL','HOU','LAC'],       'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Andrei Kirilenko',        't'=>['UTA','MIN','BKN'],                         'c'=>'RUS','a'=>['ALLSTAR']],
    ['n'=>'Patrick Ewing',           't'=>['NYK','SEA','ORL','ATL'],                   'c'=>'USA','a'=>['ROY','ALLSTAR']],
    ['n'=>'Dikembe Mutombo',         't'=>['DEN','ATL','PHI','NJN','NYK','HOU'],       'c'=>'COD','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Grant Hill',              't'=>['DET','ORL','PHX','LAC'],                   'c'=>'USA','a'=>['ROY','ALLSTAR']],
    ['n'=>'Reggie Miller',           't'=>['IND'],                                      'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Metta World Peace',       't'=>['CHI','IND','LAL','NYK','BOS'],             'c'=>'USA','a'=>['CHAMPION','DPOY']],
    ['n'=>'Brook Lopez',             't'=>['NJN','LAL','BKN','MIL'],                   'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Jaylen Brown',            't'=>['BOS'],                                      'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Anthony Edwards',         't'=>['MIN'],                                      'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Paolo Banchero',          't'=>['ORL'],                                      'c'=>'USA','a'=>['ROY']],
    ['n'=>'Wilt Chamberlain',        't'=>['PHW','SFW','LAL'],                         'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','SCORING']],
    ['n'=>'Bill Russell',            't'=>['BOS'],                                      'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Magic Johnson',           't'=>['LAL'],                                      'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Larry Bird',              't'=>['BOS'],                                      'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Michael Jordan',          't'=>['CHI','WAS'],                               'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY','SCORING']],
    ['n'=>'Isiah Thomas',            't'=>['DET'],                                      'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'John Stockton',           't'=>['UTA'],                                      'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Karl Malone',             't'=>['UTA','LAL'],                               'c'=>'USA','a'=>['MVP','ALLSTAR']],
    ['n'=>'Clyde Drexler',           't'=>['POR','HOU','LAL'],                         'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Gary Payton',             't'=>['SEA','MIL','LAL','BOS','MIA'],             'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Shawn Kemp',              't'=>['SEA','CLE','POR','ORL','LAL'],             'c'=>'USA','a'=>['ALLSTAR']],
];

// Remove duplicatas
$seen = [];
$PLAYERS = array_values(array_filter($PLAYERS, function($p) use (&$seen) {
    if (isset($seen[$p['n']])) return false;
    $seen[$p['n']] = true;
    return true;
}));

$CRITERIA = [
    ['id'=>'LAL','type'=>'team',  'label'=>'Lakers',            'icon'=>'🟡','full'=>'Los Angeles Lakers'],
    ['id'=>'GSW','type'=>'team',  'label'=>'Warriors',          'icon'=>'💛','full'=>'Golden State Warriors'],
    ['id'=>'BOS','type'=>'team',  'label'=>'Celtics',           'icon'=>'🍀','full'=>'Boston Celtics'],
    ['id'=>'MIA','type'=>'team',  'label'=>'Heat',              'icon'=>'🔴','full'=>'Miami Heat'],
    ['id'=>'SAS','type'=>'team',  'label'=>'Spurs',             'icon'=>'⚫','full'=>'San Antonio Spurs'],
    ['id'=>'CHI','type'=>'team',  'label'=>'Bulls',             'icon'=>'🐂','full'=>'Chicago Bulls'],
    ['id'=>'OKC','type'=>'team',  'label'=>'Thunder',           'icon'=>'⚡','full'=>'OKC Thunder'],
    ['id'=>'DEN','type'=>'team',  'label'=>'Nuggets',           'icon'=>'⛏️','full'=>'Denver Nuggets'],
    ['id'=>'DAL','type'=>'team',  'label'=>'Mavericks',         'icon'=>'🐎','full'=>'Dallas Mavericks'],
    ['id'=>'TOR','type'=>'team',  'label'=>'Raptors',           'icon'=>'🦖','full'=>'Toronto Raptors'],
    ['id'=>'PHI','type'=>'team',  'label'=>'76ers',             'icon'=>'🔔','full'=>'Philadelphia 76ers'],
    ['id'=>'MIL','type'=>'team',  'label'=>'Bucks',             'icon'=>'🦌','full'=>'Milwaukee Bucks'],
    ['id'=>'HOU','type'=>'team',  'label'=>'Rockets',           'icon'=>'🚀','full'=>'Houston Rockets'],
    ['id'=>'PHX','type'=>'team',  'label'=>'Suns',              'icon'=>'☀️','full'=>'Phoenix Suns'],
    ['id'=>'CLE','type'=>'team',  'label'=>'Cavaliers',         'icon'=>'⚔️','full'=>'Cleveland Cavaliers'],
    ['id'=>'USA','type'=>'nation','label'=>'EUA',               'icon'=>'🇺🇸','full'=>'Estados Unidos'],
    ['id'=>'BRA','type'=>'nation','label'=>'Brasil',            'icon'=>'🇧🇷','full'=>'Brasil'],
    ['id'=>'FRA','type'=>'nation','label'=>'França',            'icon'=>'🇫🇷','full'=>'França'],
    ['id'=>'ARG','type'=>'nation','label'=>'Argentina',         'icon'=>'🇦🇷','full'=>'Argentina'],
    ['id'=>'SLO','type'=>'nation','label'=>'Eslovênia',         'icon'=>'🇸🇮','full'=>'Eslovênia'],
    ['id'=>'SER','type'=>'nation','label'=>'Sérvia',            'icon'=>'🇷🇸','full'=>'Sérvia'],
    ['id'=>'GER','type'=>'nation','label'=>'Alemanha',          'icon'=>'🇩🇪','full'=>'Alemanha'],
    ['id'=>'CAN','type'=>'nation','label'=>'Canadá',            'icon'=>'🇨🇦','full'=>'Canadá'],
    ['id'=>'ESP','type'=>'nation','label'=>'Espanha',           'icon'=>'🇪🇸','full'=>'Espanha'],
    ['id'=>'GRE','type'=>'nation','label'=>'Grécia',            'icon'=>'🇬🇷','full'=>'Grécia'],
    ['id'=>'MVP',       'type'=>'award','label'=>'MVP',             'icon'=>'🏆','full'=>'MVP da Temporada'],
    ['id'=>'CHAMPION',  'type'=>'award','label'=>'Campeão',         'icon'=>'💍','full'=>'Campeão NBA'],
    ['id'=>'ALLSTAR',   'type'=>'award','label'=>'All-Star',        'icon'=>'⭐','full'=>'All-Star'],
    ['id'=>'DPOY',      'type'=>'award','label'=>'Defensor',        'icon'=>'🛡️','full'=>'Melhor Defensor (DPOY)'],
    ['id'=>'FINALS_MVP','type'=>'award','label'=>'MVP Finais',      'icon'=>'🎖️','full'=>'MVP das Finais'],
    ['id'=>'ROY',       'type'=>'award','label'=>'Calouro Ano',     'icon'=>'🌟','full'=>'Calouro do Ano (ROY)'],
    ['id'=>'SCORING',   'type'=>'award','label'=>'Artilheiro',      'icon'=>'🎯','full'=>'Artilheiro da Temporada'],
    ['id'=>'SIXTHMAN',  'type'=>'award','label'=>'6º Homem',        'icon'=>'🪑','full'=>'Sexto Homem do Ano'],
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
    for ($attempt = 0; $attempt < 5000; $attempt++) {
        $idx = [];
        $used = [];
        while (count($idx) < 6) {
            $pick = rand(0, count($allCriteria) - 1);
            if (!in_array($pick, $used, true)) { $used[] = $pick; $idx[] = $pick; }
        }
        $rows = [$allCriteria[$idx[0]], $allCriteria[$idx[1]], $allCriteria[$idx[2]]];
        $cols = [$allCriteria[$idx[3]], $allCriteria[$idx[4]], $allCriteria[$idx[5]]];
        $rowNations = count(array_filter($rows, fn($c) => $c['type'] === 'nation'));
        $colNations = count(array_filter($cols, fn($c) => $c['type'] === 'nation'));
        if ($rowNations > 0 && $colNations > 0) continue;
        $valid = true;
        $minCount = PHP_INT_MAX;
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                $cnt = count(getValidPlayers($players, $r, $c));
                if ($cnt === 0) { $valid = false; break 2; }
                $minCount = min($minCount, $cnt);
            }
        }
        if ($valid && $minCount >= 1) return ['rows' => $rows, 'cols' => $cols];
    }
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

$allPlayerNames = array_map(fn($p) => $p['n'], $PLAYERS);

// --- AJAX ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    header('Content-Type: application/json');
    if ($action === 'save') {
        $respostas         = json_encode(json_decode($_POST['respostas'] ?? '[]', true) ?: []);
        $tentativas_rest   = (int)($_POST['tentativas_restantes'] ?? 9);
        $concluido         = (int)($_POST['concluido'] ?? 0);
        $desistiu          = (int)($_POST['desistiu'] ?? 0);
        $pontos            = (int)($_POST['pontos'] ?? 0);
        $hoje              = date('Y-m-d');
        try {
            $stmt = $pdo->prepare("SELECT id, pontos_ganhos FROM boxnba_historico WHERE id_usuario=? AND data_jogo=?");
            $stmt->execute([$user_id, $hoje]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $pdo->prepare("UPDATE boxnba_historico SET respostas=?,tentativas_restantes=?,concluido=?,desistiu=?,pontos_ganhos=? WHERE id=?")
                    ->execute([$respostas,$tentativas_rest,$concluido,$desistiu,$pontos,$row['id']]);
                if ($concluido && $row['pontos_ganhos'] == 0 && $pontos > 0)
                    $pdo->prepare("UPDATE usuarios SET pontos=pontos+? WHERE id=?")->execute([$pontos,$user_id]);
            } else {
                $pdo->prepare("INSERT INTO boxnba_historico (id_usuario,data_jogo,respostas,tentativas_restantes,concluido,desistiu,pontos_ganhos) VALUES(?,?,?,?,?,?,?)")
                    ->execute([$user_id,$hoje,$respostas,$tentativas_rest,$concluido,$desistiu,$pontos]);
                if ($concluido && $pontos > 0)
                    $pdo->prepare("UPDATE usuarios SET pontos=pontos+? WHERE id=?")->execute([$pontos,$user_id]);
            }
            echo json_encode(['ok'=>true]);
        } catch (PDOException $e) { echo json_encode(['ok'=>false,'err'=>$e->getMessage()]); }
        exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

$hoje      = date('Y-m-d');
$dadosHoje = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM boxnba_historico WHERE id_usuario=? AND data_jogo=?");
    $stmt->execute([$user_id, $hoje]);
    $dadosHoje = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$respostasIniciais   = $dadosHoje ? (json_decode($dadosHoje['respostas'],true) ?: []) : [];
$tentativasIniciais  = $dadosHoje ? (int)$dadosHoje['tentativas_restantes'] : 9;
$jaTerminou          = $dadosHoje && ($dadosHoje['concluido'] || $dadosHoje['desistiu']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Box NBA</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#07070a;--panel:#101013;--panel2:#16161a;--panel3:#1c1c21;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.14);
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);--red-glow:rgba(252,0,37,.25);
  --text:#f0f0f3;--text2:#868690;--text3:#3c3c44;
  --green:#22c55e;--green-soft:rgba(34,197,94,.12);
  --amber:#f59e0b;--amber-soft:rgba(245,158,11,.12);
  --blue:#3b82f6;--blue-soft:rgba(59,130,246,.12);
  --radius:14px;--font:'Poppins',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;overflow-x:hidden}

/* TOP BAR */
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.topbar-left{display:flex;align-items:center;gap:10px}
.back-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;font-size:14px;transition:.2s;flex-shrink:0}
.back-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.game-title{font-size:15px;font-weight:800;color:var(--text)}
.game-title span{color:var(--red)}
.daily-badge{display:inline-flex;align-items:center;gap:4px;font-size:8px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;padding:2px 8px;border-radius:999px;background:var(--red-soft);border:1px solid var(--red-glow);color:var(--red);margin-left:6px}
.topbar-right{display:flex;align-items:center;gap:6px}
.chip{display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:var(--panel2);border:1px solid var(--border);font-size:11px;font-weight:700;color:var(--text);white-space:nowrap}
.chip.warn{border-color:var(--amber)!important;color:var(--amber)!important}
.chip.danger{border-color:var(--red)!important;color:var(--red)!important}

/* LAYOUT */
.main{max-width:560px;margin:0 auto;padding:14px 12px 60px}

/* ── GRID ── */
.grid-wrap{display:grid;grid-template-columns:80px repeat(3,1fr);grid-template-rows:80px repeat(3,1fr);gap:4px;margin-bottom:16px}
.header-corner{background:transparent}
.header-col,.header-row{background:var(--panel2);border:1px solid var(--border2);border-radius:10px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;padding:6px 4px;text-align:center;position:relative;cursor:default}
.header-col{border-radius:12px 12px 6px 6px}
.header-row{border-radius:6px 12px 12px 6px}
.header-icon{font-size:1.5rem;line-height:1}
.header-label{font-size:9px;font-weight:700;color:var(--text2);letter-spacing:.3px;line-height:1.2}
.header-type{font-size:7px;font-weight:600;letter-spacing:.8px;text-transform:uppercase;padding:1px 5px;border-radius:999px;margin-top:2px}
.type-team{background:rgba(59,130,246,.15);color:#60a5fa}
.type-nation{background:rgba(34,197,94,.15);color:#4ade80}
.type-award{background:rgba(252,0,37,.15);color:#f87171}

.cell{background:var(--panel);border:1px solid var(--border);border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;position:relative;overflow:hidden;min-height:72px}
.cell:hover:not(.done):not(.locked){border-color:var(--border2);background:var(--panel2);transform:scale(1.02)}
.cell.active{border-color:var(--red)!important;background:var(--red-soft)!important;box-shadow:0 0 0 2px var(--red-glow)}
.cell.done{cursor:default}
.cell.correct{border-color:rgba(34,197,94,.4)!important;background:var(--green-soft)!important}
.cell.wrong-flash{animation:wrongFlash .4s ease}
.cell.locked{cursor:default;opacity:.5}
@keyframes wrongFlash{0%,100%{background:var(--panel)}50%{background:rgba(252,0,37,.18);border-color:var(--red)}}
.cell-plus{font-size:20px;color:var(--text3);font-weight:300;line-height:1}
.cell-player{padding:6px 4px;text-align:center;width:100%}
.cell-player-name{font-size:9.5px;font-weight:700;color:var(--text);line-height:1.3}
.cell-player-icon{font-size:1.4rem;display:block;margin-bottom:2px}

/* ── SEARCH MODAL ── */
.search-overlay{position:fixed;inset:0;background:rgba(0,0,0,.8);backdrop-filter:blur(8px);z-index:200;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px 20px}
.search-overlay.hidden{display:none}
.search-box{background:var(--panel);border:1px solid var(--border2);border-radius:18px;padding:20px;max-width:440px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.7)}
.search-context{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding:10px 12px;background:var(--panel2);border:1px solid var(--border);border-radius:10px}
.search-context-badges{display:flex;gap:6px;flex-wrap:wrap}
.ctx-badge{display:flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;border:1px solid var(--border2);background:var(--panel3)}
.search-input-wrap{position:relative}
.search-input{width:100%;padding:11px 14px 11px 38px;background:var(--panel2);border:1px solid var(--border2);border-radius:10px;color:var(--text);font-family:var(--font);font-size:14px;font-weight:600;outline:none;transition:border-color .2s}
.search-input:focus{border-color:var(--red)}
.search-input-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text3);font-size:14px;pointer-events:none}
.suggestions{margin-top:6px;max-height:220px;overflow-y:auto;border-radius:10px;border:1px solid var(--border)}
.suggestion-item{padding:10px 14px;font-size:13px;font-weight:600;color:var(--text);cursor:pointer;transition:background .15s;border-bottom:1px solid var(--border)}
.suggestion-item:last-child{border-bottom:none}
.suggestion-item:hover,.suggestion-item.focused{background:var(--panel2);color:var(--red)}
.suggestion-item mark{background:transparent;color:var(--red);font-weight:800}
.no-suggestions{padding:12px 14px;font-size:12px;color:var(--text3);text-align:center}
.search-cancel{margin-top:10px;width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:transparent;color:var(--text2);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s}
.search-cancel:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}

/* ── RESULT MODAL ── */
.result-overlay{position:fixed;inset:0;background:rgba(0,0,0,.85);backdrop-filter:blur(8px);z-index:300;display:flex;align-items:center;justify-content:center;padding:20px}
.result-overlay.hidden{display:none}
.result-card{background:var(--panel);border:1px solid var(--border2);border-radius:22px;padding:30px 24px;max-width:420px;width:100%;text-align:center;box-shadow:0 24px 80px rgba(0,0,0,.7)}
.result-icon{font-size:3.5rem;margin-bottom:10px;display:block}
.result-title{font-size:20px;font-weight:800;margin-bottom:6px}
.result-subtitle{font-size:13px;color:var(--text2);margin-bottom:18px;line-height:1.5}
.result-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:20px}
.result-stat{background:var(--panel2);border:1px solid var(--border);border-radius:10px;padding:12px 8px}
.result-stat-val{font-size:20px;font-weight:800;color:var(--text)}
.result-stat-lbl{font-size:9px;color:var(--text2);font-weight:600;letter-spacing:.4px;text-transform:uppercase;margin-top:2px}
.result-answer-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:18px}
.result-cell{background:var(--panel2);border:1px solid var(--border);border-radius:8px;padding:8px 4px;font-size:10px;text-align:center}
.result-cell.ok{border-color:rgba(34,197,94,.4);background:var(--green-soft)}
.result-cell.empty{opacity:.4}
.btn-primary-red{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 24px;border-radius:12px;background:var(--red);color:#fff;border:none;font-family:var(--font);font-size:14px;font-weight:700;cursor:pointer;width:100%;transition:opacity .2s}
.btn-primary-red:hover{opacity:.85}

/* ── TOOLTIP ── */
.tooltip-wrap{position:relative;display:inline-block}
.tooltip-wrap .tooltip-body{visibility:hidden;opacity:0;position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:var(--panel3);border:1px solid var(--border2);border-radius:8px;padding:6px 10px;font-size:10px;font-weight:600;white-space:nowrap;color:var(--text);z-index:100;pointer-events:none;transition:opacity .15s}
.tooltip-wrap:hover .tooltip-body{visibility:visible;opacity:1}

/* Scrollbar */
::-webkit-scrollbar{width:4px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border2);border-radius:2px}
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
  <div class="topbar-left">
    <a href="../games.php" class="back-btn" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    <span class="game-title">Box <span>NBA</span><span class="daily-badge"><i class="bi bi-calendar3"></i>Diário</span></span>
  </div>
  <div class="topbar-right">
    <div class="chip" id="triesChip"><i class="bi bi-crosshair"></i><span id="triesLabel">9</span></div>
    <div class="chip" id="scoreChip" style="color:var(--amber)"><i class="bi bi-coin" style="color:var(--amber)"></i><span id="scoreLabel">0</span></div>
  </div>
</div>

<!-- SEARCH OVERLAY -->
<div class="search-overlay hidden" id="searchOverlay">
  <div class="search-box">
    <div class="search-context">
      <span style="font-size:11px;font-weight:600;color:var(--text2);flex-shrink:0">Célula:</span>
      <div class="search-context-badges" id="ctxBadges"></div>
    </div>
    <div class="search-input-wrap">
      <i class="bi bi-search search-input-icon"></i>
      <input type="text" class="search-input" id="searchInput" placeholder="Digite o nome do jogador…" autocomplete="off">
    </div>
    <div class="suggestions" id="suggestions"></div>
    <button class="search-cancel" id="searchCancel">Cancelar</button>
  </div>
</div>

<!-- RESULT OVERLAY -->
<div class="result-overlay hidden" id="resultOverlay">
  <div class="result-card">
    <span class="result-icon" id="resultIcon">🏆</span>
    <div class="result-title" id="resultTitle">Parabéns!</div>
    <div class="result-subtitle" id="resultSubtitle"></div>
    <div class="result-stats">
      <div class="result-stat"><div class="result-stat-val" id="rsCells">0/9</div><div class="result-stat-lbl">Acertos</div></div>
      <div class="result-stat"><div class="result-stat-val" id="rsTries">0</div><div class="result-stat-lbl">Tentativas</div></div>
      <div class="result-stat"><div class="result-stat-val" id="rsPoints">0</div><div class="result-stat-lbl">Moedas</div></div>
    </div>
    <div class="result-answer-grid" id="resultAnswerGrid"></div>
    <button class="btn-primary-red" onclick="document.getElementById('resultOverlay').classList.add('hidden')">
      <i class="bi bi-check-lg"></i>Ver grade
    </button>
  </div>
</div>

<div class="main">
  <!-- GRID -->
  <div class="grid-wrap" id="gameGrid">

    <!-- Corner -->
    <div class="header-corner"></div>

    <!-- Column headers -->
    <?php foreach ([0,1,2] as $ci):
      $col = $grid['cols'][$ci];
      $typeClass = 'type-'.$col['type'];
      $typeLabel = ['team'=>'Time','nation'=>'País','award'=>'Prêmio'][$col['type']] ?? '';
    ?>
    <div class="header-col tooltip-wrap">
      <span class="tooltip-body"><?= htmlspecialchars($col['full']) ?></span>
      <span class="header-icon"><?= $col['icon'] ?></span>
      <span class="header-label"><?= htmlspecialchars($col['label']) ?></span>
      <span class="header-type <?= $typeClass ?>"><?= $typeLabel ?></span>
    </div>
    <?php endforeach; ?>

    <!-- Rows -->
    <?php foreach ([0,1,2] as $ri):
      $row = $grid['rows'][$ri];
      $typeClass = 'type-'.$row['type'];
      $typeLabel = ['team'=>'Time','nation'=>'País','award'=>'Prêmio'][$row['type']] ?? '';
    ?>
      <div class="header-row tooltip-wrap">
        <span class="tooltip-body"><?= htmlspecialchars($row['full']) ?></span>
        <span class="header-icon"><?= $row['icon'] ?></span>
        <span class="header-label"><?= htmlspecialchars($row['label']) ?></span>
        <span class="header-type <?= $typeClass ?>"><?= $typeLabel ?></span>
      </div>
      <?php foreach ([0,1,2] as $ci): ?>
        <div class="cell" id="cell_<?= $ri ?>_<?= $ci ?>" onclick="openCell(<?= $ri ?>,<?= $ci ?>)">
          <span class="cell-plus">+</span>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>

  <div style="text-align:center;font-size:11px;color:var(--text3);padding-bottom:8px">
    Toque em uma célula para adivinhar o jogador · Nova grade amanhã às 00:00
  </div>
</div>

<script>
const VALID_MAP   = <?= json_encode($validMap) ?>;
const ALL_NAMES   = <?= json_encode($allPlayerNames) ?>;
const GRID_ROWS   = <?= json_encode(array_values($grid['rows'])) ?>;
const GRID_COLS   = <?= json_encode(array_values($grid['cols'])) ?>;
const PONTOS      = <?= (int)$PONTOS_VITORIA ?>;
const JA_TERMINOU = <?= $jaTerminou ? 'true' : 'false' ?>;

let answers  = <?= json_encode((object)array_fill_keys(array_keys($validMap), null)) ?>;
let tries    = <?= (int)$tentativasIniciais ?>;
let finished = JA_TERMINOU;

// Carrega respostas salvas
const savedAnswers = <?= json_encode($respostasIniciais) ?>;
if (Array.isArray(savedAnswers)) {
  savedAnswers.forEach(r => {
    if (r && r.key && r.player) answers[r.key] = r.player;
  });
}

// Estado atual da célula selecionada
let activeCell = null; // {r,c}
let focusedIdx = -1;

function updateUI() {
  // Atualiza células
  for (let r=0;r<3;r++) for (let c=0;c<3;c++) {
    const key = `${r}_${c}`;
    const el  = document.getElementById(`cell_${r}_${c}`);
    const ans = answers[key];
    if (ans) {
      el.classList.add('done','correct');
      el.innerHTML = `<div class="cell-player"><span class="cell-player-icon">🏀</span><div class="cell-player-name">${ans}</div></div>`;
    } else if (finished) {
      el.classList.add('locked');
    }
  }
  // Atualiza chips
  const tc = document.getElementById('triesChip');
  document.getElementById('triesLabel').textContent = tries;
  tc.className = 'chip' + (tries <= 2 ? ' danger' : tries <= 4 ? ' warn' : '');

  const correct = Object.values(answers).filter(Boolean).length;
  const pts = calcPoints(correct, tries);
  document.getElementById('scoreLabel').textContent = pts;
}

function calcPoints(correct, triesLeft) {
  if (correct === 0) return 0;
  return Math.round(PONTOS * (correct / 9) * (1 + triesLeft * 0.03));
}

function openCell(r, c) {
  if (finished) return;
  const key = `${r}_${c}`;
  if (answers[key]) return;

  activeCell = {r, c};
  document.querySelectorAll('.cell').forEach(el => el.classList.remove('active'));
  document.getElementById(`cell_${r}_${c}`).classList.add('active');

  // Monta badges de contexto
  const rowC = GRID_ROWS[r];
  const colC = GRID_COLS[c];
  document.getElementById('ctxBadges').innerHTML = `
    <span class="ctx-badge">${rowC.icon} ${rowC.label}</span>
    <span style="font-size:12px;color:var(--text3)">×</span>
    <span class="ctx-badge">${colC.icon} ${colC.label}</span>
  `;

  document.getElementById('searchInput').value = '';
  document.getElementById('suggestions').innerHTML = '';
  document.getElementById('searchOverlay').classList.remove('hidden');
  setTimeout(() => document.getElementById('searchInput').focus(), 80);
  focusedIdx = -1;
}

function closeSearch() {
  document.getElementById('searchOverlay').classList.add('hidden');
  document.querySelectorAll('.cell').forEach(el => el.classList.remove('active'));
  activeCell = null;
  focusedIdx = -1;
}

function renderSuggestions(query) {
  const box = document.getElementById('suggestions');
  if (!query) { box.innerHTML = ''; return; }
  const q = query.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
  const used = new Set(Object.values(answers).filter(Boolean).map(n => n.toLowerCase()));
  const matches = ALL_NAMES
    .filter(n => {
      const norm = n.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
      return norm.includes(q) && !used.has(n.toLowerCase());
    })
    .slice(0, 8);

  if (!matches.length) {
    box.innerHTML = `<div class="no-suggestions">Nenhum jogador encontrado</div>`;
    focusedIdx = -1;
    return;
  }
  box.innerHTML = matches.map((n,i) => {
    const highlighted = n.replace(new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi'), '<mark>$1</mark>');
    return `<div class="suggestion-item" data-idx="${i}" data-name="${n}" onclick="selectPlayer('${n.replace(/'/,"\\'")}')">
      🏀 ${highlighted}
    </div>`;
  }).join('');
  focusedIdx = -1;
}

document.getElementById('searchInput').addEventListener('input', e => {
  renderSuggestions(e.target.value.trim());
});

document.getElementById('searchInput').addEventListener('keydown', e => {
  const items = document.querySelectorAll('.suggestion-item');
  if (!items.length) return;
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    focusedIdx = Math.min(focusedIdx + 1, items.length - 1);
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    focusedIdx = Math.max(focusedIdx - 1, 0);
  } else if (e.key === 'Enter' && focusedIdx >= 0) {
    e.preventDefault();
    selectPlayer(items[focusedIdx].dataset.name);
    return;
  } else if (e.key === 'Escape') {
    closeSearch(); return;
  }
  items.forEach((el,i) => el.classList.toggle('focused', i === focusedIdx));
  if (focusedIdx >= 0) items[focusedIdx].scrollIntoView({block:'nearest'});
});

document.getElementById('searchCancel').addEventListener('click', closeSearch);
document.getElementById('searchOverlay').addEventListener('click', e => { if (e.target === e.currentTarget) closeSearch(); });

function selectPlayer(name) {
  if (!activeCell || finished) { closeSearch(); return; }
  const {r, c} = activeCell;
  const key  = `${r}_${c}`;
  const valid = VALID_MAP[key] || [];
  const isOk  = valid.includes(name.toLowerCase());

  if (isOk) {
    answers[key] = name;
    const el = document.getElementById(`cell_${r}_${c}`);
    el.classList.add('done','correct');
    el.innerHTML = `<div class="cell-player"><span class="cell-player-icon">🏀</span><div class="cell-player-name">${name}</div></div>`;
    closeSearch();
    checkFinish();
  } else {
    tries = Math.max(0, tries - 1);
    const el = document.getElementById(`cell_${r}_${c}`);
    el.classList.add('wrong-flash');
    setTimeout(() => el.classList.remove('wrong-flash'), 400);
    closeSearch();
    checkFinish();
  }
  updateUI();
  saveState();
}

function checkFinish() {
  const correct = Object.values(answers).filter(Boolean).length;
  const allDone = correct === 9;
  const noTries = tries === 0;
  if (!allDone && !noTries) return;
  finished = true;
  setTimeout(() => showResult(allDone), 350);
  saveState(true, allDone, !allDone);
}

function showResult(won) {
  const correct = Object.values(answers).filter(Boolean).length;
  const usedTries = 9 - tries;
  const pts = won ? calcPoints(correct, tries) : Math.round(calcPoints(correct, tries) * 0.5);

  document.getElementById('resultIcon').textContent    = won ? '🏆' : correct >= 5 ? '🏅' : '😔';
  document.getElementById('resultTitle').textContent   = won ? 'Grade Completa!' : correct >= 5 ? 'Bom jogo!' : 'Fim de jogo';
  document.getElementById('resultSubtitle').textContent= won
    ? `Você completou a grade com ${usedTries} tentativa${usedTries===1?'':'s'}!`
    : `Você acertou ${correct} de 9 células.`;
  document.getElementById('rsCells').textContent    = `${correct}/9`;
  document.getElementById('rsTries').textContent    = usedTries;
  document.getElementById('rsPoints').textContent   = pts;

  // Mini-grid de resultado
  let html = '';
  for (let r=0;r<3;r++) for (let c=0;c<3;c++) {
    const key = `${r}_${c}`;
    const ans = answers[key];
    html += `<div class="result-cell ${ans ? 'ok' : 'empty'}">${ans ? '🏀' : '—'}<br><small style="font-size:8px">${ans || ''}</small></div>`;
  }
  document.getElementById('resultAnswerGrid').innerHTML = html;
  document.getElementById('resultOverlay').classList.remove('hidden');
}

function saveState(forceSave = false, concluido = false, desistiu = false) {
  const correct = Object.values(answers).filter(Boolean).length;
  const pts = calcPoints(correct, tries);
  const respostas = Object.entries(answers)
    .filter(([,v]) => v)
    .map(([k,v]) => ({key: k, player: v}));

  const body = new FormData();
  body.append('action','save');
  body.append('respostas', JSON.stringify(respostas));
  body.append('tentativas_restantes', tries);
  body.append('concluido', concluido ? 1 : 0);
  body.append('desistiu', desistiu ? 1 : 0);
  body.append('pontos', pts);
  fetch('', {method:'POST', body});
}

// Inicializa
updateUI();
if (JA_TERMINOU) {
  const correct = Object.values(answers).filter(Boolean).length;
  setTimeout(() => showResult(correct === 9), 300);
}

// Auto-save periódico (a cada 60s se em progresso)
setInterval(() => { if (!finished) saveState(); }, 60000);
</script>
</body>
</html>
