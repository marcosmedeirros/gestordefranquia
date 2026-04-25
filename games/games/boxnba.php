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
    // --- LENDAS CLÁSSICAS ---
    ['n'=>'Kareem Abdul-Jabbar',     't'=>['MIL','LAL'],                               'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','SCORING','DPOY']],
    ['n'=>'David Robinson',          't'=>['SAS'],                                      'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','DPOY','SCORING','ROY']],
    ['n'=>'James Worthy',            't'=>['LAL'],                                      'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Robert Horry',            't'=>['HOU','LAL','PHX','SAS'],                   'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Alonzo Mourning',         't'=>['CHA','MIA','NJN','TOR'],                   'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Derek Fisher',            't'=>['LAL','OKC','UTA','MEM','NYK','DAL'],       'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Horace Grant',            't'=>['CHI','ORL','LAL','SEA'],                   'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Moses Malone',            't'=>['HOU','PHI','WAS','ATL','MIL'],             'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Jerry West',              't'=>['LAL'],                                      'c'=>'USA','a'=>['ALLSTAR','FINALS_MVP']],
    ['n'=>'Elgin Baylor',            't'=>['LAL'],                                      'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Bob Cousy',               't'=>['BOS'],                                      'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Julius Erving',           't'=>['PHI'],                                      'c'=>'USA','a'=>['MVP','ALLSTAR','FINALS_MVP']],
    ['n'=>'Kevin McHale',            't'=>['BOS'],                                      'c'=>'USA','a'=>['CHAMPION','ALLSTAR','SIXTHMAN']],
    ['n'=>'Bernard King',            't'=>['NJN','UTA','NYK','WAS','NJN'],             'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Dominique Wilkins',       't'=>['ATL','LAC','BOS','SAS','ORL'],             'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Dan Majerle',             't'=>['PHX','CLE','MIA'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Mitch Richmond',          't'=>['GSW','SAC','WAS','LAL','NYK'],             'c'=>'USA','a'=>['ALLSTAR','SCORING','ROY']],
    ['n'=>'Muggsy Bogues',           't'=>['WAS','CHA','GSW','TOR'],                   'c'=>'USA','a'=>[]],
    ['n'=>'Detlef Schrempf',         't'=>['DAL','IND','SEA','POR'],                   'c'=>'GER','a'=>['ALLSTAR','SIXTHMAN']],
    ['n'=>'Drazen Petrovic',         't'=>['POR','NJN'],                               'c'=>'CRO','a'=>[]],
    ['n'=>'Toni Kukoc',              't'=>['CHI','PHI','ATL','MIL'],                   'c'=>'CRO','a'=>['CHAMPION','SIXTHMAN']],
    ['n'=>'Dino Radja',              't'=>['BOS'],                                      'c'=>'CRO','a'=>['ALLSTAR']],
    ['n'=>'Arvydas Sabonis',         't'=>['POR'],                                      'c'=>'LIT','a'=>['ALLSTAR']],
    ['n'=>'Sarunas Marciulionis',    't'=>['GSW','SEA','SAC','HOU'],                   'c'=>'LIT','a'=>['ALLSTAR']],
    ['n'=>'Julius Randle',           't'=>['LAL','NOP','NYK','MIN'],                   'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Peja Stojakovic',         't'=>['SAC','NOP','IND','TOR','DAL'],             'c'=>'SER','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Predrag Stojakovic',      't'=>['SAC'],                                     'c'=>'SER','a'=>[]],
    // --- CLÁSSICOS 50s–70s ---
    ['n'=>'Oscar Robertson',         't'=>['CIN','MIL'],                               'c'=>'USA','a'=>['MVP','ALLSTAR','ROY']],
    ['n'=>'Pete Maravich',           't'=>['ATL','NOP','UTA'],                         'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'John Havlicek',           't'=>['BOS'],                                     'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP']],
    ['n'=>'Walt Frazier',            't'=>['NYK','CLE'],                               'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Willis Reed',             't'=>['NYK'],                                     'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP']],
    ['n'=>'Rick Barry',              't'=>['SFW','GSW','HOU'],                         'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP','SCORING']],
    ['n'=>'Bill Walton',             't'=>['POR','LAC','BOS'],                         'c'=>'USA','a'=>['MVP','ALLSTAR','CHAMPION','FINALS_MVP']],
    ['n'=>'Dave Cowens',             't'=>['BOS','MIL'],                               'c'=>'USA','a'=>['MVP','ALLSTAR','CHAMPION','ROY']],
    ['n'=>'Elvin Hayes',             't'=>['WAS','HOU'],                               'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Jo Jo White',             't'=>['BOS'],                                     'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP']],
    ['n'=>'Nate Archibald',          't'=>['CIN','KCO','NJN','BOS'],                  'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Bob McAdoo',              't'=>['BUF','NYK','BOS','DET','NJN','LAL'],       'c'=>'USA','a'=>['MVP','ALLSTAR','CHAMPION','SCORING']],
    ['n'=>'George Gervin',           't'=>['SAS'],                                     'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Dave Bing',               't'=>['DET','WAS','BOS'],                         'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Bob Lanier',              't'=>['DET','MIL'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Hal Greer',               't'=>['PHI'],                                     'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Gail Goodrich',           't'=>['LAL'],                                     'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Spencer Haywood',         't'=>['SEA','NYK'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Sam Jones',               't'=>['BOS'],                                     'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'K.C. Jones',              't'=>['BOS'],                                     'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Tom Heinsohn',            't'=>['BOS'],                                     'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Connie Hawkins',          't'=>['PHX','LAL','ATL'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Jack Sikma',              't'=>['SEA','MIL'],                               'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Gus Williams',            't'=>['GSW','SEA','WAS'],                         'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Dennis Johnson',          't'=>['SEA','PHX','BOS'],                         'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP','DPOY']],
    ['n'=>'Robert Parish',           't'=>['GSW','BOS','CHA','CHI'],                   'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    // --- CLÁSSICOS 80s ---
    ['n'=>'Adrian Dantley',          't'=>['LAL','UTA','DET'],                         'c'=>'USA','a'=>['ALLSTAR','SCORING','ROY']],
    ['n'=>'Alex English',            't'=>['DEN'],                                     'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Sidney Moncrief',         't'=>['MIL'],                                     'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Fat Lever',               't'=>['DEN','DAL'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Rolando Blackman',        't'=>['DAL','NYK'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Mark Price',              't'=>['CLE'],                                     'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Brad Daugherty',          't'=>['CLE'],                                     'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Tom Chambers',            't'=>['SEA','PHX','UTA'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Byron Scott',             't'=>['LAL','IND','SAS'],                         'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Michael Cooper',          't'=>['LAL'],                                     'c'=>'USA','a'=>['CHAMPION','DPOY']],
    ['n'=>'Ricky Pierce',            't'=>['MIL','SEA'],                               'c'=>'USA','a'=>['ALLSTAR','SIXTHMAN']],
    ['n'=>'Danny Manning',           't'=>['LAC','ATL','PHX','NYK','MIL','UTA'],       'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Mark Aguirre',            't'=>['DAL','DET','LAL'],                         'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Kiki Vandeweghe',         't'=>['DEN','POR','NYK','LAC'],                   'c'=>'USA','a'=>['ALLSTAR']],
    // --- 90s ---
    ['n'=>'Penny Hardaway',          't'=>['ORL','PHX','NYK','MIA'],                   'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Chris Webber',            't'=>['GSW','WAS','SAC','PHI','DET'],             'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Vin Baker',               't'=>['MIL','SEA','BOS'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Glen Rice',               't'=>['MIA','CHA','LAL','HOU','NYK'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Latrell Sprewell',        't'=>['GSW','NYK','MIN'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Kevin Johnson',           't'=>['SAC','PHX'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Damon Stoudamire',        't'=>['TOR','POR','MEM','SAS'],                   'c'=>'USA','a'=>['ROY']],
    ['n'=>'Stephon Marbury',         't'=>['MIN','NJN','PHX','NYK','BOS'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Michael Redd',            't'=>['MIL'],                                     'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Allan Houston',           't'=>['DET','NYK'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Jason Terry',             't'=>['ATL','DAL','BOS','BKN','MIL','HOU'],       'c'=>'USA','a'=>['CHAMPION','SIXTHMAN']],
    ['n'=>'Jamal Mashburn',          't'=>['DAL','MIA','NOP'],                         'c'=>'USA','a'=>['ALLSTAR']],
    // --- 2000s ---
    ['n'=>'Yao Ming',                't'=>['HOU'],                                     'c'=>'CHN','a'=>['ALLSTAR']],
    ['n'=>'Chauncey Billups',        't'=>['DET','DEN','NYK','LAC'],                   'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP']],
    ['n'=>'Ben Wallace',             't'=>['WAS','ORL','DET','CHI','CLE'],             'c'=>'USA','a'=>['ALLSTAR','CHAMPION','DPOY']],
    ['n'=>'Richard Hamilton',        't'=>['WAS','DET','CHI'],                         'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Rasheed Wallace',         't'=>['WAS','POR','ATL','DET','BOS'],             'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Gilbert Arenas',          't'=>['GSW','WAS','ORL'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Antawn Jamison',          't'=>['GSW','WAS','CLE','LAC'],                   'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Steve Francis',           't'=>['HOU','ORL','NYK'],                         'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Amare Stoudemire',        't'=>['PHX','NYK'],                               'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Baron Davis',             't'=>['CHA','NOP','GSW','LAC','CLE','NYK'],       'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Marcus Camby',            't'=>['TOR','NYK','DEN','LAC'],                   'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Jermaine O\'Neal',        't'=>['POR','IND','MIA','TOR','PHX','BOS'],       'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Zach Randolph',           't'=>['POR','NYK','LAC','MEM','SAC'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Andre Iguodala',          't'=>['PHI','DEN','GSW','MIA'],                   'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP']],
    ['n'=>'Paul Millsap',            't'=>['UTA','ATL','DEN'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Mike Conley',             't'=>['MEM','UTA'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Monta Ellis',             't'=>['GSW','MIL','DAL','IND'],                   'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Luol Deng',               't'=>['CHI','CLE','MIA','LAL','MIN'],             'c'=>'SUD','a'=>['ALLSTAR']],
    ['n'=>'Serge Ibaka',             't'=>['OKC','ORL','TOR','LAC'],                   'c'=>'COG','a'=>['CHAMPION','ALLSTAR']],
    // --- 2010s ---
    ['n'=>'Blake Griffin',           't'=>['LAC','DET','BKN'],                         'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'John Wall',               't'=>['WAS','HOU'],                               'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Kemba Walker',            't'=>['CHA','BOS','NYK'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Bradley Beal',            't'=>['WAS','PHX'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Kevin Love',              't'=>['MIN','CLE'],                               'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Marc Gasol',              't'=>['MEM','TOR','LAL'],                         'c'=>'ESP','a'=>['ALLSTAR','CHAMPION','DPOY']],
    ['n'=>'Zydrunas Ilgauskas',      't'=>['CLE','MIA'],                               'c'=>'LIT','a'=>['ALLSTAR']],
    ['n'=>'Shareef Abdur-Rahim',     't'=>['ATL','SAC','POR','NJN'],                   'c'=>'USA','a'=>['ALLSTAR']],
    // --- AUSTRALIANOS ---
    ['n'=>'Andrew Bogut',            't'=>['MIL','GSW','DAL','LAL','PHI'],             'c'=>'AUS','a'=>['CHAMPION','ROY']],
    ['n'=>'Patty Mills',             't'=>['SAS','BKN','ATL'],                         'c'=>'AUS','a'=>['CHAMPION']],
    ['n'=>'Ben Simmons',             't'=>['PHI','BKN'],                               'c'=>'AUS','a'=>['ALLSTAR','ROY']],
    ['n'=>'Joe Ingles',              't'=>['UTA','MIL'],                               'c'=>'AUS','a'=>['CHAMPION']],
    ['n'=>'Matthew Dellavedova',     't'=>['CLE','MIL','SAC'],                         'c'=>'AUS','a'=>['CHAMPION']],
    ['n'=>'Aron Baynes',             't'=>['SAS','DET','BOS','PHX'],                   'c'=>'AUS','a'=>[]],
    ['n'=>'Luc Longley',             't'=>['MIN','CHI','PHX'],                         'c'=>'AUS','a'=>['CHAMPION']],
    ['n'=>'Josh Giddey',             't'=>['OKC','CHI'],                               'c'=>'AUS','a'=>[]],
    // --- 2020s ---
    ['n'=>'LaMelo Ball',             't'=>['CHA'],                                     'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Ja Morant',               't'=>['MEM'],                                     'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Tyrese Haliburton',       't'=>['SAC','IND'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'De\'Aaron Fox',           't'=>['SAC'],                                     'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Scottie Barnes',          't'=>['TOR'],                                     'c'=>'USA','a'=>['ROY']],
    ['n'=>'Evan Mobley',             't'=>['CLE'],                                     'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Cade Cunningham',         't'=>['DET'],                                     'c'=>'USA','a'=>['ROY']],
    ['n'=>'Franz Wagner',            't'=>['ORL'],                                     'c'=>'GER','a'=>['ALLSTAR']],
    ['n'=>'Alperen Sengun',          't'=>['HOU'],                                     'c'=>'TUR','a'=>['ALLSTAR']],
    // --- PRÉ-1980 ---
    ['n'=>'Wes Unseld',              't'=>['BAL','WAS'],                               'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','ROY','FINALS_MVP']],
    ['n'=>'Jerry Lucas',             't'=>['CIN','SFW','NYK'],                         'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Dave DeBusschere',        't'=>['DET','NYK'],                               'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Bill Bradley',            't'=>['NYK'],                                     'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Earl Monroe',             't'=>['BAL','NYK'],                               'c'=>'USA','a'=>['ALLSTAR','CHAMPION','ROY']],
    ['n'=>'Bob Pettit',              't'=>['STL'],                                     'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING']],
    ['n'=>'Billy Cunningham',        't'=>['PHI'],                                     'c'=>'USA','a'=>['MVP','ALLSTAR','CHAMPION']],
    ['n'=>'Dolph Schayes',           't'=>['SYR','PHI'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Nate Thurmond',           't'=>['SFW','GSW','CHI','CLE'],                   'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Lenny Wilkens',           't'=>['STL','SEA','CLE','POR'],                   'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Chet Walker',             't'=>['PHI','CHI'],                               'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Bob Love',                't'=>['CHI'],                                     'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Norm Van Lier',           't'=>['CIN','CHI'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Jerry Sloan',             't'=>['CHI','BAL'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Paul Silas',              't'=>['STL','PHX','BOS','DEN','SEA'],             'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Dan Issel',               't'=>['DEN'],                                     'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Bailey Howell',           't'=>['DET','BAL','BOS'],                         'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Walt Bellamy',            't'=>['CHI','NYK','ATL'],                         'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Gus Johnson',             't'=>['BAL','PHX','SEA'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Bob Boozer',              't'=>['CIN','CHI','NYK'],                         'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Paul Arizin',             't'=>['PHW'],                                     'c'=>'USA','a'=>['ALLSTAR','CHAMPION','SCORING']],
    ['n'=>'Neil Johnston',           't'=>['PHW'],                                     'c'=>'USA','a'=>['ALLSTAR','CHAMPION','SCORING']],
    ['n'=>'Maurice Lucas',           't'=>['POR','NJN','NYK','PHX','LAL','SEA'],       'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Bob Dandridge',           't'=>['MIL','WAS'],                               'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Luol Deng',               't'=>['CHI','CLE','MIA','LAL','MIN'],             'c'=>'SUD','a'=>['ALLSTAR']],
    // --- 1980s ---
    ['n'=>'Bill Laimbeer',           't'=>['CLE','DET'],                               'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Joe Dumars',              't'=>['DET'],                                     'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP']],
    ['n'=>'Vinnie Johnson',          't'=>['SEA','DET','SAS'],                         'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'James Edwards',           't'=>['LAL','DET'],                               'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Alvin Robertson',         't'=>['SAS','MIL','DEN','TOR'],                   'c'=>'USA','a'=>['ALLSTAR','DPOY','ROY']],
    ['n'=>'Terry Cummings',          't'=>['SDC','MIL','SAS'],                         'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Kevin Willis',            't'=>['ATL','HOU','SAC','TOR','MIA'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Xavier McDaniel',         't'=>['SEA','PHX','BOS','NYK'],                   'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Dale Ellis',              't'=>['DAL','SEA','MIL','SAS'],                   'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Chuck Person',            't'=>['IND','MIN','SAS'],                         'c'=>'USA','a'=>['ROY']],
    ['n'=>'Ron Harper',              't'=>['CLE','LAC','CHI','LAL'],                   'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Steve Kerr',              't'=>['PHX','CLE','ORL','CHI','SAS'],             'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'John Paxson',             't'=>['SAS','CHI'],                               'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'B.J. Armstrong',          't'=>['CHI','GSW','CHA'],                         'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Hersey Hawkins',          't'=>['PHI','CHA','SEA','CHI'],                   'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Kevin Duckworth',         't'=>['POR','WAS','LAC'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Danny Ainge',             't'=>['BOS','SAC','POR','PHX'],                   'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Mark Eaton',              't'=>['UTA'],                                     'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Larry Nance',             't'=>['PHX','CLE'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'World B. Free',           't'=>['PHI','SDC','GSW','CLE','HOU'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Purvis Short',            't'=>['GSW','HOU','NJN'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Terry Porter',            't'=>['POR','MIN','MIA','SAS','DET'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Sleepy Floyd',            't'=>['GSW','HOU','SAS','NJN','PHI'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Mike Mitchell',           't'=>['CLE','SAS','ATL'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Otis Birdsong',           't'=>['KCO','NJN','BOS'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Bob McAdoo',              't'=>['BUF','NYK','BOS','DET','NJN','LAL'],       'c'=>'USA','a'=>['MVP','ALLSTAR','CHAMPION','SCORING']],
    // --- 1990s ---
    ['n'=>'Glenn Robinson',          't'=>['MIL','ATL','PHI','SAS'],                   'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Elton Brand',             't'=>['CHI','LAC','PHI','DAL','ATL'],             'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Jerry Stackhouse',        't'=>['PHI','DET','WAS','DAL','MEM','MIL'],       'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Antonio McDyess',         't'=>['DEN','PHX','NYK','DET','SAS'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Shawn Marion',            't'=>['PHX','MIA','DAL','TOR','CLE'],             'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Lamar Odom',              't'=>['LAC','MIA','LAL','DAL'],                   'c'=>'USA','a'=>['CHAMPION','SIXTHMAN']],
    ['n'=>'J.R. Smith',              't'=>['NOP','DEN','NYK','CLE','LAL'],             'c'=>'USA','a'=>['CHAMPION','SIXTHMAN']],
    ['n'=>'Richard Jefferson',       't'=>['NJN','SAS','GSW','MIL','DEN','UTA','CLE'],'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Jason Williams',          't'=>['SAC','MEM','MIA','ORL'],                   'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Carlos Boozer',           't'=>['CLE','UTA','CHI','LAL','PHX'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Josh Smith',              't'=>['ATL','DET','HOU','LAC','NOP'],             'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Caron Butler',            't'=>['MIA','WAS','LAL','DAL','LAC','MEM'],       'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Brad Miller',             't'=>['IND','CHI','SAC','HOU','MIN'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Bobby Jackson',           't'=>['DEN','MIN','SAC','NOP','HOU','MEM'],       'c'=>'USA','a'=>['SIXTHMAN']],
    ['n'=>'Hedo Turkoglu',           't'=>['SAS','ORL','TOR','PHX','MEM'],             'c'=>'TUR','a'=>['ALLSTAR']],
    ['n'=>'Mehmet Okur',             't'=>['DET','UTA'],                               'c'=>'TUR','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Andrew Bynum',            't'=>['LAL','PHI','CLE','IND'],                   'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Mike Miller',             't'=>['ORL','MEM','MIN','WAS','MIA','CLE','PHX'], 'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Shane Battier',           't'=>['MEM','HOU','MIA','BKN'],                   'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Mario Chalmers',          't'=>['MIA','MEM','ORL'],                         'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Udonis Haslem',           't'=>['MIA'],                                     'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Chris Andersen',          't'=>['DEN','NOP','MIA','CLE','LAL'],             'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Antoine Walker',          't'=>['BOS','DAL','ATL','MIA','MIN','MEM'],       'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Rashard Lewis',           't'=>['SEA','ORL','WAS','MIA','NOP'],             'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Wally Szczerbiak',        't'=>['MIN','BOS','SEA','CLE'],                   'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Danny Fortson',           't'=>['DEN','GSW','DAL','SEA'],                   'c'=>'USA','a'=>[]],
    ['n'=>'Michael Finley',          't'=>['PHX','DAL','SAS','MIL'],                   'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Jon Barry',               't'=>['BOS','ATL','LAC','CLE','SAC','HOU','DET','DEN'],'c'=>'USA','a'=>[]],
    ['n'=>'Doug Christie',           't'=>['NYK','TOR','SAC','ORL','DAL'],             'c'=>'USA','a'=>['ALLSTAR']],
    // --- 2000s ---
    ['n'=>'Joe Johnson',             't'=>['BOS','PHX','ATL','BKN','MIA','UTA','HOU'], 'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Al Horford',              't'=>['ATL','BOS','PHI','OKC','MIA','BKN'],       'c'=>'DOM','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Joakim Noah',             't'=>['CHI','NYK','MEM'],                         'c'=>'FRA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'David Lee',               't'=>['NYK','GSW','BOS','DAL'],                   'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Josh Howard',             't'=>['DAL','WAS','LAL'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Jamal Crawford',          't'=>['CHI','NYK','GSW','ATL','LAC','MIN','PHX'], 'c'=>'USA','a'=>['SIXTHMAN']],
    ['n'=>'DeAndre Jordan',          't'=>['LAC','NYK','DAL','BKN','PHI','LAL'],       'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Iman Shumpert',           't'=>['NYK','CLE','SAC','HOU','BKN'],             'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Tristan Thompson',        't'=>['CLE','BOS','SAC','IND','CHI'],             'c'=>'CAN','a'=>['CHAMPION']],
    ['n'=>'Harrison Barnes',         't'=>['GSW','DAL','SAC','WAS'],                   'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'James Posey',             't'=>['DEN','HOU','MIA','NOP','BOS'],             'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Damon Jones',             't'=>['HOU','MIA','CLE','MIL','LAL'],             'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Luke Walton',             't'=>['LAL','CLE','SAC'],                         'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Quentin Richardson',      't'=>['LAC','PHX','NYK','MIA','OKC'],             'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Gary Payton II',          't'=>['GSW','MIL','POR','LAC'],                   'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Jordan Poole',            't'=>['GSW','WAS','LAC'],                         'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Kevon Looney',            't'=>['GSW'],                                     'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'JaVale McGee',            't'=>['WAS','DEN','PHI','GSW','LAL','CLE','PHX'], 'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Zaza Pachulia',           't'=>['MIL','ATL','DAL','GSW','DET'],             'c'=>'GEO','a'=>['CHAMPION']],
    ['n'=>'Thabo Sefolosha',         't'=>['CHI','OKC','ATL','UTA','HOU'],             'c'=>'RSA','a'=>['CHAMPION']],
    ['n'=>'Andre Miller',            't'=>['CLE','LAC','DEN','PHI','POR','WAS','SAC'], 'c'=>'USA','a'=>[]],
    ['n'=>'Nate Robinson',           't'=>['NYK','OKC','BOS','CHI','DEN','MEM'],       'c'=>'USA','a'=>[]],
    ['n'=>'Kendrick Perkins',        't'=>['BOS','OKC','CLE','NOP','UTA'],             'c'=>'USA','a'=>['CHAMPION']],
    // --- 2010s ---
    ['n'=>'Gordon Hayward',          't'=>['UTA','BOS','CHA','MIA'],                   'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'D\'Angelo Russell',       't'=>['LAL','BKN','MIN','GSW','ATL','LAC'],       'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Kristaps Porzingis',      't'=>['NYK','DAL','WAS','BOS','MIA'],             'c'=>'LAT','a'=>['ALLSTAR']],
    ['n'=>'Tobias Harris',           't'=>['MIL','ORL','LAC','DET','PHI'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Eric Gordon',             't'=>['LAC','NOP','HOU','DEN'],                   'c'=>'USA','a'=>['ALLSTAR','SIXTHMAN']],
    ['n'=>'Jordan Clarkson',         't'=>['LAL','CLE','UTA'],                         'c'=>'PHI','a'=>['SIXTHMAN']],
    ['n'=>'Malcolm Brogdon',         't'=>['MIL','IND','BOS','LAC'],                   'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Mikal Bridges',           't'=>['PHX','BKN','NYK'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'CJ McCollum',             't'=>['POR','NOP','LAC'],                         'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Al Jefferson',            't'=>['BOS','MIN','UTA','CHA','IND'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Brandon Ingram',          't'=>['LAL','NOP'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'OG Anunoby',              't'=>['TOR','NYK'],                               'c'=>'GBR','a'=>['ALLSTAR']],
    ['n'=>'Hassan Whiteside',        't'=>['MIA','POR','SAC','LAL','UTA'],             'c'=>'USA','a'=>[]],
    ['n'=>'Nikola Mirotic',          't'=>['CHI','NOP','MIL'],                         'c'=>'SPA','a'=>['ALLSTAR']],
    ['n'=>'Seth Curry',              't'=>['POR','SAC','DAL','PHI','BKN'],             'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Draymond Green',          't'=>['GSW'],                                     'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Eric Bledsoe',            't'=>['LAC','PHX','MIL','NOP','LAC'],             'c'=>'USA','a'=>[]],
    ['n'=>'George Hill',             't'=>['SAS','IND','UTA','SAC','CLE','MIL','OKC'], 'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Jeff Teague',             't'=>['ATL','IND','MIN','OKC','MIL','PHI'],       'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Lou Williams',            't'=>['PHI','ATL','TOR','LAL','HOU','LAC'],       'c'=>'USA','a'=>['SIXTHMAN']],
    ['n'=>'Montrezl Harrell',        't'=>['HOU','LAC','LAL','WAS','CHA','PHI'],       'c'=>'USA','a'=>['SIXTHMAN']],
    ['n'=>'Robert Covington',        't'=>['HOU','PHI','MIN','POR','HOU','LAC','WAS'], 'c'=>'USA','a'=>[]],
    ['n'=>'Kevin Martin',            't'=>['SAC','HOU','OKC','MIN','SAS'],             'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'DeMarre Carroll',         't'=>['MEM','UTA','ATL','TOR','BKN','BOS'],       'c'=>'USA','a'=>[]],
    ['n'=>'Marvin Williams',         't'=>['ATL','UTA','CHA','MIL'],                   'c'=>'USA','a'=>[]],
    ['n'=>'Nick Young',              't'=>['WAS','LAC','PHI','LAL','GSW','DEN'],       'c'=>'USA','a'=>['CHAMPION']],
    // --- INTERNACIONAIS ADICIONAIS ---
    ['n'=>'Danilo Gallinari',        't'=>['NYK','DEN','LAC','OKC','ATL','BOS','MIL'], 'c'=>'ITA','a'=>['ALLSTAR']],
    ['n'=>'Marco Belinelli',         't'=>['GSW','CHI','NOP','TOR','SAS','ATL','SAC','PHI','CHA'],'c'=>'ITA','a'=>['CHAMPION']],
    ['n'=>'Andrea Bargnani',         't'=>['TOR','NYK','BKN','LAC'],                   'c'=>'ITA','a'=>['ROY']],
    ['n'=>'Jonas Valanciunas',       't'=>['TOR','MEM','NOP'],                         'c'=>'LIT','a'=>['ALLSTAR']],
    ['n'=>'Dario Saric',             't'=>['PHI','PHX','GSW','SAC'],                   'c'=>'CRO','a'=>[]],
    ['n'=>'Bojan Bogdanovic',        't'=>['BKN','WAS','IND','UTA'],                   'c'=>'CRO','a'=>[]],
    ['n'=>'Yi Jianlian',             't'=>['MIL','NJN','DAL','WAS'],                   'c'=>'CHN','a'=>[]],
    ['n'=>'Wang Zhizhi',             't'=>['DAL','MIA','DEN'],                         'c'=>'CHN','a'=>[]],
    ['n'=>'Ricky Rubio',             't'=>['MIN','UTA','PHX','CLE','BKN'],             'c'=>'ESP','a'=>[]],
    ['n'=>'Gorgui Dieng',            't'=>['MIN','PHX','HOU','MEM','SAS'],             'c'=>'SEN','a'=>[]],
    ['n'=>'Bismack Biyombo',         't'=>['CHA','TOR','ORL','PHX','HOU'],             'c'=>'COD','a'=>[]],
    ['n'=>'Rudy Fernandez',          't'=>['POR','DAL','DEN'],                         'c'=>'ESP','a'=>[]],
    ['n'=>'Victor Oladipo',          't'=>['ORL','OKC','IND','HOU','MIA','BKN','NOP'], 'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Myles Turner',            't'=>['IND'],                                     'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Domantas Sabonis',        't'=>['OKC','IND','SAC'],                         'c'=>'LIT','a'=>['ALLSTAR','SIXTHMAN']],
    ['n'=>'Saddiq Bey',              't'=>['DET','ATL','LAL'],                         'c'=>'USA','a'=>[]],
    ['n'=>'Naz Reid',                't'=>['MIN'],                                     'c'=>'USA','a'=>['SIXTHMAN']],
    ['n'=>'Immanuel Quickley',       't'=>['NYK','TOR'],                               'c'=>'USA','a'=>[]],
    ['n'=>'RJ Barrett',              't'=>['NYK','TOR'],                               'c'=>'CAN','a'=>[]],
    ['n'=>'Herb Jones',              't'=>['NOP'],                                     'c'=>'USA','a'=>[]],
    ['n'=>'Jalen Green',             't'=>['HOU'],                                     'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Paolo Banchero',          't'=>['ORL'],                                     'c'=>'USA','a'=>['ROY']],
    ['n'=>'Walker Kessler',          't'=>['UTA'],                                     'c'=>'USA','a'=>[]],
    ['n'=>'Scoot Henderson',         't'=>['POR'],                                     'c'=>'USA','a'=>[]],
    ['n'=>'Victor Wembanyama',       't'=>['SAS'],                                     'c'=>'FRA','a'=>['ROY']],
    ['n'=>'Brandon Miller',          't'=>['CHA'],                                     'c'=>'USA','a'=>[]],
    ['n'=>'Ausar Thompson',          't'=>['DET'],                                     'c'=>'USA','a'=>[]],
    ['n'=>'Amen Thompson',           't'=>['HOU'],                                     'c'=>'USA','a'=>[]],
    ['n'=>'Jarace Walker',           't'=>['IND'],                                     'c'=>'USA','a'=>[]],
    ['n'=>'Taylor Hawkins',          't'=>['ATL'],                                     'c'=>'USA','a'=>[]],
    ['n'=>'Jose Juan Barea',         't'=>['DAL','MIN','NOP'],                         'c'=>'PRI','a'=>['CHAMPION']],
    ['n'=>'Carlos Arroyo',           't'=>['UTA','DET','ORL','MIA','HOU'],             'c'=>'PRI','a'=>['CHAMPION']],
    ['n'=>'Goran Dragic',            't'=>['PHX','MIA','TOR','BKN','CHI'],             'c'=>'SLO','a'=>['ALLSTAR']],
    ['n'=>'Anthony Morrow',          't'=>['GSW','NJN','NOP','OKC','ATL','CHI'],       'c'=>'USA','a'=>[]],
    ['n'=>'Steve Blake',             't'=>['WAS','POR','DEN','MIL','LAL','GSW','UTA'], 'c'=>'USA','a'=>[]],
    ['n'=>'Nene Hilario',            't'=>['DEN','WAS','HOU'],                         'c'=>'BRA','a'=>['ALLSTAR']],
    ['n'=>'Leandro Barbosa',         't'=>['PHX','TOR','IND','GSW','BOS'],             'c'=>'BRA','a'=>['SIXTHMAN']],
    ['n'=>'Bruno Caboclo',           't'=>['TOR','MEM','SAC','HOU','IND','MIA'],       'c'=>'BRA','a'=>[]],
    ['n'=>'Cristiano Felício',       't'=>['CHI','LAC'],                               'c'=>'BRA','a'=>[]],
    ['n'=>'Raul Neto',               't'=>['UTA','PHI','WAS','CLE'],                   'c'=>'BRA','a'=>[]],
    ['n'=>'Marcelinho Huertas',      't'=>['LAL'],                                     'c'=>'BRA','a'=>[]],
    ['n'=>'Leandrinho Barbosa',      't'=>['PHX','TOR','IND','GSW','BOS'],             'c'=>'BRA','a'=>[]],
    ['n'=>'Larry Nance Jr.',         't'=>['LAL','CLE','POR','NOP','ATL'],             'c'=>'USA','a'=>[]],
    ['n'=>'Gary Harris',             't'=>['DEN','ORL','MIA'],                         'c'=>'USA','a'=>[]],
    ['n'=>'Jarrett Allen',           't'=>['BKN','CLE'],                               'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Darius Garland',          't'=>['CLE'],                                     'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Jaylen Nowell',           't'=>['MIN'],                                     'c'=>'USA','a'=>[]],
    ['n'=>'Jordan Nwora',            't'=>['MIL','POR','HOU'],                         'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Khris Middleton',         't'=>['DET','MIL'],                               'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Fred VanVleet',           't'=>['TOR','HOU'],                               'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Norman Powell',           't'=>['TOR','POR','LAC'],                         'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Marc Gasol',              't'=>['MEM','TOR','LAL'],                         'c'=>'ESP','a'=>['ALLSTAR','CHAMPION','DPOY']],
    ['n'=>'Kyle Lowry',              't'=>['MEM','HOU','TOR','MIA','PHI','MIN'],       'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'OG Anunoby',              't'=>['TOR','NYK'],                               'c'=>'GBR','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Serge Ibaka',             't'=>['OKC','ORL','TOR','LAC'],                   'c'=>'COG','a'=>['CHAMPION','ALLSTAR']],
];

// Remove duplicatas
$seen = [];
$PLAYERS = array_values(array_filter($PLAYERS, function($p) use (&$seen) {
    if (isset($seen[$p['n']])) return false;
    $seen[$p['n']] = true;
    return true;
}));

$CRITERIA = [
    ['id'=>'LAL','type'=>'team',  'label'=>'Lakers',            'icon'=>'🟡','full'=>'Los Angeles Lakers',      'nba_id'=>1610612747],
    ['id'=>'GSW','type'=>'team',  'label'=>'Warriors',          'icon'=>'💛','full'=>'Golden State Warriors',   'nba_id'=>1610612744],
    ['id'=>'BOS','type'=>'team',  'label'=>'Celtics',           'icon'=>'🍀','full'=>'Boston Celtics',          'nba_id'=>1610612738],
    ['id'=>'MIA','type'=>'team',  'label'=>'Heat',              'icon'=>'🔴','full'=>'Miami Heat',              'nba_id'=>1610612748],
    ['id'=>'SAS','type'=>'team',  'label'=>'Spurs',             'icon'=>'⚫','full'=>'San Antonio Spurs',       'nba_id'=>1610612759],
    ['id'=>'CHI','type'=>'team',  'label'=>'Bulls',             'icon'=>'🐂','full'=>'Chicago Bulls',           'nba_id'=>1610612741],
    ['id'=>'OKC','type'=>'team',  'label'=>'Thunder',           'icon'=>'⚡','full'=>'OKC Thunder',             'nba_id'=>1610612760],
    ['id'=>'DEN','type'=>'team',  'label'=>'Nuggets',           'icon'=>'⛏️','full'=>'Denver Nuggets',          'nba_id'=>1610612743],
    ['id'=>'DAL','type'=>'team',  'label'=>'Mavericks',         'icon'=>'🐎','full'=>'Dallas Mavericks',        'nba_id'=>1610612742],
    ['id'=>'TOR','type'=>'team',  'label'=>'Raptors',           'icon'=>'🦖','full'=>'Toronto Raptors',         'nba_id'=>1610612761],
    ['id'=>'PHI','type'=>'team',  'label'=>'76ers',             'icon'=>'🔔','full'=>'Philadelphia 76ers',      'nba_id'=>1610612755],
    ['id'=>'MIL','type'=>'team',  'label'=>'Bucks',             'icon'=>'🦌','full'=>'Milwaukee Bucks',         'nba_id'=>1610612749],
    ['id'=>'HOU','type'=>'team',  'label'=>'Rockets',           'icon'=>'🚀','full'=>'Houston Rockets',         'nba_id'=>1610612745],
    ['id'=>'PHX','type'=>'team',  'label'=>'Suns',              'icon'=>'☀️','full'=>'Phoenix Suns',            'nba_id'=>1610612756],
    ['id'=>'CLE','type'=>'team',  'label'=>'Cavaliers',         'icon'=>'⚔️','full'=>'Cleveland Cavaliers',     'nba_id'=>1610612739],
    ['id'=>'NYK','type'=>'team',  'label'=>'Knicks',            'icon'=>'🗽','full'=>'New York Knicks',          'nba_id'=>1610612752],
    ['id'=>'LAC','type'=>'team',  'label'=>'Clippers',          'icon'=>'✂️','full'=>'Los Angeles Clippers',    'nba_id'=>1610612746],
    ['id'=>'MIN','type'=>'team',  'label'=>'Timberwolves',      'icon'=>'🐺','full'=>'Minnesota Timberwolves',  'nba_id'=>1610612750],
    ['id'=>'NOP','type'=>'team',  'label'=>'Pelicans',          'icon'=>'🦅','full'=>'New Orleans Pelicans',    'nba_id'=>1610612740],
    ['id'=>'POR','type'=>'team',  'label'=>'Trail Blazers',     'icon'=>'🌹','full'=>'Portland Trail Blazers',   'nba_id'=>1610612757],
    ['id'=>'UTA','type'=>'team',  'label'=>'Jazz',              'icon'=>'🎵','full'=>'Utah Jazz',                'nba_id'=>1610612762],
    ['id'=>'IND','type'=>'team',  'label'=>'Pacers',            'icon'=>'🏎️','full'=>'Indiana Pacers',           'nba_id'=>1610612754],
    ['id'=>'ATL','type'=>'team',  'label'=>'Hawks',             'icon'=>'🦅','full'=>'Atlanta Hawks',            'nba_id'=>1610612737],
    ['id'=>'DET','type'=>'team',  'label'=>'Pistons',           'icon'=>'⚙️','full'=>'Detroit Pistons',          'nba_id'=>1610612765],
    ['id'=>'ORL','type'=>'team',  'label'=>'Magic',             'icon'=>'🪄','full'=>'Orlando Magic',            'nba_id'=>1610612753],
    ['id'=>'WAS','type'=>'team',  'label'=>'Wizards',           'icon'=>'🧙','full'=>'Washington Wizards',       'nba_id'=>1610612764],
    ['id'=>'SAC','type'=>'team',  'label'=>'Kings',             'icon'=>'👑','full'=>'Sacramento Kings',         'nba_id'=>1610612758],
    ['id'=>'BKN','type'=>'team',  'label'=>'Nets',              'icon'=>'🕸️','full'=>'Brooklyn Nets',            'nba_id'=>1610612751],
    ['id'=>'MEM','type'=>'team',  'label'=>'Grizzlies',         'icon'=>'🐻','full'=>'Memphis Grizzlies',        'nba_id'=>1610612763],
    ['id'=>'CHA','type'=>'team',  'label'=>'Hornets',           'icon'=>'🐝','full'=>'Charlotte Hornets',        'nba_id'=>1610612766],
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
    ['id'=>'AUS','type'=>'nation','label'=>'Austrália',         'icon'=>'🇦🇺','full'=>'Austrália'],
    ['id'=>'CHN','type'=>'nation','label'=>'China',             'icon'=>'🇨🇳','full'=>'China'],
    ['id'=>'LIT','type'=>'nation','label'=>'Lituânia',          'icon'=>'🇱🇹','full'=>'Lituânia'],
    ['id'=>'CRO','type'=>'nation','label'=>'Croácia',           'icon'=>'🇭🇷','full'=>'Croácia'],
    ['id'=>'TUR','type'=>'nation','label'=>'Turquia',           'icon'=>'🇹🇷','full'=>'Turquia'],
    ['id'=>'ITA','type'=>'nation','label'=>'Itália',            'icon'=>'🇮🇹','full'=>'Itália'],
    ['id'=>'LAT','type'=>'nation','label'=>'Letônia',           'icon'=>'🇱🇻','full'=>'Letônia'],
    ['id'=>'DOM','type'=>'nation','label'=>'Rep. Dominicana',   'icon'=>'🇩🇴','full'=>'República Dominicana'],
    ['id'=>'SEN','type'=>'nation','label'=>'Senegal',           'icon'=>'🇸🇳','full'=>'Senegal'],
    ['id'=>'PRI','type'=>'nation','label'=>'Porto Rico',        'icon'=>'🇵🇷','full'=>'Porto Rico'],
    ['id'=>'MVP',       'type'=>'award','label'=>'MVP',             'icon'=>'🏆','full'=>'MVP da Temporada'],
    ['id'=>'CHAMPION',  'type'=>'award','label'=>'Campeão',         'icon'=>'💍','full'=>'Campeão NBA'],
    ['id'=>'ALLSTAR',   'type'=>'award','label'=>'All-Star',        'icon'=>'⭐','full'=>'All-Star'],
    ['id'=>'DPOY',      'type'=>'award','label'=>'Defensor',        'icon'=>'🛡️','full'=>'Melhor Defensor (DPOY)'],
    ['id'=>'FINALS_MVP','type'=>'award','label'=>'MVP Finais',      'icon'=>'🎖️','full'=>'MVP das Finais'],
    ['id'=>'ROY',       'type'=>'award','label'=>'Calouro Ano',     'icon'=>'🌟','full'=>'Calouro do Ano (ROY)'],
    ['id'=>'SCORING',   'type'=>'award','label'=>'Artilheiro',      'icon'=>'🎯','full'=>'Artilheiro da Temporada'],
    ['id'=>'SIXTHMAN',  'type'=>'award','label'=>'6º Homem',        'icon'=>'🪑','full'=>'Sexto Homem do Ano'],
];

// NBA player IDs para headshots: https://cdn.nba.com/headshots/nba/latest/260x190/{id}.png
$PLAYER_PIDS = [
    'LeBron James'=>2544,'Stephen Curry'=>201939,'Kevin Durant'=>201142,
    'Giannis Antetokounmpo'=>203507,'Nikola Jokic'=>203999,'Luka Doncic'=>1629029,
    'Joel Embiid'=>203954,'Kawhi Leonard'=>202695,'Kobe Bryant'=>977,
    'Shaquille O\'Neal'=>406,'Dwyane Wade'=>2548,'Chris Bosh'=>76001,
    'Dirk Nowitzki'=>1717,'Tim Duncan'=>1495,'Tony Parker'=>2225,
    'Manu Ginobili'=>1938,'Kevin Garnett'=>708,'Paul Pierce'=>1718,
    'Ray Allen'=>951,'Allen Iverson'=>947,'Vince Carter'=>1713,
    'Tracy McGrady'=>1503,'Carmelo Anthony'=>2546,'Russell Westbrook'=>201566,
    'James Harden'=>201935,'Derrick Rose'=>201565,'Jimmy Butler'=>202710,
    'Paul George'=>202331,'Kyrie Irving'=>202681,'Damian Lillard'=>203081,
    'Chris Paul'=>101108,'Steve Nash'=>959,'Jason Kidd'=>714,
    'Pau Gasol'=>1932,'Rudy Gobert'=>203497,'Victor Wembanyama'=>1641705,
    'Andrew Wiggins'=>203952,'Jamal Murray'=>1627750,
    'Shai Gilgeous-Alexander'=>1628983,'DeMar DeRozan'=>201942,
    'Draymond Green'=>203110,'Klay Thompson'=>202691,'Anthony Davis'=>203076,
    'Jayson Tatum'=>1628369,'Bam Adebayo'=>1628389,'Donovan Mitchell'=>1628378,
    'Devin Booker'=>1626164,'Hakeem Olajuwon'=>165,'Charles Barkley'=>787,
    'Scottie Pippen'=>979,'Dennis Rodman'=>1007,'Patrick Ewing'=>121,
    'Dikembe Mutombo'=>137,'Magic Johnson'=>1020,'Larry Bird'=>1449,
    'Michael Jordan'=>893,'Isiah Thomas'=>262,'John Stockton'=>304,
    'Karl Malone'=>252,'Clyde Drexler'=>781,'Gary Payton'=>730,
    'Shawn Kemp'=>713,'Wilt Chamberlain'=>76375,'Bill Russell'=>76343,
    'Kareem Abdul-Jabbar'=>76003,'David Robinson'=>231,'James Worthy'=>311,
    'Robert Horry'=>400,'Alonzo Mourning'=>174,'Derek Fisher'=>2524,
    'Horace Grant'=>363,'Moses Malone'=>76550,'Julius Erving'=>76823,
    'Kevin McHale'=>76872,'Dominique Wilkins'=>775,
    'Toni Kukoc'=>758,'Peja Stojakovic'=>2038,'Julius Randle'=>203944,
    'Khris Middleton'=>203114,'Jrue Holiday'=>201950,'Trae Young'=>1629027,
    'Karl-Anthony Towns'=>1626157,'Zion Williamson'=>1629627,'Kyle Lowry'=>200768,
    'Dwight Howard'=>2730,'Rajon Rondo'=>200765,'DeMarcus Cousins'=>202326,
    'Brook Lopez'=>201572,'Jaylen Brown'=>1627759,'Anthony Edwards'=>1630162,
    'Paolo Banchero'=>1631094,'Grant Hill'=>397,'Reggie Miller'=>855,
    // Clássicos
    'Oscar Robertson'=>1003,'Pete Maravich'=>76866,'John Havlicek'=>76984,
    'Walt Frazier'=>76366,'Willis Reed'=>76963,'Rick Barry'=>76375,
    'Bill Walton'=>76561,'Dave Cowens'=>76362,'Elvin Hayes'=>76586,
    'George Gervin'=>76817,'Bob McAdoo'=>76862,'Nate Archibald'=>76328,
    'Dave Bing'=>76343,'Bob Lanier'=>77018,'Jack Sikma'=>76699,
    'Robert Parish'=>76949,'Dennis Johnson'=>76827,
    // 80s-90s
    'Adrian Dantley'=>76346,'Alex English'=>76816,'Sidney Moncrief'=>76893,
    'Rolando Blackman'=>76353,'Mark Price'=>769,'Brad Daugherty'=>76358,
    'Mark Aguirre'=>76323,'Danny Manning'=>765,'Kiki Vandeweghe'=>77153,
    'Penny Hardaway'=>769,'Chris Webber'=>768,
    'Glen Rice'=>729,'Latrell Sprewell'=>766,'Kevin Johnson'=>202,'Stephon Marbury'=>950,
    'Allan Houston'=>1003,'Jason Terry'=>1891,'Michael Redd'=>2401,
    // 2000s
    'Yao Ming'=>2397,'Chauncey Billups'=>1012,'Ben Wallace'=>2404,
    'Richard Hamilton'=>1949,'Rasheed Wallace'=>945,'Gilbert Arenas'=>2399,
    'Amare Stoudemire'=>2405,'Baron Davis'=>1884,'Marcus Camby'=>944,
    'Jermaine O\'Neal'=>2072,'Zach Randolph'=>2217,'Andre Iguodala'=>2738,
    'Antawn Jamison'=>1728,'Steve Francis'=>2399,'Paul Millsap'=>200794,
    'Mike Conley'=>201144,'Monta Ellis'=>201188,'Luol Deng'=>2546,
    'Serge Ibaka'=>202683,
    // 2010s-2020s
    'Blake Griffin'=>201933,'John Wall'=>202322,'Kemba Walker'=>202689,
    'Bradley Beal'=>203078,'Kevin Love'=>201567,'Marc Gasol'=>201188,
    'Ben Simmons'=>1627732,'LaMelo Ball'=>1630163,'Ja Morant'=>1629630,
    'Tyrese Haliburton'=>1630169,'De\'Aaron Fox'=>1628368,'Scottie Barnes'=>1630544,
    'Evan Mobley'=>1630596,'Cade Cunningham'=>1630595,'Franz Wagner'=>1630532,
    'Alperen Sengun'=>1630578,'Josh Giddey'=>1630581,'Andrew Bogut'=>101106,
    'Patty Mills'=>201988,'Joe Ingles'=>204060,'Zydrunas Ilgauskas'=>708,
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
            // Award 25 coins per newly answered correct cell
            $prev_respostas = $row ? (json_decode($row['respostas'], true) ?: []) : [];
            $prev_keys      = array_column($prev_respostas, 'key');
            $curr_respostas = json_decode($respostas, true) ?: [];
            $curr_keys      = array_column($curr_respostas, 'key');
            $new_cells      = count(array_diff($curr_keys, $prev_keys));
            if ($new_cells > 0) {
                $pdo->prepare("UPDATE usuarios SET pontos=pontos+? WHERE id=?")->execute([$new_cells * 25, $user_id]);
            }
            if ($row) {
                $pdo->prepare("UPDATE boxnba_historico SET respostas=?,tentativas_restantes=?,concluido=?,desistiu=?,pontos_ganhos=? WHERE id=?")
                    ->execute([$respostas,$tentativas_rest,$concluido,$desistiu,$pontos,$row['id']]);
            } else {
                $pdo->prepare("INSERT INTO boxnba_historico (id_usuario,data_jogo,respostas,tentativas_restantes,concluido,desistiu,pontos_ganhos) VALUES(?,?,?,?,?,?,?)")
                    ->execute([$user_id,$hoje,$respostas,$tentativas_rest,$concluido,$desistiu,$pontos]);
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
.header-logo{width:38px;height:38px;object-fit:contain;display:block}
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
.cell-player{padding:5px 4px;text-align:center;width:100%}
.cell-player-name{font-size:9.5px;font-weight:700;color:var(--text);line-height:1.3}
.cell-player-icon{font-size:1.4rem;display:block;margin-bottom:2px}
.cell-headshot{width:50px;height:37px;object-fit:contain;display:block;margin:0 auto 2px;border-radius:5px}

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

/* Toast */
.fba-toast{position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:var(--panel3);border:1px solid rgba(245,158,11,.4);border-radius:10px;padding:9px 18px;font-size:12px;font-weight:700;color:#f59e0b;z-index:500;white-space:nowrap;pointer-events:none;animation:toastIn .25s ease}
.fba-toast.out{animation:toastOut .25s ease forwards}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(10px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
@keyframes toastOut{to{opacity:0;transform:translateX(-50%) translateY(8px)}}

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
      <?php if ($col['type'] === 'team' && !empty($col['nba_id'])): ?>
        <img src="https://cdn.nba.com/logos/nba/<?= $col['nba_id'] ?>/global/L/logo.svg" class="header-logo" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='inline'"><span class="header-icon" style="display:none"><?= $col['icon'] ?></span>
      <?php else: ?>
        <span class="header-icon"><?= $col['icon'] ?></span>
      <?php endif; ?>
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
        <?php if ($row['type'] === 'team' && !empty($row['nba_id'])): ?>
          <img src="https://cdn.nba.com/logos/nba/<?= $row['nba_id'] ?>/global/L/logo.svg" class="header-logo" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='inline'"><span class="header-icon" style="display:none"><?= $row['icon'] ?></span>
        <?php else: ?>
          <span class="header-icon"><?= $row['icon'] ?></span>
        <?php endif; ?>
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
const PLAYER_PIDS = <?= json_encode((object)$PLAYER_PIDS) ?>;

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

function buildCellHtml(name) {
  const pid = PLAYER_PIDS[name];
  const media = pid
    ? `<img src="https://cdn.nba.com/headshots/nba/latest/260x190/${pid}.png" class="cell-headshot" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='block'"><span class="cell-player-icon" style="display:none">🏀</span>`
    : `<span class="cell-player-icon">🏀</span>`;
  return `<div class="cell-player">${media}<div class="cell-player-name">${name}</div></div>`;
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
      el.innerHTML = buildCellHtml(ans);
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
  return correct * 25;
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
    const pid = PLAYER_PIDS[n];
    const avatar = pid
      ? `<img src="https://cdn.nba.com/headshots/nba/latest/260x190/${pid}.png" style="width:24px;height:18px;object-fit:contain;border-radius:3px;flex-shrink:0" onerror="this.style.display='none'">`
      : `<span style="font-size:14px;flex-shrink:0">🏀</span>`;
    return `<div class="suggestion-item" style="display:flex;align-items:center;gap:8px" data-idx="${i}" data-name="${n}" onclick="selectPlayer('${n.replace(/'/,"\\'")}')">
      ${avatar} ${highlighted}
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
    el.innerHTML = buildCellHtml(name);
    closeSearch();
    showCoinToast('+25 moedas! 🪙');
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

let _toastTimer = null;
function showCoinToast(msg) {
  let el = document.getElementById('_fbaToast');
  if (el) { clearTimeout(_toastTimer); el.remove(); }
  el = document.createElement('div');
  el.id = '_fbaToast';
  el.className = 'fba-toast';
  el.textContent = msg;
  document.body.appendChild(el);
  _toastTimer = setTimeout(() => {
    el.classList.add('out');
    setTimeout(() => el.remove(), 250);
  }, 1800);
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
