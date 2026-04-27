<?php
/**
 * nba-players-db.php
 * Banco central de jogadores NBA — usado por boxnba.php e conexoes.php.
 * Inclui funções para sincronizar/ler do banco de dados.
 */

// ── DADOS ESTÁTICOS (seed completo) ──────────────────────────────────────────
// Formato: n=nome, t=times (siglas), c=país, a=conquistas
// Conquistas: MVP,CHAMPION,ALLSTAR,FINALS_MVP,DPOY,SCORING,ROY,SIXTHMAN
$NBA_PLAYERS_SEED = [
    // ── SUPERSTARS MODERNOS ───────────────────────────────────────────────────
    ['n'=>'LeBron James',             't'=>['CLE','MIA','LAL'],                         'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Stephen Curry',            't'=>['GSW'],                                     'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Kevin Durant',             't'=>['OKC','GSW','BKN','PHX'],                  'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','SCORING']],
    ['n'=>'Giannis Antetokounmpo',    't'=>['MIL'],                                    'c'=>'GRE','a'=>['MVP','CHAMPION','ALLSTAR','DPOY','FINALS_MVP']],
    ['n'=>'Nikola Jokic',             't'=>['DEN'],                                    'c'=>'SER','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Luka Doncic',              't'=>['DAL','LAL'],                              'c'=>'SLO','a'=>['ALLSTAR','ROY']],
    ['n'=>'Joel Embiid',              't'=>['PHI','LAL'],                              'c'=>'CAM','a'=>['MVP','ALLSTAR','SCORING']],
    ['n'=>'Kawhi Leonard',            't'=>['SAS','TOR','LAC'],                        'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY','FINALS_MVP']],
    ['n'=>'Kobe Bryant',              't'=>['LAL'],                                    'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','SCORING']],
    ['n'=>'Shaquille O\'Neal',        't'=>['LAL','MIA','PHX','CLE','BOS'],            'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Dwyane Wade',              't'=>['MIA','CHI','CLE'],                        'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP','SCORING']],
    ['n'=>'Chris Bosh',               't'=>['TOR','MIA'],                              'c'=>'CAN','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Dirk Nowitzki',            't'=>['DAL'],                                    'c'=>'GER','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Tim Duncan',               't'=>['SAS'],                                    'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY']],
    ['n'=>'Tony Parker',              't'=>['SAS','CHA'],                              'c'=>'FRA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Manu Ginobili',            't'=>['SAS'],                                    'c'=>'ARG','a'=>['CHAMPION','ALLSTAR','SIXTHMAN']],
    ['n'=>'Kevin Garnett',            't'=>['MIN','BOS','BKN'],                        'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Paul Pierce',              't'=>['BOS','BKN','WAS','LAC','CLE'],            'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Ray Allen',                't'=>['MIL','SEA','BOS','MIA'],                  'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Allen Iverson',            't'=>['PHI','DEN','DET','MEM'],                  'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING','ROY']],
    ['n'=>'Vince Carter',             't'=>['TOR','NJN','ORL','PHX','DAL','OKC','MEM','ATL','SAC'],'c'=>'CAN','a'=>['ALLSTAR','ROY']],
    ['n'=>'Tracy McGrady',            't'=>['TOR','ORL','HOU','NYK','DET','ATL','SAS'],'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Carmelo Anthony',          't'=>['DEN','NYK','OKC','HOU','POR','LAL'],      'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Russell Westbrook',        't'=>['OKC','HOU','WAS','LAL','LAC','UTA'],      'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING']],
    ['n'=>'James Harden',             't'=>['OKC','HOU','BKN','PHI','LAC'],            'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING']],
    ['n'=>'Derrick Rose',             't'=>['CHI','NYK','CLE','MIN','DET','MEM'],      'c'=>'USA','a'=>['MVP','ALLSTAR','ROY']],
    ['n'=>'Jimmy Butler',             't'=>['CHI','MIN','PHI','MIA','GSW'],            'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Paul George',              't'=>['IND','OKC','LAC'],                        'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Kyrie Irving',             't'=>['CLE','BOS','BKN','DAL','MIA'],            'c'=>'USA','a'=>['CHAMPION','ALLSTAR','ROY']],
    ['n'=>'Damian Lillard',           't'=>['POR','MIL'],                              'c'=>'USA','a'=>['ALLSTAR','ROY','SCORING']],
    ['n'=>'Chris Paul',               't'=>['NOH','LAC','HOU','OKC','PHX','SAC','GSW'],'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Steve Nash',               't'=>['PHX','DAL','LAL'],                        'c'=>'CAN','a'=>['MVP','ALLSTAR']],
    ['n'=>'Jason Kidd',               't'=>['DAL','PHX','NJN','NYK'],                  'c'=>'USA','a'=>['CHAMPION','ALLSTAR','ROY']],
    ['n'=>'Pau Gasol',                't'=>['MEM','LAL','CHI','SAS','MIL','POR'],      'c'=>'ESP','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Rudy Gobert',              't'=>['UTA','MIN'],                              'c'=>'FRA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Victor Wembanyama',        't'=>['SAS'],                                    'c'=>'FRA','a'=>['ROY']],
    ['n'=>'Nicolas Batum',            't'=>['POR','CHA','LAC','PHI'],                  'c'=>'FRA','a'=>[]],
    ['n'=>'Boris Diaw',               't'=>['ATL','PHX','CHA','SAS','UTA'],            'c'=>'FRA','a'=>['CHAMPION','SIXTHMAN']],
    ['n'=>'Evan Fournier',            't'=>['DEN','ORL','BOS','NYK'],                  'c'=>'FRA','a'=>[]],
    ['n'=>'Andrew Wiggins',           't'=>['MIN','GSW'],                              'c'=>'CAN','a'=>['CHAMPION','ALLSTAR','ROY']],
    ['n'=>'Jamal Murray',             't'=>['DEN'],                                    'c'=>'CAN','a'=>['CHAMPION']],
    ['n'=>'Shai Gilgeous-Alexander',  't'=>['LAC','OKC'],                              'c'=>'CAN','a'=>['ALLSTAR','SCORING']],
    ['n'=>'DeMar DeRozan',            't'=>['TOR','SAS','CHI'],                        'c'=>'CAN','a'=>['ALLSTAR']],
    ['n'=>'Nenê',                     't'=>['DEN','HOU','WAS'],                        'c'=>'BRA','a'=>['ALLSTAR']],
    ['n'=>'Anderson Varejão',         't'=>['CLE','POR','GSW'],                        'c'=>'BRA','a'=>[]],
    ['n'=>'Leandro Barbosa',          't'=>['PHX','TOR','IND','GSW','BOS'],            'c'=>'BRA','a'=>['SIXTHMAN']],
    ['n'=>'Tiago Splitter',           't'=>['SAS','ATL','SAC','PHX'],                  'c'=>'BRA','a'=>['CHAMPION']],
    ['n'=>'Luis Scola',               't'=>['HOU','PHX','TOR','IND','NJN'],            'c'=>'ARG','a'=>['ALLSTAR']],
    ['n'=>'Goran Dragic',             't'=>['PHX','MIA','TOR','BKN','CHI'],            'c'=>'SLO','a'=>['ALLSTAR']],
    ['n'=>'Nikola Vucevic',           't'=>['PHI','ORL','CHI'],                        'c'=>'SER','a'=>['ALLSTAR']],
    ['n'=>'Bogdan Bogdanovic',        't'=>['SAC','ATL','MIL'],                        'c'=>'SER','a'=>[]],
    ['n'=>'Vlade Divac',              't'=>['LAL','CHA','SAC'],                        'c'=>'SER','a'=>['ALLSTAR']],
    ['n'=>'Dennis Schroder',          't'=>['ATL','OKC','LAL','BOS','HOU','TOR'],      'c'=>'GER','a'=>['SIXTHMAN']],
    ['n'=>'Pascal Siakam',            't'=>['TOR','IND'],                              'c'=>'CAM','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Draymond Green',           't'=>['GSW'],                                    'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Klay Thompson',            't'=>['GSW','DAL'],                              'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Anthony Davis',            't'=>['NOP','LAL'],                              'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Jayson Tatum',             't'=>['BOS'],                                    'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Jaylen Brown',             't'=>['BOS'],                                    'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Bam Adebayo',              't'=>['MIA'],                                    'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Donovan Mitchell',         't'=>['UTA','CLE'],                              'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Devin Booker',             't'=>['PHX'],                                    'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Hakeem Olajuwon',          't'=>['HOU','TOR'],                              'c'=>'NIG','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY']],
    ['n'=>'Charles Barkley',          't'=>['PHI','PHX','HOU'],                        'c'=>'USA','a'=>['MVP','ALLSTAR']],
    ['n'=>'Scottie Pippen',           't'=>['CHI','HOU','POR'],                        'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Dennis Rodman',            't'=>['DET','SAS','CHI','LAL','DAL'],            'c'=>'USA','a'=>['CHAMPION','DPOY']],
    ['n'=>'Khris Middleton',          't'=>['DET','MIL'],                              'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Jrue Holiday',             't'=>['PHI','NOP','MIL','BOS','POR'],            'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Trae Young',               't'=>['ATL'],                                    'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Karl-Anthony Towns',       't'=>['MIN','NYK'],                              'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Zion Williamson',          't'=>['NOP'],                                    'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Kyle Lowry',               't'=>['MEM','HOU','TOR','MIA','PHI','MIN'],      'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Dwight Howard',            't'=>['ORL','LAL','HOU','ATL','CHA','WAS','PHI'],'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Rajon Rondo',              't'=>['BOS','DAL','SAC','CHI','NOP','LAL','ATL','LAC','CLE'],'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'DeMarcus Cousins',         't'=>['SAC','NOP','GSW','LAL','HOU','LAC'],      'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Andrei Kirilenko',         't'=>['UTA','MIN','BKN'],                        'c'=>'RUS','a'=>['ALLSTAR']],
    ['n'=>'Patrick Ewing',            't'=>['NYK','SEA','ORL','ATL'],                  'c'=>'USA','a'=>['ROY','ALLSTAR']],
    ['n'=>'Dikembe Mutombo',          't'=>['DEN','ATL','PHI','NJN','NYK','HOU'],      'c'=>'COD','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Grant Hill',               't'=>['DET','ORL','PHX','LAC'],                  'c'=>'USA','a'=>['ROY','ALLSTAR']],
    ['n'=>'Reggie Miller',            't'=>['IND'],                                    'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Metta World Peace',        't'=>['CHI','IND','LAL','NYK','BOS'],            'c'=>'USA','a'=>['CHAMPION','DPOY']],
    ['n'=>'Brook Lopez',              't'=>['NJN','LAL','BKN','MIL'],                  'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Anthony Edwards',          't'=>['MIN'],                                    'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Paolo Banchero',           't'=>['ORL'],                                    'c'=>'USA','a'=>['ROY']],
    // ── LENDAS CLÁSSICAS ─────────────────────────────────────────────────────
    ['n'=>'Wilt Chamberlain',         't'=>['PHW','SFW','LAL'],                        'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','SCORING']],
    ['n'=>'Bill Russell',             't'=>['BOS'],                                    'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Magic Johnson',            't'=>['LAL'],                                    'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Larry Bird',               't'=>['BOS'],                                    'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Michael Jordan',           't'=>['CHI','WAS'],                              'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY','SCORING']],
    ['n'=>'Isiah Thomas',             't'=>['DET'],                                    'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'John Stockton',            't'=>['UTA'],                                    'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Karl Malone',              't'=>['UTA','LAL'],                              'c'=>'USA','a'=>['MVP','ALLSTAR']],
    ['n'=>'Clyde Drexler',            't'=>['POR','HOU','LAL'],                        'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Gary Payton',              't'=>['SEA','MIL','LAL','BOS','MIA'],            'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Shawn Kemp',               't'=>['SEA','CLE','POR','ORL','LAL'],            'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Kareem Abdul-Jabbar',      't'=>['MIL','LAL'],                              'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','SCORING']],
    ['n'=>'David Robinson',           't'=>['SAS'],                                    'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','DPOY','SCORING','ROY']],
    ['n'=>'James Worthy',             't'=>['LAL'],                                    'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Robert Horry',             't'=>['HOU','LAL','PHX','SAS'],                  'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Alonzo Mourning',          't'=>['CHA','MIA','NJN','TOR'],                  'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY']],
    ['n'=>'Derek Fisher',             't'=>['LAL','OKC','UTA','MEM','NYK','DAL'],      'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Horace Grant',             't'=>['CHI','ORL','LAL','SEA'],                  'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Moses Malone',             't'=>['HOU','PHI','WAS','ATL','MIL'],            'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Jerry West',               't'=>['LAL'],                                    'c'=>'USA','a'=>['ALLSTAR','FINALS_MVP']],
    ['n'=>'Elgin Baylor',             't'=>['LAL'],                                    'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Bob Cousy',                't'=>['BOS'],                                    'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Julius Erving',            't'=>['PHI'],                                    'c'=>'USA','a'=>['MVP','ALLSTAR','FINALS_MVP']],
    ['n'=>'Kevin McHale',             't'=>['BOS'],                                    'c'=>'USA','a'=>['CHAMPION','ALLSTAR','SIXTHMAN']],
    ['n'=>'Bernard King',             't'=>['NJN','UTA','NYK','WAS'],                  'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Dominique Wilkins',        't'=>['ATL','LAC','BOS','SAS','ORL'],            'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Mitch Richmond',           't'=>['GSW','SAC','WAS','LAL','NYK'],            'c'=>'USA','a'=>['ALLSTAR','SCORING','ROY']],
    ['n'=>'Muggsy Bogues',            't'=>['WAS','CHA','GSW','TOR'],                  'c'=>'USA','a'=>[]],
    ['n'=>'Detlef Schrempf',          't'=>['DAL','IND','SEA','POR'],                  'c'=>'GER','a'=>['ALLSTAR','SIXTHMAN']],
    ['n'=>'Drazen Petrovic',          't'=>['POR','NJN'],                              'c'=>'CRO','a'=>[]],
    ['n'=>'Toni Kukoc',               't'=>['CHI','PHI','ATL','MIL'],                  'c'=>'CRO','a'=>['CHAMPION','SIXTHMAN']],
    ['n'=>'Dino Radja',               't'=>['BOS'],                                    'c'=>'CRO','a'=>[]],
    ['n'=>'Arvydas Sabonis',          't'=>['POR'],                                    'c'=>'LIT','a'=>['ALLSTAR']],
    ['n'=>'Sarunas Marciulionis',     't'=>['GSW','SEA','SAC','HOU'],                  'c'=>'LIT','a'=>['ALLSTAR']],
    ['n'=>'Julius Randle',            't'=>['LAL','NOP','NYK','MIN'],                  'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Peja Stojakovic',          't'=>['SAC','NOP','IND','TOR','DAL'],            'c'=>'SER','a'=>['CHAMPION','ALLSTAR']],
    // ── CLÁSSICOS 50s–70s ────────────────────────────────────────────────────
    ['n'=>'Oscar Robertson',          't'=>['CIN','MIL'],                              'c'=>'USA','a'=>['MVP','ALLSTAR','ROY']],
    ['n'=>'Pete Maravich',            't'=>['ATL','NOP','UTA'],                        'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'John Havlicek',            't'=>['BOS'],                                    'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP']],
    ['n'=>'Walt Frazier',             't'=>['NYK','CLE'],                              'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Willis Reed',              't'=>['NYK'],                                    'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP']],
    ['n'=>'Rick Barry',               't'=>['SFW','GSW','HOU'],                        'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP','SCORING']],
    ['n'=>'Bill Walton',              't'=>['POR','LAC','BOS'],                        'c'=>'USA','a'=>['MVP','ALLSTAR','CHAMPION','FINALS_MVP']],
    ['n'=>'Dave Cowens',              't'=>['BOS','MIL'],                              'c'=>'USA','a'=>['MVP','ALLSTAR','CHAMPION','ROY']],
    ['n'=>'Elvin Hayes',              't'=>['WAS','HOU'],                              'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Nate Archibald',           't'=>['CIN','KCO','NJN','BOS'],                 'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Bob McAdoo',               't'=>['BUF','NYK','BOS','DET','NJN','LAL'],      'c'=>'USA','a'=>['MVP','ALLSTAR','CHAMPION','SCORING']],
    ['n'=>'George Gervin',            't'=>['SAS'],                                    'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Dave Bing',                't'=>['DET','WAS','BOS'],                        'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Bob Lanier',               't'=>['DET','MIL'],                              'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Hal Greer',                't'=>['PHI'],                                    'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Gail Goodrich',            't'=>['LAL'],                                    'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Spencer Haywood',          't'=>['SEA','NYK'],                              'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Sam Jones',                't'=>['BOS'],                                    'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'K.C. Jones',               't'=>['BOS'],                                    'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Tom Heinsohn',             't'=>['BOS'],                                    'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Jack Sikma',               't'=>['SEA','MIL'],                              'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Gus Williams',             't'=>['GSW','SEA','WAS'],                        'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Dennis Johnson',           't'=>['SEA','PHX','BOS'],                        'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP','DPOY']],
    ['n'=>'Robert Parish',            't'=>['GSW','BOS','CHA','CHI'],                  'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Jo Jo White',              't'=>['BOS'],                                    'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP']],
    // ── CLÁSSICOS 80s ────────────────────────────────────────────────────────
    ['n'=>'Adrian Dantley',           't'=>['LAL','UTA','DET'],                        'c'=>'USA','a'=>['ALLSTAR','SCORING','ROY']],
    ['n'=>'Alex English',             't'=>['DEN'],                                    'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Sidney Moncrief',          't'=>['MIL'],                                    'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Fat Lever',                't'=>['DEN','DAL'],                              'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Rolando Blackman',         't'=>['DAL','NYK'],                              'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Mark Price',               't'=>['CLE'],                                    'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Brad Daugherty',           't'=>['CLE'],                                    'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Tom Chambers',             't'=>['SEA','PHX','UTA'],                        'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Byron Scott',              't'=>['LAL','IND','SAS'],                        'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Michael Cooper',           't'=>['LAL'],                                    'c'=>'USA','a'=>['CHAMPION','DPOY']],
    ['n'=>'Ricky Pierce',             't'=>['MIL','SEA'],                              'c'=>'USA','a'=>['ALLSTAR','SIXTHMAN']],
    ['n'=>'Danny Manning',            't'=>['LAC','ATL','PHX','NYK','MIL','UTA'],      'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Mark Aguirre',             't'=>['DAL','DET','LAL'],                        'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Kiki Vandeweghe',          't'=>['DEN','POR','NYK','LAC'],                  'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Bill Laimbeer',            't'=>['CLE','DET'],                              'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Joe Dumars',               't'=>['DET'],                                    'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Dan Majerle',              't'=>['PHX','CLE','MIA'],                        'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Alvin Robertson',          't'=>['SAS','MIL','DET'],                        'c'=>'USA','a'=>['ALLSTAR','DPOY','ROY']],
    ['n'=>'Xavier McDaniel',          't'=>['SEA','PHX','NYK','BOS'],                  'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Buck Williams',            't'=>['NJN','POR','NYK'],                        'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Otis Thorpe',              't'=>['HOU','POR'],                              'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    // ── 90s ──────────────────────────────────────────────────────────────────
    ['n'=>'Penny Hardaway',           't'=>['ORL','PHX','NYK','MIA'],                  'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Chris Webber',             't'=>['GSW','WAS','SAC','PHI','DET'],            'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Vin Baker',                't'=>['MIL','SEA','BOS'],                        'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Glen Rice',                't'=>['MIA','CHA','LAL','HOU','NYK'],            'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Latrell Sprewell',         't'=>['GSW','NYK','MIN'],                        'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Kevin Johnson',            't'=>['SAC','PHX'],                              'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Damon Stoudamire',         't'=>['TOR','POR','MEM','SAS'],                  'c'=>'USA','a'=>['ROY']],
    ['n'=>'Stephon Marbury',          't'=>['MIN','NJN','PHX','NYK','BOS'],            'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Michael Redd',             't'=>['MIL'],                                    'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Allan Houston',            't'=>['DET','NYK'],                              'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Jason Terry',              't'=>['ATL','DAL','BOS','BKN','MIL','HOU'],      'c'=>'USA','a'=>['CHAMPION','SIXTHMAN']],
    ['n'=>'Jamal Mashburn',           't'=>['DAL','MIA','NOP'],                        'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Juwan Howard',             't'=>['WAS','DAL','DEN','ORL','HOU','ATL','MIA'],'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Donyell Marshall',         't'=>['MIN','GSW','UTA','TOR','CHI','CLE'],      'c'=>'USA','a'=>[]],
    ['n'=>'Gary Trent Sr.',           't'=>['POR','DAL'],                              'c'=>'USA','a'=>[]],
    ['n'=>'Cherokee Parks',           't'=>['DAL','MIN'],                              'c'=>'USA','a'=>[]],
    ['n'=>'Jim Jackson',              't'=>['DAL','NJN'],                              'c'=>'USA','a'=>[]],
    ['n'=>'Cedric Ceballos',          't'=>['PHX','LAL','DAL','MIA'],                  'c'=>'USA','a'=>['ALLSTAR','SCORING']],
    ['n'=>'Mookie Blaylock',          't'=>['NJN','ATL','GSW'],                        'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Hersey Hawkins',           't'=>['PHI','CHA','SEA','CHI'],                  'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Terrell Brandon',          't'=>['CLE','MIL','MIN'],                        'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Laphonso Ellis',           't'=>['DEN','ATL','MIA','MIN'],                  'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Rik Smits',                't'=>['IND'],                                    'c'=>'NED','a'=>['ALLSTAR']],
    ['n'=>'Detlef Schrempf',          't'=>['DAL','IND','SEA','POR'],                  'c'=>'GER','a'=>['ALLSTAR','SIXTHMAN']],
    // ── 2000s ────────────────────────────────────────────────────────────────
    ['n'=>'Yao Ming',                 't'=>['HOU'],                                    'c'=>'CHN','a'=>['ALLSTAR']],
    ['n'=>'Chauncey Billups',         't'=>['DET','DEN','NYK','LAC'],                  'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP']],
    ['n'=>'Ben Wallace',              't'=>['WAS','ORL','DET','CHI','CLE'],            'c'=>'USA','a'=>['ALLSTAR','CHAMPION','DPOY']],
    ['n'=>'Richard Hamilton',         't'=>['WAS','DET','CHI'],                        'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Rasheed Wallace',          't'=>['WAS','POR','ATL','DET','BOS'],            'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Gilbert Arenas',           't'=>['GSW','WAS','ORL'],                        'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Antawn Jamison',           't'=>['GSW','WAS','CLE','LAC'],                  'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Steve Francis',            't'=>['HOU','ORL','NYK'],                        'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Amare Stoudemire',         't'=>['PHX','NYK'],                              'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Baron Davis',              't'=>['CHA','NOP','GSW','LAC','CLE','NYK'],      'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Marcus Camby',             't'=>['TOR','NYK','DEN','LAC'],                  'c'=>'USA','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Jermaine O\'Neal',         't'=>['POR','IND','MIA','TOR','PHX','BOS'],      'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Zach Randolph',            't'=>['POR','NYK','LAC','MEM','SAC'],            'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Andre Iguodala',           't'=>['PHI','DEN','GSW','MIA'],                  'c'=>'USA','a'=>['ALLSTAR','CHAMPION','FINALS_MVP']],
    ['n'=>'Paul Millsap',             't'=>['UTA','ATL','DEN'],                        'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Mike Conley',              't'=>['MEM','UTA'],                              'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Monta Ellis',              't'=>['GSW','MIL','DAL','IND'],                  'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Luol Deng',                't'=>['CHI','CLE','MIA','LAL','MIN'],            'c'=>'SUD','a'=>['ALLSTAR']],
    ['n'=>'Serge Ibaka',              't'=>['OKC','ORL','TOR','LAC'],                  'c'=>'COG','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Dirk Nowitzki',            't'=>['DAL'],                                    'c'=>'GER','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP']],
    ['n'=>'Mike Bibby',               't'=>['SAC','ATL','WAS','NYK','MIA'],            'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Peja Stojakovic',          't'=>['SAC','NOP','IND','TOR','DAL'],            'c'=>'SER','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Vladimir Radmanovic',      't'=>['UTA','SEA','LAL','CHI'],                  'c'=>'SER','a'=>[]],
    ['n'=>'Carlos Boozer',            't'=>['CLE','UTA','CHI','LAL','PHX'],            'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Mehmet Okur',              't'=>['DET','UTA'],                              'c'=>'TUR','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Hedo Turkoglu',            't'=>['SAS','SAC','ORL','TOR','PHX'],            'c'=>'TUR','a'=>['ALLSTAR']],
    ['n'=>'Wally Szczerbiak',         't'=>['MIN','CLE','BOS'],                        'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Corey Maggette',           't'=>['LAC','GSW','MIL','CHA'],                  'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Al Jefferson',             't'=>['BOS','MIN','UTA','CHA','IND'],            'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Elton Brand',              't'=>['CHI','LAC','PHI','DAL','ATL'],            'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Stromile Swift',           't'=>['MEM','HOU','NJN','LAL'],                  'c'=>'USA','a'=>[]],
    ['n'=>'Kenyon Martin',            't'=>['NJN','DEN','NYK','LAC'],                  'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Jason Williams',           't'=>['SAC','MEM','MIA'],                        'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    // ── 2010s ────────────────────────────────────────────────────────────────
    ['n'=>'Blake Griffin',            't'=>['LAC','DET','BKN'],                        'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'John Wall',                't'=>['WAS','HOU'],                              'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Kemba Walker',             't'=>['CHA','BOS','NYK'],                        'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Bradley Beal',             't'=>['WAS','PHX'],                              'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Kevin Love',               't'=>['MIN','CLE'],                              'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Marc Gasol',               't'=>['MEM','TOR','LAL'],                        'c'=>'ESP','a'=>['ALLSTAR','CHAMPION','DPOY']],
    ['n'=>'Zydrunas Ilgauskas',       't'=>['CLE','MIA'],                              'c'=>'LIT','a'=>['ALLSTAR']],
    ['n'=>'Andrew Bogut',             't'=>['MIL','GSW','DAL','LAL','PHI'],            'c'=>'AUS','a'=>['CHAMPION','ROY']],
    ['n'=>'Patty Mills',              't'=>['SAS','BKN','ATL'],                        'c'=>'AUS','a'=>['CHAMPION']],
    ['n'=>'Ben Simmons',              't'=>['PHI','BKN'],                              'c'=>'AUS','a'=>['ALLSTAR','ROY']],
    ['n'=>'Joe Ingles',               't'=>['UTA','MIL'],                              'c'=>'AUS','a'=>['CHAMPION']],
    ['n'=>'Matthew Dellavedova',      't'=>['CLE','MIL','SAC'],                        'c'=>'AUS','a'=>['CHAMPION']],
    ['n'=>'Aron Baynes',              't'=>['SAS','DET','BOS','PHX'],                  'c'=>'AUS','a'=>[]],
    ['n'=>'Luc Longley',              't'=>['MIN','CHI','PHX'],                        'c'=>'AUS','a'=>['CHAMPION']],
    ['n'=>'Josh Giddey',              't'=>['OKC','CHI'],                              'c'=>'AUS','a'=>[]],
    ['n'=>'Shareef Abdur-Rahim',      't'=>['ATL','SAC','POR','NJN'],                  'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Deandre Jordan',           't'=>['LAC','DAL','NYK','BKN'],                  'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Bismack Biyombo',          't'=>['CHA','ORL','TOR','NOP','PHX'],            'c'=>'COD','a'=>[]],
    ['n'=>'Gordon Hayward',           't'=>['UTA','BOS','CHA','OKC'],                  'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Tobias Harris',            't'=>['MIL','ORL','DET','LAC','PHI'],            'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Andre Drummond',           't'=>['DET','CLE','LAL','PHI','CHI'],            'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Gorgui Dieng',             't'=>['MIN','NOP','ATL'],                        'c'=>'SEN','a'=>[]],
    ['n'=>'Nikola Mirotic',           't'=>['CHI','NOP','MIL'],                        'c'=>'SER','a'=>[]],
    ['n'=>'Bojan Bogdanovic',         't'=>['BKN','IND','UTA','DET'],                  'c'=>'CRO','a'=>['ALLSTAR']],
    ['n'=>'Dario Saric',              't'=>['PHI','PHX','GSW'],                        'c'=>'CRO','a'=>['ROY']],
    ['n'=>'Jusuf Nurkic',             't'=>['DEN','POR'],                              'c'=>'BOS','a'=>['ALLSTAR']],
    ['n'=>'Kristaps Porzingis',       't'=>['NYK','DAL','WAS','BOS','MIL'],            'c'=>'LAT','a'=>['ALLSTAR']],
    ['n'=>'Dennis Schroder',          't'=>['ATL','OKC','LAL','BOS','HOU','TOR'],      'c'=>'GER','a'=>['SIXTHMAN']],
    ['n'=>'Moritz Wagner',            't'=>['LAL','WAS','ORL'],                        'c'=>'GER','a'=>[]],
    ['n'=>'Daniel Theis',             't'=>['BOS','CHI','HOU','IND','NOP'],            'c'=>'GER','a'=>[]],
    ['n'=>'Franz Wagner',             't'=>['ORL'],                                    'c'=>'GER','a'=>['ALLSTAR']],
    ['n'=>'Isaiah Thomas',            't'=>['SAC','PHX','BOS','CLE','LAL'],            'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Al Horford',               't'=>['ATL','BOS','PHI','OKC'],                  'c'=>'DOM','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Khris Middleton',          't'=>['DET','MIL'],                              'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Eric Bledsoe',             't'=>['LAC','PHX','MIL','NOP','LAL'],            'c'=>'USA','a'=>['ALLSTAR','CHAMPION']],
    ['n'=>'Victor Oladipo',           't'=>['ORL','OKC','IND','HOU','MIA'],            'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Domantas Sabonis',         't'=>['OKC','IND','SAC'],                        'c'=>'LIT','a'=>['ALLSTAR']],
    ['n'=>'Jonas Valanciunas',        't'=>['TOR','MEM','NOP'],                        'c'=>'LIT','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Giannis Antetokounmpo',    't'=>['MIL'],                                    'c'=>'GRE','a'=>['MVP','CHAMPION','ALLSTAR','DPOY','FINALS_MVP']],
    ['n'=>'Thanasis Antetokounmpo',   't'=>['NYK','MIL'],                              'c'=>'GRE','a'=>['CHAMPION']],
    ['n'=>'Aleksej Pokusevski',       't'=>['OKC'],                                    'c'=>'SER','a'=>[]],
    ['n'=>'Vlatko Cancar',            't'=>['DEN'],                                    'c'=>'SLO','a'=>['CHAMPION']],
    // ── 2020s GERAÇÃO NOVA ───────────────────────────────────────────────────
    ['n'=>'LaMelo Ball',              't'=>['CHA'],                                    'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Ja Morant',                't'=>['MEM'],                                    'c'=>'USA','a'=>['ALLSTAR','ROY']],
    ['n'=>'Tyrese Haliburton',        't'=>['SAC','IND'],                              'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'De\'Aaron Fox',            't'=>['SAC'],                                    'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Scottie Barnes',           't'=>['TOR'],                                    'c'=>'USA','a'=>['ROY']],
    ['n'=>'Evan Mobley',              't'=>['CLE'],                                    'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Cade Cunningham',          't'=>['DET'],                                    'c'=>'USA','a'=>['ROY']],
    ['n'=>'Jalen Green',              't'=>['HOU'],                                    'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Alperen Sengun',           't'=>['HOU'],                                    'c'=>'TUR','a'=>[]],
    ['n'=>'Walker Kessler',           't'=>['UTA'],                                    'c'=>'USA','a'=>[]],
    ['n'=>'Jabari Smith Jr.',         't'=>['HOU'],                                    'c'=>'USA','a'=>[]],
    ['n'=>'Keegan Murray',            't'=>['SAC'],                                    'c'=>'USA','a'=>[]],
    ['n'=>'Bennedict Mathurin',       't'=>['IND'],                                    'c'=>'CAN','a'=>[]],
    ['n'=>'Ochai Agbaji',             't'=>['CLE','TOR'],                              'c'=>'USA','a'=>[]],
    ['n'=>'Dyson Daniels',            't'=>['NOP'],                                    'c'=>'AUS','a'=>[]],
    ['n'=>'Wembanyama',               't'=>['SAS'],                                    'c'=>'FRA','a'=>['ROY']],
    ['n'=>'Chet Holmgren',            't'=>['OKC'],                                    'c'=>'USA','a'=>[]],
    ['n'=>'Scoot Henderson',          't'=>['POR'],                                    'c'=>'USA','a'=>[]],
    ['n'=>'Brandon Miller',           't'=>['CHA'],                                    'c'=>'USA','a'=>[]],
    ['n'=>'Amen Thompson',            't'=>['HOU'],                                    'c'=>'USA','a'=>[]],
    ['n'=>'Ausar Thompson',           't'=>['DET'],                                    'c'=>'USA','a'=>[]],
    ['n'=>'Alexandre Sarr',           't'=>['WAS'],                                    'c'=>'FRA','a'=>[]],
    ['n'=>'Zaccharie Risacher',       't'=>['ATL'],                                    'c'=>'FRA','a'=>[]],
    // ── INTERNACIONAIS NOTÁVEIS ───────────────────────────────────────────────
    ['n'=>'Hakeem Olajuwon',          't'=>['HOU','TOR'],                              'c'=>'NIG','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY']],
    ['n'=>'Dikembe Mutombo',          't'=>['DEN','ATL','PHI','NJN','NYK','HOU'],      'c'=>'COD','a'=>['ALLSTAR','DPOY']],
    ['n'=>'Manute Bol',               't'=>['WAS','GSW','PHI'],                        'c'=>'SSD','a'=>[]],
    ['n'=>'Olumide Oyedeji',          't'=>['ORL'],                                    'c'=>'NIG','a'=>[]],
    ['n'=>'Yi Jianlian',              't'=>['MIL','NJN','WAS','DAL'],                  'c'=>'CHN','a'=>[]],
    ['n'=>'Wang Zhizhi',              't'=>['DAL','MIA'],                              'c'=>'CHN','a'=>[]],
    ['n'=>'Mengke Bateer',            't'=>['SAS'],                                    'c'=>'CHN','a'=>['CHAMPION']],
    ['n'=>'Ricky Rubio',              't'=>['MIN','UTA','PHX','CLE','BOS'],            'c'=>'ESP','a'=>['ROY']],
    ['n'=>'Jose Calderon',            't'=>['TOR','DET','NYK','DAL'],                  'c'=>'ESP','a'=>[]],
    ['n'=>'Alex Abrines',             't'=>['OKC','BAR'],                              'c'=>'ESP','a'=>[]],
    ['n'=>'Clint Capela',             't'=>['HOU','ATL'],                              'c'=>'SUI','a'=>['ALLSTAR']],
    ['n'=>'Thabo Sefolosha',          't'=>['CHI','OKC','ATL'],                        'c'=>'SUI','a'=>['CHAMPION']],
    ['n'=>'Lauri Markkanen',          't'=>['CHI','CLE','UTA'],                        'c'=>'FIN','a'=>['ALLSTAR']],
    ['n'=>'Petteri Koponen',          't'=>['PHI'],                                    'c'=>'FIN','a'=>[]],
    ['n'=>'Jan Vesely',               't'=>['WAS'],                                    'c'=>'CZE','a'=>[]],
    ['n'=>'Tomas Satoransky',         't'=>['WAS','CHI','NOP','SAC'],                  'c'=>'CZE','a'=>[]],
    ['n'=>'Vassilis Spanoulis',       't'=>['HOU'],                                    'c'=>'GRE','a'=>[]],
    ['n'=>'Nikos Zisis',              't'=>['GSW'],                                    'c'=>'GRE','a'=>[]],
    ['n'=>'Georgios Papagiannis',     't'=>['SAC','PHX','MEM'],                        'c'=>'GRE','a'=>[]],
    ['n'=>'Milos Teodosic',           't'=>['LAC'],                                    'c'=>'SER','a'=>[]],
    ['n'=>'Stefan Jovic',             't'=>['POR'],                                    'c'=>'SER','a'=>[]],
    ['n'=>'Marcus & Markieff Morris', 't'=>['PHX','WAS','DET','BOS','LAL'],            'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Goga Bitadze',             't'=>['IND','ORL','MIN'],                        'c'=>'GEO','a'=>[]],
    ['n'=>'Zaza Pachulia',            't'=>['MIL','ATL','DAL','GSW'],                  'c'=>'GEO','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Edin Bavcic',              't'=>['MIL'],                                    'c'=>'SLO','a'=>[]],
    // ── ESPANHÓIS ────────────────────────────────────────────────────────────
    ['n'=>'Pau Gasol',                't'=>['MEM','LAL','CHI','SAS','MIL','POR'],      'c'=>'ESP','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Marc Gasol',               't'=>['MEM','TOR','LAL'],                        'c'=>'ESP','a'=>['ALLSTAR','CHAMPION','DPOY']],
    ['n'=>'Serge Ibaka',              't'=>['OKC','ORL','TOR','LAC'],                  'c'=>'COG','a'=>['CHAMPION','ALLSTAR']],
    // ── ROLE PLAYERS ICÔNICOS ────────────────────────────────────────────────
    ['n'=>'Steve Kerr',               't'=>['PHX','CHI','CLE','SAS'],                  'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'John Paxson',              't'=>['SAS','CHI'],                              'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Toni Kukoc',               't'=>['CHI','PHI','ATL','MIL'],                  'c'=>'CRO','a'=>['CHAMPION','SIXTHMAN']],
    ['n'=>'Luc Longley',              't'=>['MIN','CHI','PHX'],                        'c'=>'AUS','a'=>['CHAMPION']],
    ['n'=>'Ron Harper',               't'=>['CLE','LAC','CHI','LAL'],                  'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Scottie Pippen',           't'=>['CHI','HOU','POR'],                        'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Will Perdue',              't'=>['CHI','SAS'],                              'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Nick Anderson',            't'=>['ORL','SAC','MEM'],                        'c'=>'USA','a'=>[]],
    ['n'=>'Anfernee Simons',          't'=>['POR'],                                    'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'RJ Barrett',               't'=>['NYK','TOR'],                              'c'=>'CAN','a'=>[]],
    ['n'=>'Immanuel Quickley',        't'=>['NYK','TOR'],                              'c'=>'USA','a'=>[]],
    ['n'=>'Josh Hart',                't'=>['LAL','NOP','POR','NYK'],                  'c'=>'USA','a'=>[]],
    ['n'=>'Mikal Bridges',            't'=>['PHX','BKN','NYK'],                        'c'=>'USA','a'=>[]],
    ['n'=>'OG Anunoby',               't'=>['TOR','NYK'],                              'c'=>'NIG','a'=>['CHAMPION']],
    ['n'=>'Luguentz Dort',            't'=>['OKC'],                                    'c'=>'CAN','a'=>[]],
    ['n'=>'Saddiq Bey',               't'=>['DET','ATL'],                              'c'=>'USA','a'=>[]],
    ['n'=>'Chris Boucher',            't'=>['TOR'],                                    'c'=>'CAN','a'=>[]],
    ['n'=>'Darius Garland',           't'=>['CLE'],                                    'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Jarrett Allen',            't'=>['BKN','CLE'],                              'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Keldon Johnson',           't'=>['SAS'],                                    'c'=>'USA','a'=>[]],
    ['n'=>'Devin Vassell',            't'=>['SAS'],                                    'c'=>'USA','a'=>[]],
    ['n'=>'Andrew Nembhard',          't'=>['IND'],                                    'c'=>'CAN','a'=>[]],
    ['n'=>'Bennedict Mathurin',       't'=>['IND'],                                    'c'=>'CAN','a'=>[]],
    ['n'=>'Aaron Gordon',             't'=>['ORL','DEN'],                              'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Michael Porter Jr.',       't'=>['DEN'],                                    'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Kentavious Caldwell-Pope', 't'=>['DET','LAL','WAS','DEN','ORL'],            'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Bruce Brown',              't'=>['DET','BKN','DEN','IND'],                  'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Reggie Jackson',           't'=>['OKC','DET','LAC'],                        'c'=>'USA','a'=>[]],
    ['n'=>'Marcus Morris',            't'=>['HOU','PHX','DET','BOS','LAC'],            'c'=>'USA','a'=>['CHAMPION']],
    ['n'=>'Al Horford',               't'=>['ATL','BOS','PHI','OKC'],                  'c'=>'DOM','a'=>['CHAMPION','ALLSTAR']],
    ['n'=>'Jalen Brunson',            't'=>['DAL','NYK'],                              'c'=>'USA','a'=>['ALLSTAR']],
    ['n'=>'Klay Thompson',            't'=>['GSW','DAL'],                              'c'=>'USA','a'=>['CHAMPION','ALLSTAR']],
];

// Remove duplicatas por nome
function nba_players_deduplicate(array $players): array {
    $seen = [];
    $out  = [];
    foreach ($players as $p) {
        $key = mb_strtolower(trim($p['n']));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $p;
    }
    return $out;
}

$NBA_PLAYERS_SEED = nba_players_deduplicate($NBA_PLAYERS_SEED);

// ── DB FUNCTIONS ─────────────────────────────────────────────────────────────

function nba_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS nba_players_custom (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(120) NOT NULL,
        teams       VARCHAR(300) DEFAULT '',
        country     VARCHAR(10)  DEFAULT 'USA',
        achievements VARCHAR(200) DEFAULT '',
        active      TINYINT DEFAULT 1,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS conexoes_puzzles_custom (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        grupos     TEXT NOT NULL,
        active     TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Retorna a lista combinada de jogadores: seed estático + customizados no DB.
 * Converte o formato do DB para o mesmo formato do seed.
 */
function nba_get_all_players(PDO $pdo): array {
    global $NBA_PLAYERS_SEED;
    nba_ensure_tables($pdo);

    $custom = [];
    try {
        $rows = $pdo->query("SELECT name, teams, country, achievements FROM nba_players_custom WHERE active=1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $teams = array_filter(array_map('trim', explode(',', $r['teams'])));
            $ach   = array_filter(array_map('trim', explode(',', $r['achievements'])));
            $custom[] = [
                'n' => $r['name'],
                't' => array_values($teams),
                'c' => $r['country'],
                'a' => array_values($ach),
            ];
        }
    } catch (Exception $e) {}

    // Merge: seed primeiro, custom depois (sem duplicar nomes)
    $merged = array_merge($NBA_PLAYERS_SEED, $custom);
    return nba_players_deduplicate($merged);
}

/**
 * Retorna puzzles customizados do DB ativos.
 * Cada puzzle é um array de 4 grupos no mesmo formato de $PUZZLES em conexoes.php.
 */
function conexoes_get_custom_puzzles(PDO $pdo): array {
    nba_ensure_tables($pdo);
    $puzzles = [];
    try {
        $rows = $pdo->query("SELECT grupos FROM conexoes_puzzles_custom WHERE active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $g = json_decode($r['grupos'], true);
            if (is_array($g) && count($g) === 4) $puzzles[] = $g;
        }
    } catch (Exception $e) {}
    return $puzzles;
}
