<?php
// hoopgrid.php — Grade NBA 3×3: encontre o jogador que satisfaz os dois critérios
require '../core/conexao.php';
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = (int)$_SESSION['user_id'];
$PONTOS_VITORIA = 150 * getGamePointsMultiplier($pdo, 'hoopgrid');

try { $pdo->exec("CREATE TABLE IF NOT EXISTS hoopgrid_historico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    data_jogo DATE NOT NULL,
    respostas TEXT DEFAULT '[]',
    tentativas_restantes INT DEFAULT 9,
    pontos_ganhos INT DEFAULT 0,
    concluido TINYINT DEFAULT 0,
    desistiu TINYINT DEFAULT 0,
    UNIQUE KEY uq (id_usuario, data_jogo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (PDOException $e) {}
try { $pdo->exec("INSERT IGNORE INTO fba_game_controls (game_key, is_double) VALUES ('hoopgrid', 0)"); } catch (PDOException $e) {}

// Tabela de jogadores (DB-first; hardcode abaixo é só seed)
try { $pdo->exec("CREATE TABLE IF NOT EXISTS hoopgrid_players (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(200) NOT NULL,
    times TEXT NOT NULL DEFAULT '[]',
    pais CHAR(5) NOT NULL DEFAULT 'USA',
    premios TEXT NOT NULL DEFAULT '[]',
    eras TEXT NOT NULL DEFAULT '[]',
    nba_person_id INT NULL,
    ativo TINYINT(1) DEFAULT 1,
    UNIQUE KEY uk_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (PDOException $e) {}

// ── SEED DATA (hardcoded, usado apenas se o banco estiver vazio) ─────────────
$_SEED = [
    ['n'=>'LeBron James',           't'=>['CLE','MIA','LAL'],                   'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP'],        'e'=>['00s','10s','20s']],
    ['n'=>'Stephen Curry',          't'=>['GSW'],                               'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP'],        'e'=>['10s','20s']],
    ['n'=>'Kevin Durant',           't'=>['OKC','GSW','BKN','PHX'],            'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','SCORING'],'e'=>['00s','10s','20s']],
    ['n'=>'Giannis Antetokounmpo',  't'=>['MIL'],                              'c'=>'GRE','a'=>['MVP','CHAMPION','ALLSTAR','DPOY','FINALS_MVP'],  'e'=>['10s','20s']],
    ['n'=>'Nikola Jokic',           't'=>['DEN'],                              'c'=>'SER','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP'],        'e'=>['10s','20s']],
    ['n'=>'Luka Doncic',            't'=>['DAL','LAL'],                        'c'=>'SLO','a'=>['ALLSTAR','ROY'],                               'e'=>['10s','20s']],
    ['n'=>'Joel Embiid',            't'=>['PHI','LAL'],                        'c'=>'CAM','a'=>['MVP','ALLSTAR','SCORING'],                     'e'=>['10s','20s']],
    ['n'=>'Kawhi Leonard',          't'=>['SAS','TOR','LAC'],                  'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY','FINALS_MVP'],       'e'=>['10s','20s']],
    ['n'=>'Kobe Bryant',            't'=>['LAL'],                              'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','SCORING'],'e'=>['90s','00s','10s']],
    ['n'=>'Shaquille O\'Neal',      't'=>['LAL','MIA','PHX','CLE','BOS'],      'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP'],        'e'=>['90s','00s','10s']],
    ['n'=>'Dwyane Wade',            't'=>['MIA','CHI','CLE'],                  'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP','SCORING'],    'e'=>['00s','10s']],
    ['n'=>'Dirk Nowitzki',          't'=>['DAL'],                              'c'=>'GER','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP'],        'e'=>['00s','10s']],
    ['n'=>'Tim Duncan',             't'=>['SAS'],                              'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY'], 'e'=>['90s','00s','10s']],
    ['n'=>'Tony Parker',            't'=>['SAS','CHA'],                        'c'=>'FRA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP'],              'e'=>['00s','10s']],
    ['n'=>'Manu Ginobili',          't'=>['SAS'],                              'c'=>'ARG','a'=>['CHAMPION','ALLSTAR','SIXTHMAN'],                'e'=>['00s','10s']],
    ['n'=>'Kevin Garnett',          't'=>['MIN','BOS','BKN'],                  'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','DPOY'],              'e'=>['90s','00s','10s']],
    ['n'=>'Paul Pierce',            't'=>['BOS','BKN','WAS','LAC','CLE'],      'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP'],              'e'=>['90s','00s','10s']],
    ['n'=>'Ray Allen',              't'=>['MIL','SEA','BOS','MIA'],            'c'=>'USA','a'=>['CHAMPION','ALLSTAR'],                          'e'=>['90s','00s','10s']],
    ['n'=>'Allen Iverson',          't'=>['PHI','DEN','DET','MEM'],            'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING','ROY'],               'e'=>['90s','00s']],
    ['n'=>'Vince Carter',           't'=>['TOR','NJN','ORL','PHX','DAL','OKC','MEM','ATL','SAC'],'c'=>'CAN','a'=>['ALLSTAR','ROY'],           'e'=>['90s','00s','10s','20s']],
    ['n'=>'Tracy McGrady',          't'=>['TOR','ORL','HOU','NYK','DET','ATL','SAS'],'c'=>'USA','a'=>['ALLSTAR','SCORING'],                   'e'=>['90s','00s','10s']],
    ['n'=>'Carmelo Anthony',        't'=>['DEN','NYK','OKC','HOU','POR','LAL'],'c'=>'USA','a'=>['ALLSTAR','SCORING'],                         'e'=>['00s','10s','20s']],
    ['n'=>'Russell Westbrook',      't'=>['OKC','HOU','WAS','LAL','LAC','UTA'],'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING'],                   'e'=>['00s','10s','20s']],
    ['n'=>'James Harden',           't'=>['OKC','HOU','BKN','PHI','LAC'],      'c'=>'USA','a'=>['MVP','ALLSTAR','SCORING'],                    'e'=>['10s','20s']],
    ['n'=>'Derrick Rose',           't'=>['CHI','NYK','CLE','MIN','DET','MEM'],'c'=>'USA','a'=>['MVP','ALLSTAR','ROY'],                       'e'=>['00s','10s','20s']],
    ['n'=>'Jimmy Butler',           't'=>['CHI','MIN','PHI','MIA','GSW'],      'c'=>'USA','a'=>['ALLSTAR'],                                   'e'=>['10s','20s']],
    ['n'=>'Paul George',            't'=>['IND','OKC','LAC'],                  'c'=>'USA','a'=>['ALLSTAR','DPOY'],                            'e'=>['10s','20s']],
    ['n'=>'Kyrie Irving',           't'=>['CLE','BOS','BKN','DAL','MIA'],      'c'=>'USA','a'=>['CHAMPION','ALLSTAR','ROY'],                  'e'=>['10s','20s']],
    ['n'=>'Damian Lillard',         't'=>['POR','MIL'],                        'c'=>'USA','a'=>['ALLSTAR','ROY','SCORING'],                   'e'=>['10s','20s']],
    ['n'=>'Chris Paul',             't'=>['NOH','LAC','HOU','OKC','PHX','SAC','GSW'],'c'=>'USA','a'=>['ALLSTAR','ROY'],                      'e'=>['00s','10s','20s']],
    ['n'=>'Steve Nash',             't'=>['PHX','DAL','LAL'],                  'c'=>'CAN','a'=>['MVP','ALLSTAR'],                            'e'=>['90s','00s','10s']],
    ['n'=>'Jason Kidd',             't'=>['DAL','PHX','NJN','NYK'],            'c'=>'USA','a'=>['CHAMPION','ALLSTAR','ROY'],                  'e'=>['90s','00s','10s']],
    ['n'=>'Pau Gasol',              't'=>['MEM','LAL','CHI','SAS','MIL','POR'],'c'=>'ESP','a'=>['CHAMPION','ALLSTAR'],                       'e'=>['00s','10s']],
    ['n'=>'Rudy Gobert',            't'=>['UTA','MIN'],                        'c'=>'FRA','a'=>['ALLSTAR','DPOY'],                           'e'=>['10s','20s']],
    ['n'=>'Victor Wembanyama',      't'=>['SAS'],                              'c'=>'FRA','a'=>['ROY'],                                      'e'=>['20s']],
    ['n'=>'Draymond Green',         't'=>['GSW'],                              'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY'],                 'e'=>['10s','20s']],
    ['n'=>'Klay Thompson',          't'=>['GSW','DAL'],                        'c'=>'USA','a'=>['CHAMPION','ALLSTAR'],                       'e'=>['10s','20s']],
    ['n'=>'Anthony Davis',          't'=>['NOP','LAL'],                        'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY'],                'e'=>['10s','20s']],
    ['n'=>'Jayson Tatum',           't'=>['BOS'],                              'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP'],          'e'=>['10s','20s']],
    ['n'=>'Jaylen Brown',           't'=>['BOS'],                              'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP'],          'e'=>['10s','20s']],
    ['n'=>'Donovan Mitchell',       't'=>['UTA','CLE'],                        'c'=>'USA','a'=>['ALLSTAR'],                                  'e'=>['10s','20s']],
    ['n'=>'Devin Booker',           't'=>['PHX'],                              'c'=>'USA','a'=>['ALLSTAR','SCORING'],                        'e'=>['10s','20s']],
    ['n'=>'Hakeem Olajuwon',        't'=>['HOU','TOR'],                        'c'=>'NIG','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY'],'e'=>['80s','90s']],
    ['n'=>'Charles Barkley',        't'=>['PHI','PHX','HOU'],                  'c'=>'USA','a'=>['MVP','ALLSTAR'],                           'e'=>['80s','90s']],
    ['n'=>'Scottie Pippen',         't'=>['CHI','HOU','POR'],                  'c'=>'USA','a'=>['CHAMPION','ALLSTAR'],                      'e'=>['80s','90s','00s']],
    ['n'=>'Dennis Rodman',          't'=>['DET','SAS','CHI','LAL','DAL'],      'c'=>'USA','a'=>['CHAMPION','DPOY'],                         'e'=>['80s','90s']],
    ['n'=>'Patrick Ewing',          't'=>['NYK','SEA','ORL','ATL'],            'c'=>'USA','a'=>['ALLSTAR','ROY'],                           'e'=>['80s','90s','00s']],
    ['n'=>'Michael Jordan',         't'=>['CHI','WAS'],                        'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY','SCORING'],'e'=>['80s','90s','00s']],
    ['n'=>'Magic Johnson',          't'=>['LAL'],                              'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP'],   'e'=>['80s','90s']],
    ['n'=>'Larry Bird',             't'=>['BOS'],                              'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP'],   'e'=>['80s','90s']],
    ['n'=>'Isiah Thomas',           't'=>['DET'],                              'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP'],          'e'=>['80s','90s']],
    ['n'=>'Karl Malone',            't'=>['UTA','LAL'],                        'c'=>'USA','a'=>['MVP','ALLSTAR'],                           'e'=>['80s','90s','00s']],
    ['n'=>'John Stockton',          't'=>['UTA'],                              'c'=>'USA','a'=>['ALLSTAR'],                                  'e'=>['80s','90s','00s']],
    ['n'=>'Clyde Drexler',          't'=>['POR','HOU'],                        'c'=>'USA','a'=>['CHAMPION','ALLSTAR'],                      'e'=>['80s','90s']],
    ['n'=>'Gary Payton',            't'=>['SEA','MIL','LAL','BOS','MIA'],      'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY'],               'e'=>['90s','00s']],
    ['n'=>'Shawn Kemp',             't'=>['SEA','CLE','POR','ORL','LAL'],      'c'=>'USA','a'=>['ALLSTAR'],                                 'e'=>['90s','00s']],
    ['n'=>'Grant Hill',             't'=>['DET','ORL','PHX','LAC'],            'c'=>'USA','a'=>['ALLSTAR','ROY'],                           'e'=>['90s','00s','10s']],
    ['n'=>'Reggie Miller',          't'=>['IND'],                              'c'=>'USA','a'=>['ALLSTAR'],                                  'e'=>['80s','90s','00s']],
    ['n'=>'Alonzo Mourning',        't'=>['CHA','MIA','NJN','TOR'],            'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY'],               'e'=>['90s','00s']],
    ['n'=>'David Robinson',         't'=>['SAS'],                              'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','DPOY','SCORING'],'e'=>['80s','90s','00s']],
    ['n'=>'Penny Hardaway',         't'=>['ORL','PHX','NYK','MIA'],            'c'=>'USA','a'=>['ALLSTAR','ROY'],                           'e'=>['90s','00s']],
    ['n'=>'Glen Rice',              't'=>['MIA','CHA','LAL','NYK','HOU'],      'c'=>'USA','a'=>['CHAMPION','ALLSTAR'],                      'e'=>['90s','00s']],
    ['n'=>'Yao Ming',               't'=>['HOU'],                              'c'=>'CHN','a'=>['ALLSTAR'],                                  'e'=>['00s']],
    ['n'=>'Chauncey Billups',       't'=>['DEN','DET','NYK','POR'],            'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP'],          'e'=>['90s','00s','10s']],
    ['n'=>'Ben Wallace',            't'=>['DET','CHI','CLE','PHX'],            'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY'],               'e'=>['90s','00s','10s']],
    ['n'=>'Gilbert Arenas',         't'=>['GSW','WAS','ORL','MEM'],            'c'=>'USA','a'=>['ALLSTAR'],                                  'e'=>['00s','10s']],
    ['n'=>'Amare Stoudemire',       't'=>['PHX','NYK','MIA','DAL','CHI'],      'c'=>'USA','a'=>['ALLSTAR','ROY'],                           'e'=>['00s','10s']],
    ['n'=>'Chris Bosh',             't'=>['TOR','MIA'],                        'c'=>'CAN','a'=>['CHAMPION','ALLSTAR'],                      'e'=>['00s','10s']],
    ['n'=>'Dwight Howard',          't'=>['ORL','LAL','HOU','ATL','CHA','WAS'],'c'=>'USA','a'=>['CHAMPION','ALLSTAR','DPOY'],               'e'=>['00s','10s','20s']],
    ['n'=>'Blake Griffin',          't'=>['LAC','DET','BKN','BOS'],            'c'=>'USA','a'=>['ALLSTAR','ROY'],                           'e'=>['10s','20s']],
    ['n'=>'John Wall',              't'=>['WAS','HOU'],                        'c'=>'USA','a'=>['ALLSTAR','ROY'],                           'e'=>['10s','20s']],
    ['n'=>'Kevin Love',             't'=>['MIN','CLE','MIA'],                  'c'=>'USA','a'=>['CHAMPION','ALLSTAR'],                      'e'=>['00s','10s','20s']],
    ['n'=>'Bradley Beal',           't'=>['WAS','PHX'],                        'c'=>'USA','a'=>['ALLSTAR'],                                  'e'=>['10s','20s']],
    ['n'=>'Kemba Walker',           't'=>['CHA','BOS','NYK','OKC'],            'c'=>'USA','a'=>['ALLSTAR'],                                  'e'=>['10s','20s']],
    ['n'=>'Bam Adebayo',            't'=>['MIA'],                              'c'=>'USA','a'=>['ALLSTAR'],                                  'e'=>['10s','20s']],
    ['n'=>'LaMelo Ball',            't'=>['CHA'],                              'c'=>'USA','a'=>['ALLSTAR','ROY'],                           'e'=>['20s']],
    ['n'=>'Ja Morant',              't'=>['MEM'],                              'c'=>'USA','a'=>['ALLSTAR','ROY'],                           'e'=>['10s','20s']],
    ['n'=>'Anthony Edwards',        't'=>['MIN'],                              'c'=>'USA','a'=>['ALLSTAR','ROY'],                           'e'=>['20s']],
    ['n'=>'Tyrese Haliburton',      't'=>['SAC','IND'],                        'c'=>'USA','a'=>['ALLSTAR'],                                  'e'=>['20s']],
    ['n'=>'Trae Young',             't'=>['ATL'],                              'c'=>'USA','a'=>['ALLSTAR'],                                  'e'=>['10s','20s']],
    ['n'=>'Karl-Anthony Towns',     't'=>['MIN','NYK'],                        'c'=>'DOM','a'=>['ALLSTAR','ROY'],                           'e'=>['10s','20s']],
    ['n'=>'Zion Williamson',        't'=>['NOP'],                              'c'=>'USA','a'=>['ALLSTAR','ROY'],                           'e'=>['10s','20s']],
    ['n'=>'Scottie Barnes',         't'=>['TOR'],                              'c'=>'CAN','a'=>['ROY'],                                     'e'=>['20s']],
    ['n'=>'Andrew Wiggins',         't'=>['MIN','GSW'],                        'c'=>'CAN','a'=>['CHAMPION','ALLSTAR','ROY'],                'e'=>['10s','20s']],
    ['n'=>'Jamal Murray',           't'=>['DEN'],                              'c'=>'CAN','a'=>['CHAMPION'],                                'e'=>['10s','20s']],
    ['n'=>'Shai Gilgeous-Alexander','t'=>['LAC','OKC'],                        'c'=>'CAN','a'=>['ALLSTAR','SCORING'],                      'e'=>['10s','20s']],
    ['n'=>'DeMar DeRozan',          't'=>['TOR','SAS','CHI'],                  'c'=>'CAN','a'=>['ALLSTAR'],                                 'e'=>['00s','10s','20s']],
    ['n'=>'Kyle Lowry',             't'=>['MEM','HOU','TOR','MIA','PHI'],      'c'=>'USA','a'=>['CHAMPION','ALLSTAR'],                     'e'=>['00s','10s','20s']],
    ['n'=>'Nenê',                   't'=>['DEN','HOU','WAS'],                  'c'=>'BRA','a'=>['ALLSTAR'],                                 'e'=>['00s','10s']],
    ['n'=>'Anderson Varejão',       't'=>['CLE','POR','GSW'],                  'c'=>'BRA','a'=>[],                                         'e'=>['00s','10s']],
    ['n'=>'Leandro Barbosa',        't'=>['PHX','TOR','IND','GSW','BOS'],      'c'=>'BRA','a'=>['SIXTHMAN'],                               'e'=>['00s','10s']],
    ['n'=>'Tiago Splitter',         't'=>['SAS','ATL','SAC','PHX'],            'c'=>'BRA','a'=>['CHAMPION'],                               'e'=>['10s']],
    ['n'=>'Raul Neto',              't'=>['UTA','PHI','WAS','LAC'],            'c'=>'BRA','a'=>[],                                         'e'=>['10s','20s']],
    ['n'=>'Cristiano Felicio',      't'=>['CHI','POR'],                        'c'=>'BRA','a'=>[],                                         'e'=>['10s','20s']],
    ['n'=>'Marcelinho Huertas',     't'=>['LAL'],                              'c'=>'BRA','a'=>[],                                         'e'=>['10s']],
    ['n'=>'Rudy Gobert',            't'=>['UTA','MIN'],                        'c'=>'FRA','a'=>['ALLSTAR','DPOY'],                         'e'=>['10s','20s']],
    ['n'=>'Nicolas Batum',          't'=>['POR','CHA','LAC','PHI'],            'c'=>'FRA','a'=>[],                                         'e'=>['00s','10s','20s']],
    ['n'=>'Boris Diaw',             't'=>['ATL','PHX','CHA','SAS','UTA'],      'c'=>'FRA','a'=>['CHAMPION','SIXTHMAN'],                    'e'=>['00s','10s']],
    ['n'=>'Evan Fournier',          't'=>['DEN','ORL','BOS','NYK'],            'c'=>'FRA','a'=>[],                                         'e'=>['10s','20s']],
    ['n'=>'Goran Dragic',           't'=>['PHX','MIA','TOR','BKN','CHI'],      'c'=>'SLO','a'=>['ALLSTAR'],                                'e'=>['00s','10s','20s']],
    ['n'=>'Luis Scola',             't'=>['HOU','PHX','TOR','IND','NJN'],      'c'=>'ARG','a'=>['ALLSTAR'],                                'e'=>['00s','10s']],
    ['n'=>'Manu Ginobili',          't'=>['SAS'],                              'c'=>'ARG','a'=>['CHAMPION','ALLSTAR','SIXTHMAN'],           'e'=>['00s','10s']],
    ['n'=>'Pau Gasol',              't'=>['MEM','LAL','CHI','SAS','MIL','POR'],'c'=>'ESP','a'=>['CHAMPION','ALLSTAR'],                     'e'=>['00s','10s']],
    ['n'=>'Ricky Rubio',            't'=>['MIN','UTA','PHX','CLE','BKN'],      'c'=>'ESP','a'=>[],                                         'e'=>['10s','20s']],
    ['n'=>'Willy Hernangomez',      't'=>['NYK','CHA','NOP'],                  'c'=>'ESP','a'=>[],                                         'e'=>['10s','20s']],
    ['n'=>'Nikola Vucevic',         't'=>['PHI','ORL','CHI'],                  'c'=>'SER','a'=>['ALLSTAR'],                                'e'=>['10s','20s']],
    ['n'=>'Bogdan Bogdanovic',      't'=>['SAC','ATL','MIL'],                  'c'=>'SER','a'=>[],                                         'e'=>['10s','20s']],
    ['n'=>'Vlade Divac',            't'=>['LAL','CHA','SAC'],                  'c'=>'SER','a'=>['ALLSTAR'],                                'e'=>['90s','00s']],
    ['n'=>'Predrag Stojakovic',     't'=>['SAC','IND','NOP','TOR','DAL'],      'c'=>'SER','a'=>['CHAMPION','ALLSTAR'],                     'e'=>['90s','00s','10s']],
    ['n'=>'Dennis Schroder',        't'=>['ATL','OKC','LAL','BOS','HOU','TOR'],'c'=>'GER','a'=>['SIXTHMAN'],                              'e'=>['10s','20s']],
    ['n'=>'Daniel Theis',           't'=>['BOS','CHI','HOU','IND','NOP'],      'c'=>'GER','a'=>[],                                         'e'=>['10s','20s']],
    ['n'=>'Patty Mills',            't'=>['POR','SAS','BKN','ATL'],            'c'=>'AUS','a'=>['CHAMPION','SIXTHMAN'],                    'e'=>['10s','20s']],
    ['n'=>'Andrew Bogut',           't'=>['MIL','GSW','DAL','LAL','CLE'],      'c'=>'AUS','a'=>['CHAMPION'],                               'e'=>['00s','10s']],
    ['n'=>'Joe Ingles',             't'=>['UTA','MIL','POR','ORL'],            'c'=>'AUS','a'=>[],                                         'e'=>['10s','20s']],
    ['n'=>'Ben Simmons',            't'=>['PHI','BKN'],                        'c'=>'AUS','a'=>['ALLSTAR','ROY'],                          'e'=>['10s','20s']],
    ['n'=>'Josh Giddey',            't'=>['OKC','CHI'],                        'c'=>'AUS','a'=>[],                                         'e'=>['20s']],
    ['n'=>'Dikembe Mutombo',        't'=>['DEN','ATL','PHI','NJN','NYK','HOU'],'c'=>'COD','a'=>['ALLSTAR','DPOY'],                        'e'=>['90s','00s']],
    ['n'=>'Hakeem Olajuwon',        't'=>['HOU','TOR'],                        'c'=>'NIG','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','DPOY'],'e'=>['80s','90s']],
    ['n'=>'Khris Middleton',        't'=>['DET','MIL'],                        'c'=>'USA','a'=>['CHAMPION','ALLSTAR'],                     'e'=>['10s','20s']],
    ['n'=>'Jrue Holiday',           't'=>['PHI','NOP','MIL','BOS','POR'],      'c'=>'USA','a'=>['CHAMPION'],                               'e'=>['00s','10s','20s']],
    ['n'=>'Domantas Sabonis',       't'=>['OKC','IND','SAC'],                  'c'=>'LIT','a'=>['ALLSTAR','SIXTHMAN'],                     'e'=>['10s','20s']],
    ['n'=>'Jonas Valanciunas',      't'=>['TOR','MEM','NOP'],                  'c'=>'LIT','a'=>['ALLSTAR'],                                'e'=>['10s','20s']],
    ['n'=>'Arvydas Sabonis',        't'=>['POR'],                              'c'=>'LIT','a'=>['ALLSTAR'],                                'e'=>['90s','00s']],
    ['n'=>'Sarunas Marciulionis',   't'=>['GSW','SEA','SAC','HOU'],            'c'=>'LIT','a'=>[],                                         'e'=>['90s']],
    ['n'=>'Dario Saric',            't'=>['PHI','MIN','PHX','OKC'],            'c'=>'CRO','a'=>[],                                         'e'=>['10s','20s']],
    ['n'=>'Toni Kukoc',             't'=>['CHI','ATL','PHI','MIL'],            'c'=>'CRO','a'=>['CHAMPION','ALLSTAR','SIXTHMAN'],           'e'=>['90s','00s']],
    ['n'=>'Drazen Petrovic',        't'=>['POR','NJN'],                        'c'=>'CRO','a'=>['ALLSTAR'],                                'e'=>['90s']],
    ['n'=>'Cedi Osman',             't'=>['CLE','SAS'],                        'c'=>'TUR','a'=>[],                                         'e'=>['10s','20s']],
    ['n'=>'Enes Kanter',            't'=>['OKC','NYK','POR','BOS','CLE'],      'c'=>'TUR','a'=>[],                                         'e'=>['10s','20s']],
    ['n'=>'Hedo Turkoglu',          't'=>['ORL','SAS','SAC','TOR','PHX'],      'c'=>'TUR','a'=>['SIXTHMAN'],                               'e'=>['00s','10s']],
    ['n'=>'Danilo Gallinari',       't'=>['NYK','DEN','LAC','ATL','BOS','MIL'],'c'=>'ITA','a'=>[],                                         'e'=>['00s','10s','20s']],
    ['n'=>'Marco Belinelli',        't'=>['GSW','CHA','CHI','SAS','ATL','SAC'],'c'=>'ITA','a'=>['CHAMPION'],                              'e'=>['00s','10s','20s']],
    ['n'=>'Kristaps Porzingis',     't'=>['NYK','DAL','WAS','BOS'],            'c'=>'LAT','a'=>['ALLSTAR'],                                'e'=>['10s','20s']],
    ['n'=>'Davis Bertans',          't'=>['SAS','OKC','WAS','DAL'],            'c'=>'LAT','a'=>[],                                         'e'=>['10s','20s']],
    ['n'=>'Karl-Anthony Towns',     't'=>['MIN','NYK'],                        'c'=>'DOM','a'=>['ALLSTAR','ROY'],                          'e'=>['10s','20s']],
    ['n'=>'Serge Ibaka',            't'=>['OKC','ORL','TOR','LAC'],            'c'=>'SEN','a'=>['CHAMPION','ALLSTAR','DPOY'],              'e'=>['10s','20s']],
    ['n'=>'Bismack Biyombo',        't'=>['CHA','TOR','ORL','PHX','MEM'],      'c'=>'COD','a'=>[],                                         'e'=>['10s','20s']],
    ['n'=>'Marco Belinelli',        't'=>['GSW','CHA','CHI','SAS','ATL','SAC'],'c'=>'ITA','a'=>['CHAMPION'],                              'e'=>['00s','10s','20s']],
    ['n'=>'Jose Calderon',          't'=>['TOR','DET','NYK','DAL','ATL','GSW'],'c'=>'ESP','a'=>[],                                         'e'=>['00s','10s']],
    ['n'=>'Jorge Garbajosa',        't'=>['TOR'],                              'c'=>'ESP','a'=>[],                                         'e'=>['00s']],
    ['n'=>'Rajon Rondo',            't'=>['BOS','DAL','SAC','CHI','NOP','LAL'],'c'=>'USA','a'=>['CHAMPION','ALLSTAR'],                     'e'=>['00s','10s','20s']],
    ['n'=>'DeMarcus Cousins',       't'=>['SAC','NOP','GSW','LAL','HOU'],      'c'=>'USA','a'=>['ALLSTAR'],                                'e'=>['10s','20s']],
    ['n'=>'Marc Gasol',             't'=>['MEM','TOR','LAL'],                  'c'=>'ESP','a'=>['CHAMPION','ALLSTAR','DPOY'],              'e'=>['00s','10s','20s']],
    ['n'=>'De\'Aaron Fox',          't'=>['SAC'],                              'c'=>'USA','a'=>['ALLSTAR'],                                'e'=>['10s','20s']],
    ['n'=>'Evan Mobley',            't'=>['CLE'],                              'c'=>'USA','a'=>['ALLSTAR'],                                'e'=>['20s']],
    ['n'=>'Cade Cunningham',        't'=>['DET'],                              'c'=>'USA','a'=>['ALLSTAR'],                                'e'=>['20s']],
    ['n'=>'Franz Wagner',           't'=>['ORL'],                              'c'=>'GER','a'=>[],                                         'e'=>['20s']],
    ['n'=>'Alperen Sengun',         't'=>['HOU'],                              'c'=>'TUR','a'=>[],                                         'e'=>['20s']],
    ['n'=>'Pascal Siakam',          't'=>['TOR','IND'],                        'c'=>'CAM','a'=>['CHAMPION','ALLSTAR'],                     'e'=>['10s','20s']],
    ['n'=>'Paolo Banchero',         't'=>['ORL'],                              'c'=>'USA','a'=>['ALLSTAR','ROY'],                          'e'=>['20s']],

    // ── Lendas dos anos 80 ──────────────────────────────────────────────────
    ['n'=>'Kareem Abdul-Jabbar',    't'=>['MIL','LAL'],                        'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP','SCORING'],'e'=>['80s','90s']],
    ['n'=>'Julius Erving',          't'=>['PHI'],                              'c'=>'USA','a'=>['MVP','ALLSTAR','FINALS_MVP'],                    'e'=>['80s']],
    ['n'=>'Moses Malone',           't'=>['HOU','PHI','WAS','ATL','MIL','SAS'],'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','FINALS_MVP'],        'e'=>['80s','90s']],
    ['n'=>'James Worthy',           't'=>['LAL'],                              'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP'],               'e'=>['80s','90s']],
    ['n'=>'Dominique Wilkins',      't'=>['ATL','LAC','BOS','PHX'],            'c'=>'USA','a'=>['ALLSTAR','SCORING'],                            'e'=>['80s','90s']],
    ['n'=>'Kevin McHale',           't'=>['BOS'],                              'c'=>'USA','a'=>['CHAMPION','ALLSTAR','SIXTHMAN'],                 'e'=>['80s','90s']],
    ['n'=>'Robert Parish',          't'=>['BOS','CHI','CHA'],                  'c'=>'USA','a'=>['CHAMPION','ALLSTAR'],                           'e'=>['80s','90s']],
    ['n'=>'Bill Walton',            't'=>['POR','BOS'],                        'c'=>'USA','a'=>['CHAMPION','MVP','DPOY','SIXTHMAN'],              'e'=>['80s']],
    ['n'=>'Alex English',           't'=>['DEN','DAL'],                        'c'=>'USA','a'=>['ALLSTAR','SCORING'],                            'e'=>['80s']],
    ['n'=>'Mitch Richmond',         't'=>['GSW','SAC','WAS','LAL'],            'c'=>'USA','a'=>['ALLSTAR','SCORING','ROY'],                      'e'=>['80s','90s']],
    ['n'=>'Detlef Schrempf',        't'=>['IND','SEA'],                        'c'=>'GER','a'=>['ALLSTAR','SIXTHMAN'],                           'e'=>['80s','90s','00s']],
    ['n'=>'Dan Majerle',            't'=>['PHX','CLE','MIA'],                  'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['80s','90s','00s']],
    ['n'=>'Mark Price',             't'=>['CLE','WAS','GSW','ORL'],            'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['80s','90s']],
    ['n'=>'Tom Chambers',           't'=>['PHX','UTA'],                        'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['80s','90s']],
    ['n'=>'Adrian Dantley',         't'=>['UTA','DET','DAL','MIL','LAL'],      'c'=>'USA','a'=>['ALLSTAR','SCORING'],                            'e'=>['80s','90s']],
    ['n'=>'Bernard King',           't'=>['NJN','UTA','NYK','WAS','IND'],      'c'=>'USA','a'=>['ALLSTAR','SCORING'],                            'e'=>['80s','90s']],
    ['n'=>'Bob McAdoo',             't'=>['BOS','LAL','NYK','MIA'],            'c'=>'USA','a'=>['MVP','CHAMPION','ALLSTAR','SCORING'],           'e'=>['80s']],
    ['n'=>'Bill Laimbeer',          't'=>['DET'],                              'c'=>'USA','a'=>['CHAMPION','ALLSTAR'],                           'e'=>['80s','90s']],
    ['n'=>'Joe Dumars',             't'=>['DET'],                              'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP'],              'e'=>['80s','90s']],

    // ── Anos 90 ─────────────────────────────────────────────────────────────
    ['n'=>'Tim Hardaway',           't'=>['GSW','MIA','DAL','DEN','IND'],      'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['90s','00s']],
    ['n'=>'Latrell Sprewell',       't'=>['GSW','NYK','MIN'],                  'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['90s','00s']],
    ['n'=>'Larry Johnson',          't'=>['CHA','NYK'],                        'c'=>'USA','a'=>['ALLSTAR','ROY'],                                'e'=>['90s']],
    ['n'=>'Horace Grant',           't'=>['CHI','ORL','LAL'],                  'c'=>'USA','a'=>['CHAMPION'],                                     'e'=>['90s','00s']],
    ['n'=>'Glenn Robinson',         't'=>['MIL','ATL','PHI'],                  'c'=>'USA','a'=>['ALLSTAR','ROY'],                                'e'=>['90s','00s']],
    ['n'=>'Sam Cassell',            't'=>['HOU','PHX','DAL','BKN','MIL','MIN','LAC','BOS'],'c'=>'USA','a'=>['CHAMPION','ALLSTAR'],               'e'=>['90s','00s']],
    ['n'=>'Cedric Ceballos',        't'=>['PHX','LAL','MIA'],                  'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['90s']],
    ['n'=>'John Starks',            't'=>['NYK','GSW','UTA','CHI'],            'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['90s','00s']],
    ['n'=>'Nick Van Exel',          't'=>['LAL','DEN','DAL','POR','GSW','SAS'],'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['90s','00s']],
    ['n'=>'Vin Baker',              't'=>['MIL','HOU'],                        'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['90s','00s']],
    ['n'=>'Muggsy Bogues',          't'=>['WAS','CHA','GSW','TOR'],            'c'=>'USA','a'=>[],                                              'e'=>['80s','90s','00s']],
    ['n'=>'Nick Anderson',          't'=>['ORL','SAC','MEM','HOU'],            'c'=>'USA','a'=>[],                                              'e'=>['90s','00s']],
    ['n'=>'Hersey Hawkins',         't'=>['PHI','CHA','ATL','CHI'],            'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['80s','90s','00s']],

    // ── Anos 2000 ────────────────────────────────────────────────────────────
    ['n'=>'Andre Iguodala',         't'=>['PHI','DEN','GSW','MIA'],            'c'=>'USA','a'=>['CHAMPION','ALLSTAR','FINALS_MVP','SIXTHMAN'],   'e'=>['00s','10s','20s']],
    ['n'=>'Shawn Marion',           't'=>['PHX','TOR','DAL','MIA','IND'],      'c'=>'USA','a'=>['CHAMPION','ALLSTAR'],                           'e'=>['00s','10s']],
    ['n'=>'Elton Brand',            't'=>['CHI','LAC','PHI','DAL'],            'c'=>'USA','a'=>['ALLSTAR','ROY'],                                'e'=>['90s','00s','10s']],
    ['n'=>'Zach Randolph',          't'=>['POR','NYK','LAC','MEM','SAC'],      'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['00s','10s']],
    ['n'=>'Lamar Odom',             't'=>['LAC','MIA','LAL','DAL'],            'c'=>'USA','a'=>['CHAMPION','ALLSTAR','SIXTHMAN'],                'e'=>['00s','10s']],
    ['n'=>'Ron Artest',             't'=>['CHI','IND','SAC','HOU','LAL','NYK'],'c'=>'USA','a'=>['CHAMPION','DPOY'],                             'e'=>['90s','00s','10s']],
    ['n'=>'Baron Davis',            't'=>['CHA','NOP','GSW','CLE','NYK'],      'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['00s','10s']],
    ['n'=>'Paul Millsap',           't'=>['UTA','ATL','DEN','TOR'],            'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['00s','10s','20s']],
    ['n'=>'Josh Smith',             't'=>['ATL','DET','HOU','LAC','IND'],      'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['00s','10s']],
    ['n'=>'Joakim Noah',            't'=>['CHI','NYK','LAC'],                  'c'=>'FRA','a'=>['ALLSTAR','DPOY'],                              'e'=>['00s','10s']],
    ['n'=>'Luol Deng',              't'=>['CHI','CLE','MIA','LAL','MIN'],      'c'=>'GBR','a'=>['ALLSTAR'],                                      'e'=>['00s','10s']],
    ['n'=>'Al Horford',             't'=>['ATL','BOS','PHI','OKC','MIA'],      'c'=>'DOM','a'=>['CHAMPION','ALLSTAR'],                           'e'=>['00s','10s','20s']],
    ['n'=>'Richard Jefferson',      't'=>['NJN','BKN','MIL','SAS','UTA','GSW','CLE'],'c'=>'USA','a'=>['CHAMPION','ALLSTAR'],                   'e'=>['00s','10s','20s']],
    ['n'=>'Udonis Haslem',          't'=>['MIA'],                              'c'=>'USA','a'=>['CHAMPION'],                                     'e'=>['00s','10s','20s']],
    ['n'=>'Jameer Nelson',          't'=>['ORL','DEN','DAL','NOP','SAC'],      'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['00s','10s']],

    // ── Anos 2010 e 2020 ─────────────────────────────────────────────────────
    ['n'=>'Andre Drummond',         't'=>['DET','CLE','PHI','LAL','CHI','ATL'],'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['10s','20s']],
    ['n'=>'Brook Lopez',            't'=>['BKN','LAL','MIL'],                  'c'=>'USA','a'=>['CHAMPION'],                                     'e'=>['00s','10s','20s']],
    ['n'=>'Brandon Ingram',         't'=>['LAL','NOP'],                        'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['10s','20s']],
    ['n'=>'Dejounte Murray',        't'=>['SAS','ATL','NOP'],                  'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['10s','20s']],
    ['n'=>'Marcus Smart',           't'=>['BOS','MEM','POR'],                  'c'=>'USA','a'=>['CHAMPION','DPOY'],                             'e'=>['10s','20s']],
    ['n'=>'Malcolm Brogdon',        't'=>['MIL','IND','BOS'],                  'c'=>'USA','a'=>['ROY','SIXTHMAN'],                              'e'=>['10s','20s']],
    ['n'=>'Tyler Herro',            't'=>['MIA'],                              'c'=>'USA','a'=>['SIXTHMAN','ALLSTAR'],                           'e'=>['10s','20s']],
    ['n'=>'Alex Caruso',            't'=>['LAL','CHI','OKC'],                  'c'=>'USA','a'=>['CHAMPION'],                                     'e'=>['10s','20s']],
    ['n'=>'Patrick Beverley',       't'=>['HOU','LAC','MIN','LAL','CHI'],      'c'=>'USA','a'=>['CHAMPION'],                                     'e'=>['10s','20s']],
    ['n'=>'Mikal Bridges',          't'=>['PHX','BKN','NYK'],                  'c'=>'USA','a'=>['ALLSTAR'],                                      'e'=>['10s','20s']],
    ['n'=>'Jaren Jackson Jr.',      't'=>['MEM'],                              'c'=>'USA','a'=>['ALLSTAR','DPOY'],                              'e'=>['10s','20s']],
    ['n'=>'Myles Turner',           't'=>['IND'],                              'c'=>'USA','a'=>['ALLSTAR','DPOY'],                              'e'=>['10s','20s']],
    ['n'=>'Eric Gordon',            't'=>['LAC','NOP','HOU','PHX','SAC'],      'c'=>'USA','a'=>['SIXTHMAN'],                                     'e'=>['00s','10s','20s']],
    ['n'=>'Dillon Brooks',          't'=>['MEM','HOU'],                        'c'=>'CAN','a'=>[],                                              'e'=>['10s','20s']],
    ['n'=>'RJ Barrett',             't'=>['NYK','TOR'],                        'c'=>'CAN','a'=>['ALLSTAR'],                                      'e'=>['10s','20s']],
    ['n'=>'Tristan Thompson',       't'=>['CLE','BOS','SAC','IND','CHI'],      'c'=>'CAN','a'=>['CHAMPION'],                                     'e'=>['10s','20s']],
    ['n'=>'Bojan Bogdanovic',       't'=>['BKN','IND','UTA','DET'],            'c'=>'CRO','a'=>['ALLSTAR'],                                      'e'=>['10s','20s']],
    ['n'=>'Ivica Zubac',            't'=>['LAL','LAC'],                        'c'=>'CRO','a'=>[],                                              'e'=>['10s','20s']],
    ['n'=>'Nikola Mirotic',         't'=>['CHI','NOP','MIL','BKN'],            'c'=>'ESP','a'=>[],                                              'e'=>['10s']],
    ['n'=>'Lauri Markkanen',        't'=>['CHI','CLE','UTA'],                  'c'=>'FIN','a'=>['ALLSTAR'],                                      'e'=>['10s','20s']],
    ['n'=>'Rui Hachimura',          't'=>['WAS','LAL'],                        'c'=>'JPN','a'=>['CHAMPION'],                                     'e'=>['10s','20s']],
    ['n'=>'Yuta Watanabe',          't'=>['TOR','BKN','PHX'],                  'c'=>'JPN','a'=>[],                                              'e'=>['10s','20s']],
    ['n'=>'Deandre Ayton',          't'=>['PHX','POR'],                        'c'=>'BAH','a'=>['ALLSTAR'],                                      'e'=>['10s','20s']],
    ['n'=>'Buddy Hield',            't'=>['NOP','SAC','IND','PHI','GSW','LAL'],'c'=>'BAH','a'=>['ALLSTAR'],                                      'e'=>['10s','20s']],
    ['n'=>'OG Anunoby',             't'=>['TOR','NYK'],                        'c'=>'ENG','a'=>['CHAMPION'],                                     'e'=>['10s','20s']],
    ['n'=>'Jordan Clarkson',        't'=>['WAS','LAL','CLE','UTA'],            'c'=>'PHL','a'=>['SIXTHMAN'],                                     'e'=>['10s','20s']],
    ['n'=>'Zaza Pachulia',          't'=>['ATL','MIL','DAL','DET','GSW'],      'c'=>'GEO','a'=>['CHAMPION'],                                     'e'=>['00s','10s']],
    ['n'=>'Thanasis Antetokounmpo', 't'=>['NYK','MIL'],                        'c'=>'GRE','a'=>['CHAMPION'],                                     'e'=>['10s','20s']],
    ['n'=>'Georgios Papagiannis',   't'=>['SAC','NOP'],                        'c'=>'GRE','a'=>[],                                              'e'=>['10s','20s']],
    ['n'=>'Al-Farouq Aminu',        't'=>['LAC','NOP','POR','ORL','WAS','UTA'],'c'=>'NIG','a'=>[],                                              'e'=>['00s','10s','20s']],
    ['n'=>'Thaddeus Young',         't'=>['PHI','MIN','BKN','IND','CHI','TOR'],'c'=>'USA','a'=>[],                                              'e'=>['00s','10s','20s']],
];

// Seed DB se vazio
try {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM hoopgrid_players")->fetchColumn();
    if ($cnt === 0) {
        $stmtSeed = $pdo->prepare("INSERT IGNORE INTO hoopgrid_players (nome, times, pais, premios, eras) VALUES (?,?,?,?,?)");
        $seenSeed = [];
        foreach ($_SEED as $p) {
            if (!isset($seenSeed[$p['n']])) {
                $seenSeed[$p['n']] = 1;
                $stmtSeed->execute([$p['n'], json_encode($p['t']), $p['c'], json_encode($p['a']), json_encode($p['e'])]);
            }
        }
    }
} catch (PDOException $e) {}
unset($_SEED);

// Carregar jogadores do banco
$PLAYERS = [];
try {
    $rowsPlayers = $pdo->query("SELECT nome, times, pais, premios, eras FROM hoopgrid_players WHERE ativo=1")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsPlayers as $rp) {
        $PLAYERS[] = [
            'n' => $rp['nome'],
            't' => json_decode($rp['times'],  true) ?: [],
            'c' => $rp['pais'],
            'a' => json_decode($rp['premios'], true) ?: [],
            'e' => json_decode($rp['eras'],    true) ?: [],
        ];
    }
} catch (PDOException $e) { $PLAYERS = []; }

// ── CRITÉRIOS ──────────────────────────────────────────────────────────────
$CRITERIA = [
    // Times
    ['id'=>'LAL','type'=>'team',  'label'=>'Lakers',       'icon'=>'🟡','full'=>'Los Angeles Lakers',     'nba_id'=>1610612747],
    ['id'=>'GSW','type'=>'team',  'label'=>'Warriors',     'icon'=>'💛','full'=>'Golden State Warriors',  'nba_id'=>1610612744],
    ['id'=>'BOS','type'=>'team',  'label'=>'Celtics',      'icon'=>'🍀','full'=>'Boston Celtics',         'nba_id'=>1610612738],
    ['id'=>'MIA','type'=>'team',  'label'=>'Heat',         'icon'=>'🔴','full'=>'Miami Heat',             'nba_id'=>1610612748],
    ['id'=>'SAS','type'=>'team',  'label'=>'Spurs',        'icon'=>'⚫','full'=>'San Antonio Spurs',      'nba_id'=>1610612759],
    ['id'=>'CHI','type'=>'team',  'label'=>'Bulls',        'icon'=>'🐂','full'=>'Chicago Bulls',          'nba_id'=>1610612741],
    ['id'=>'OKC','type'=>'team',  'label'=>'Thunder',      'icon'=>'⚡','full'=>'OKC Thunder',            'nba_id'=>1610612760],
    ['id'=>'DEN','type'=>'team',  'label'=>'Nuggets',      'icon'=>'⛏️','full'=>'Denver Nuggets',         'nba_id'=>1610612743],
    ['id'=>'DAL','type'=>'team',  'label'=>'Mavericks',    'icon'=>'🐎','full'=>'Dallas Mavericks',       'nba_id'=>1610612742],
    ['id'=>'TOR','type'=>'team',  'label'=>'Raptors',      'icon'=>'🦖','full'=>'Toronto Raptors',        'nba_id'=>1610612761],
    ['id'=>'PHI','type'=>'team',  'label'=>'76ers',        'icon'=>'🔔','full'=>'Philadelphia 76ers',     'nba_id'=>1610612755],
    ['id'=>'MIL','type'=>'team',  'label'=>'Bucks',        'icon'=>'🦌','full'=>'Milwaukee Bucks',        'nba_id'=>1610612749],
    ['id'=>'HOU','type'=>'team',  'label'=>'Rockets',      'icon'=>'🚀','full'=>'Houston Rockets',        'nba_id'=>1610612745],
    ['id'=>'PHX','type'=>'team',  'label'=>'Suns',         'icon'=>'☀️','full'=>'Phoenix Suns',           'nba_id'=>1610612756],
    ['id'=>'CLE','type'=>'team',  'label'=>'Cavaliers',    'icon'=>'⚔️','full'=>'Cleveland Cavaliers',    'nba_id'=>1610612739],
    ['id'=>'NYK','type'=>'team',  'label'=>'Knicks',       'icon'=>'🗽','full'=>'New York Knicks',         'nba_id'=>1610612752],
    ['id'=>'MIN','type'=>'team',  'label'=>'T-Wolves',     'icon'=>'🐺','full'=>'Minnesota Timberwolves', 'nba_id'=>1610612750],
    ['id'=>'UTA','type'=>'team',  'label'=>'Jazz',         'icon'=>'🎵','full'=>'Utah Jazz',              'nba_id'=>1610612762],
    ['id'=>'IND','type'=>'team',  'label'=>'Pacers',       'icon'=>'🏎️','full'=>'Indiana Pacers',         'nba_id'=>1610612754],
    ['id'=>'ATL','type'=>'team',  'label'=>'Hawks',        'icon'=>'🦅','full'=>'Atlanta Hawks',          'nba_id'=>1610612737],
    ['id'=>'POR','type'=>'team',  'label'=>'Trail Blazers','icon'=>'🌹','full'=>'Portland Trail Blazers', 'nba_id'=>1610612757],
    ['id'=>'ORL','type'=>'team',  'label'=>'Magic',        'icon'=>'🪄','full'=>'Orlando Magic',          'nba_id'=>1610612753],
    ['id'=>'SAC','type'=>'team',  'label'=>'Kings',        'icon'=>'👑','full'=>'Sacramento Kings',       'nba_id'=>1610612758],
    ['id'=>'BKN','type'=>'team',  'label'=>'Nets',         'icon'=>'🕸️','full'=>'Brooklyn Nets',          'nba_id'=>1610612751],
    ['id'=>'MEM','type'=>'team',  'label'=>'Grizzlies',    'icon'=>'🐻','full'=>'Memphis Grizzlies',      'nba_id'=>1610612763],
    ['id'=>'NOP','type'=>'team',  'label'=>'Pelicans',     'icon'=>'🦅','full'=>'New Orleans Pelicans',   'nba_id'=>1610612740],
    // Países
    ['id'=>'USA','type'=>'nation','label'=>'EUA',          'icon'=>'🇺🇸','full'=>'Estados Unidos'],
    ['id'=>'BRA','type'=>'nation','label'=>'Brasil',       'icon'=>'🇧🇷','full'=>'Brasil'],
    ['id'=>'FRA','type'=>'nation','label'=>'França',       'icon'=>'🇫🇷','full'=>'França'],
    ['id'=>'ARG','type'=>'nation','label'=>'Argentina',    'icon'=>'🇦🇷','full'=>'Argentina'],
    ['id'=>'SLO','type'=>'nation','label'=>'Eslovênia',    'icon'=>'🇸🇮','full'=>'Eslovênia'],
    ['id'=>'SER','type'=>'nation','label'=>'Sérvia',       'icon'=>'🇷🇸','full'=>'Sérvia'],
    ['id'=>'GER','type'=>'nation','label'=>'Alemanha',     'icon'=>'🇩🇪','full'=>'Alemanha'],
    ['id'=>'CAN','type'=>'nation','label'=>'Canadá',       'icon'=>'🇨🇦','full'=>'Canadá'],
    ['id'=>'ESP','type'=>'nation','label'=>'Espanha',      'icon'=>'🇪🇸','full'=>'Espanha'],
    ['id'=>'AUS','type'=>'nation','label'=>'Austrália',    'icon'=>'🇦🇺','full'=>'Austrália'],
    ['id'=>'LIT','type'=>'nation','label'=>'Lituânia',     'icon'=>'🇱🇹','full'=>'Lituânia'],
    ['id'=>'CRO','type'=>'nation','label'=>'Croácia',      'icon'=>'🇭🇷','full'=>'Croácia'],
    ['id'=>'TUR','type'=>'nation','label'=>'Turquia',      'icon'=>'🇹🇷','full'=>'Turquia'],
    ['id'=>'ITA','type'=>'nation','label'=>'Itália',       'icon'=>'🇮🇹','full'=>'Itália'],
    ['id'=>'LAT','type'=>'nation','label'=>'Letônia',      'icon'=>'🇱🇻','full'=>'Letônia'],
    ['id'=>'GRE','type'=>'nation','label'=>'Grécia',       'icon'=>'🇬🇷','full'=>'Grécia'],
    ['id'=>'DOM','type'=>'nation','label'=>'Rep. Dominicana','icon'=>'🇩🇴','full'=>'República Dominicana'],
    ['id'=>'CAM','type'=>'nation','label'=>'Camarões',     'icon'=>'🇨🇲','full'=>'Camarões'],
    ['id'=>'FIN','type'=>'nation','label'=>'Finlândia',    'icon'=>'🇫🇮','full'=>'Finlândia'],
    // Prêmios
    ['id'=>'MVP',       'type'=>'award','label'=>'MVP',        'icon'=>'🏆','full'=>'MVP da Temporada'],
    ['id'=>'CHAMPION',  'type'=>'award','label'=>'Campeão',    'icon'=>'💍','full'=>'Campeão NBA'],
    ['id'=>'ALLSTAR',   'type'=>'award','label'=>'All-Star',   'icon'=>'⭐','full'=>'All-Star Game'],
    ['id'=>'DPOY',      'type'=>'award','label'=>'DPOY',       'icon'=>'🛡️','full'=>'Melhor Defensor'],
    ['id'=>'FINALS_MVP','type'=>'award','label'=>'Finals MVP', 'icon'=>'🎖️','full'=>'MVP das Finais'],
    ['id'=>'ROY',       'type'=>'award','label'=>'Calouro Ano','icon'=>'🌟','full'=>'Calouro do Ano'],
    ['id'=>'SCORING',   'type'=>'award','label'=>'Artilheiro', 'icon'=>'🎯','full'=>'Artilheiro da Temporada'],
    ['id'=>'SIXTHMAN',  'type'=>'award','label'=>'6º Homem',   'icon'=>'🪑','full'=>'Sexto Homem do Ano'],
    // Eras
    ['id'=>'80s','type'=>'era','label'=>'Anos 80',  'icon'=>'📼','full'=>'Jogou nos anos 80 (1980–1989)'],
    ['id'=>'90s','type'=>'era','label'=>'Anos 90',  'icon'=>'💿','full'=>'Jogou nos anos 90 (1990–1999)'],
    ['id'=>'00s','type'=>'era','label'=>'Anos 2000','icon'=>'📱','full'=>'Jogou nos anos 2000 (2000–2009)'],
    ['id'=>'10s','type'=>'era','label'=>'Anos 2010','icon'=>'🏀','full'=>'Jogou nos anos 2010 (2010–2019)'],
    ['id'=>'20s','type'=>'era','label'=>'Anos 2020','icon'=>'⚡','full'=>'Jogou nos anos 2020 (2020–hoje)'],
];

function playerMatchesCriteria(array $p, array $c): bool {
    if ($c['type'] === 'team')   return in_array($c['id'], $p['t'], true);
    if ($c['type'] === 'nation') return ($p['c'] ?? '') === $c['id'];
    if ($c['type'] === 'award')  return in_array($c['id'], $p['a'], true);
    if ($c['type'] === 'era')    return in_array($c['id'], $p['e'] ?? [], true);
    return false;
}

function getValidPlayers(array $players, array $c1, array $c2): array {
    return array_values(array_filter($players, fn($p) => playerMatchesCriteria($p, $c1) && playerMatchesCriteria($p, $c2)));
}

function generateDailyGrid(array $players, array $criteria): array {
    // Separa por tipo para garantir variedade
    $byType = [];
    foreach ($criteria as $c) $byType[$c['type']][] = $c;

    $fallback = [
        'rows' => [
            $byType['award'][0] ?? $criteria[0],
            $byType['team'][0]  ?? $criteria[1],
            $byType['era'][0]   ?? $criteria[2],
        ],
        'cols' => [
            $byType['team'][1]   ?? $criteria[3],
            $byType['nation'][0] ?? $criteria[4],
            $byType['award'][1]  ?? $criteria[5],
        ]
    ];

    for ($attempt = 0; $attempt < 8000; $attempt++) {
        // Escolhe 6 critérios distintos garantindo diversidade de tipos
        shuffle($criteria);
        $selected = array_slice($criteria, 0, 6);
        $rows = array_slice($selected, 0, 3);
        $cols = array_slice($selected, 3, 3);

        // Evita duas nações na mesma grade (menos jogadores)
        $nationCount = count(array_filter($selected, fn($c) => $c['type'] === 'nation'));
        if ($nationCount > 1) continue;

        // Evita dois MVPs ou dois Scoring, etc. (tipos award repetidos em ambos eixos)
        $rowTypes = array_column($rows, 'type');
        $colTypes = array_column($cols, 'type');
        if (count(array_unique($rowTypes)) < 2) continue;
        if (count(array_unique($colTypes)) < 2) continue;

        // Valida que cada célula tem pelo menos 1 jogador
        $valid = true; $minCount = PHP_INT_MAX;
        foreach ($rows as $r) {
            foreach ($cols as $c) {
                $cnt = count(getValidPlayers($players, $r, $c));
                if ($cnt === 0) { $valid = false; break 2; }
                $minCount = min($minCount, $cnt);
            }
        }
        if ($valid && $minCount >= 1) return ['rows' => $rows, 'cols' => $cols];
    }
    return $fallback;
}

srand((int)floor(time() / 86400) + 42);
$grid = generateDailyGrid($PLAYERS, $CRITERIA);

$validMap = [];
foreach ([0,1,2] as $r) {
    foreach ([0,1,2] as $c) {
        $key = "{$r}_{$c}";
        $valids = getValidPlayers($PLAYERS, $grid['rows'][$r], $grid['cols'][$c]);
        $validMap[$key] = [
            'names'  => array_map(fn($p) => mb_strtolower($p['n']), $valids),
            'count'  => count($valids),
        ];
    }
}
$allPlayerNames = array_column($PLAYERS, 'n');
$playerNbaIds   = ['LeBron James'=>2544,'Stephen Curry'=>201939,'Kevin Durant'=>201142,
    'Giannis Antetokounmpo'=>203507,'Nikola Jokic'=>203999,'Luka Doncic'=>1629029,
    'Joel Embiid'=>203954,'Kawhi Leonard'=>202695,'Kobe Bryant'=>977,
    'Shaquille O\'Neal'=>406,'Dwyane Wade'=>2548,'Dirk Nowitzki'=>1717,
    'Tim Duncan'=>1495,'Tony Parker'=>2225,'Manu Ginobili'=>1938,
    'Kevin Garnett'=>708,'Paul Pierce'=>1718,'Ray Allen'=>951,
    'Allen Iverson'=>947,'Vince Carter'=>1713,'Tracy McGrady'=>1503,
    'Carmelo Anthony'=>2546,'Russell Westbrook'=>201566,'James Harden'=>201935,
    'Derrick Rose'=>201565,'Jimmy Butler'=>202710,'Paul George'=>202331,
    'Kyrie Irving'=>202681,'Damian Lillard'=>203081,'Chris Paul'=>101108,
    'Steve Nash'=>959,'Jason Kidd'=>714,'Pau Gasol'=>1932,
    'Rudy Gobert'=>203497,'Victor Wembanyama'=>1641705,'Draymond Green'=>203110,
    'Klay Thompson'=>202691,'Anthony Davis'=>203076,'Jayson Tatum'=>1628369,
    'Jaylen Brown'=>1627759,'Donovan Mitchell'=>1628378,'Devin Booker'=>1626164,
    'Charles Barkley'=>787,'Scottie Pippen'=>979,'Dennis Rodman'=>1007,
    'Patrick Ewing'=>121,'Michael Jordan'=>893,'Karl Malone'=>252,
    'Clyde Drexler'=>781,'Gary Payton'=>730,'Grant Hill'=>397,
    'Chauncey Billups'=>1012,'Blake Griffin'=>201933,'John Wall'=>202322,
    'Ja Morant'=>1629630,'Anthony Edwards'=>1630162,'LaMelo Ball'=>1630163,
    'Trae Young'=>1629027,'Karl-Anthony Towns'=>1626157,'Zion Williamson'=>1629627,
    'Chris Bosh'=>76001,'Dwight Howard'=>2730,'DeMar DeRozan'=>201942,
    'Shai Gilgeous-Alexander'=>1628983,'Andrew Wiggins'=>203952,'Jamal Murray'=>1627750,
    'Khris Middleton'=>203114,'Jrue Holiday'=>201950,
];

// AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    header('Content-Type: application/json');
    if ($action === 'save') {
        $res   = json_encode(json_decode($_POST['respostas'] ?? '[]', true) ?: []);
        $tries = (int)($_POST['tentativas_restantes'] ?? 9);
        $done  = (int)($_POST['concluido'] ?? 0);
        $quit  = (int)($_POST['desistiu']  ?? 0);
        $pts   = (int)($_POST['pontos']    ?? 0);
        $hoje  = date('Y-m-d');
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT id, respostas, concluido, desistiu FROM hoopgrid_historico WHERE id_usuario=? AND data_jogo=? FOR UPDATE");
            $stmt->execute([$user_id, $hoje]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $already_done = $row && ((int)($row['concluido']??0)===1 || (int)($row['desistiu']??0)===1);
            if (!$already_done) {
                $prev = $row ? (json_decode($row['respostas']??'[]', true) ?: []) : [];
                $prevKeys = array_flip(array_column($prev, 'key'));
                $curr = json_decode($res, true) ?: [];
                $newCells = 0;
                foreach ($curr as $r) { if (!isset($prevKeys[$r['key'] ?? ''])) $newCells++; }
                if ($newCells > 0) $pdo->prepare("UPDATE usuarios SET pontos = pontos + ? WHERE id = ?")->execute([$newCells * 25, $user_id]);
            }
            if ($row) {
                $pdo->prepare("UPDATE hoopgrid_historico SET respostas=?,tentativas_restantes=?,concluido=?,desistiu=?,pontos_ganhos=? WHERE id=?")
                    ->execute([$res, $tries, $done, $quit, $pts, $row['id']]);
            } else {
                $pdo->prepare("INSERT INTO hoopgrid_historico (id_usuario,data_jogo,respostas,tentativas_restantes,concluido,desistiu,pontos_ganhos) VALUES(?,?,?,?,?,?,?)")
                    ->execute([$user_id, $hoje, $res, $tries, $done, $quit, $pts]);
            }
            $pdo->commit();
            echo json_encode(['ok'=>true]);
        } catch (PDOException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['ok'=>false,'err'=>$e->getMessage()]); }
        exit;
    }
    echo json_encode(['ok'=>false]); exit;
}

$hoje = date('Y-m-d');
$dadosHoje = null;
try { $stmt = $pdo->prepare("SELECT * FROM hoopgrid_historico WHERE id_usuario=? AND data_jogo=?"); $stmt->execute([$user_id, $hoje]); $dadosHoje = $stmt->fetch(PDO::FETCH_ASSOC); } catch (PDOException $e) {}
$respostasIniciais  = $dadosHoje ? (json_decode($dadosHoje['respostas'],true) ?: []) : [];
$tentativasIniciais = $dadosHoje ? (int)$dadosHoje['tentativas_restantes'] : 9;
$jaTerminou         = $dadosHoje && ($dadosHoje['concluido'] || $dadosHoje['desistiu']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Hoop Grid</title>
	<link rel="icon" type="image/png" href="/img/fbagames.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#07070a;--panel:#101013;--panel2:#16161a;--panel3:#1c1c21;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.14);
  --red:#fc0025;--red-s:rgba(252,0,37,.12);--red-g:rgba(252,0,37,.25);
  --text:#f0f0f3;--t2:#868690;--t3:#3c3c44;
  --green:#22c55e;--gs:rgba(34,197,94,.12);
  --amber:#f59e0b;--blue:#3b82f6;
  --r:14px;--font:'Poppins',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;overflow-x:hidden}

/* TOP BAR */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50;gap:10px}
.topbar-l{display:flex;align-items:center;gap:10px}
.back{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;border:1px solid var(--border);background:transparent;color:var(--t2);text-decoration:none;font-size:14px;transition:.2s;flex-shrink:0}
.back:hover{border-color:var(--red);color:var(--red);background:var(--red-s)}
.title{font-size:15px;font-weight:800}
.title span{color:var(--red)}
.badge-daily{display:inline-flex;align-items:center;gap:4px;font-size:8px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;padding:2px 8px;border-radius:999px;background:var(--red-s);border:1px solid var(--red-g);color:var(--red);margin-left:6px}
.topbar-r{display:flex;align-items:center;gap:6px}
.chip{display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:var(--panel2);border:1px solid var(--border);font-size:11px;font-weight:700;white-space:nowrap}
.chip.warn{border-color:var(--amber)!important;color:var(--amber)!important}
.chip.danger{border-color:var(--red)!important;color:var(--red)!important}

/* MAIN */
.main{max-width:560px;margin:0 auto;padding:14px 10px 60px}

/* GRID */
.grid-wrap{display:grid;grid-template-columns:76px repeat(3,1fr);grid-template-rows:76px repeat(3,1fr);gap:4px;margin-bottom:14px}
.corner{background:transparent}
.hcol,.hrow{background:var(--panel2);border:1px solid var(--border2);border-radius:10px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;padding:6px 4px;text-align:center;position:relative;cursor:default}
.hcol{border-radius:12px 12px 6px 6px}
.hrow{border-radius:6px 12px 12px 6px}
.h-logo{width:36px;height:36px;object-fit:contain;display:block}
.h-icon{font-size:1.4rem;line-height:1}
.h-label{font-size:9px;font-weight:700;color:var(--t2);letter-spacing:.3px;line-height:1.2;word-break:break-word}
.h-type{font-size:7px;font-weight:600;letter-spacing:.8px;text-transform:uppercase;padding:1px 5px;border-radius:999px;margin-top:2px}
.t-team{background:rgba(59,130,246,.15);color:#60a5fa}
.t-nation{background:rgba(34,197,94,.15);color:#4ade80}
.t-award{background:rgba(252,0,37,.15);color:#f87171}
.t-era{background:rgba(245,158,11,.15);color:#fbbf24}

/* CELLS */
.cell{background:var(--panel);border:1px solid var(--border);border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;position:relative;overflow:hidden;min-height:70px}
.cell:hover:not(.done):not(.locked){border-color:var(--border2);background:var(--panel2);transform:scale(1.02)}
.cell.active{border-color:var(--red)!important;background:var(--red-s)!important;box-shadow:0 0 0 2px var(--red-g)}
.cell.done{cursor:default}
.cell.correct{border-color:rgba(34,197,94,.4)!important;background:var(--gs)!important}
.cell.wrong{animation:wrongFlash .4s ease}
.cell.locked{cursor:default;opacity:.5}
@keyframes wrongFlash{0%,100%{background:var(--panel)}50%{background:rgba(252,0,37,.18);border-color:var(--red)}}
.cell-plus{font-size:20px;color:var(--t3);font-weight:300;line-height:1}
.cell-count{position:absolute;bottom:4px;right:5px;font-size:7px;color:var(--t3);font-weight:700}
.cell-player{padding:5px 4px;text-align:center;width:100%}
.cell-name{font-size:9px;font-weight:700;color:var(--text);line-height:1.3}
.cell-headshot{width:46px;height:34px;object-fit:contain;display:block;margin:0 auto 2px;border-radius:5px}
.cell-icon{font-size:1.3rem;display:block;margin-bottom:2px}

/* SEARCH OVERLAY */
.s-overlay{position:fixed;inset:0;background:rgba(0,0,0,.8);backdrop-filter:blur(8px);z-index:200;display:flex;align-items:flex-start;justify-content:center;padding:36px 14px 20px}
.s-overlay.hidden{display:none}
.s-box{background:var(--panel);border:1px solid var(--border2);border-radius:18px;padding:18px;max-width:440px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.7)}
.s-ctx{display:flex;align-items:center;gap:10px;margin-bottom:12px;padding:10px 12px;background:var(--panel2);border:1px solid var(--border);border-radius:10px;flex-wrap:wrap}
.ctx-b{display:flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;border:1px solid var(--border2);background:var(--panel3)}
.ctx-count{font-size:11px;color:var(--t2);margin-left:auto;flex-shrink:0}
.s-input-w{position:relative}
.s-input{width:100%;padding:11px 14px 11px 38px;background:var(--panel2);border:1px solid var(--border2);border-radius:10px;color:var(--text);font-family:var(--font);font-size:14px;font-weight:600;outline:none;transition:border-color .2s}
.s-input:focus{border-color:var(--red)}
.s-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--t3);font-size:14px;pointer-events:none}
.suggestions{margin-top:6px;max-height:210px;overflow-y:auto;border-radius:10px;border:1px solid var(--border)}
.sug-item{padding:9px 14px;font-size:13px;font-weight:600;cursor:pointer;transition:background .15s;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
.sug-item:last-child{border-bottom:none}
.sug-item:hover,.sug-item.focused{background:var(--panel2);color:var(--red)}
.sug-item mark{background:transparent;color:var(--red);font-weight:800}
.no-sug{padding:12px 14px;font-size:12px;color:var(--t3);text-align:center}
.s-cancel{margin-top:10px;width:100%;padding:10px;border-radius:10px;border:1px solid var(--border);background:transparent;color:var(--t2);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;transition:all .2s}
.s-cancel:hover{border-color:var(--red);color:var(--red);background:var(--red-s)}

/* RESULT */
.r-overlay{position:fixed;inset:0;background:rgba(0,0,0,.85);backdrop-filter:blur(8px);z-index:300;display:flex;align-items:center;justify-content:center;padding:20px}
.r-overlay.hidden{display:none}
.r-card{background:var(--panel);border:1px solid var(--border2);border-radius:22px;padding:28px 22px;max-width:400px;width:100%;text-align:center;box-shadow:0 24px 80px rgba(0,0,0,.7)}
.r-icon{font-size:3.2rem;margin-bottom:8px;display:block}
.r-title{font-size:20px;font-weight:800;margin-bottom:5px}
.r-sub{font-size:13px;color:var(--t2);margin-bottom:16px;line-height:1.5}
.r-stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:16px}
.r-stat{background:var(--panel2);border:1px solid var(--border);border-radius:10px;padding:10px 6px}
.r-stat-v{font-size:20px;font-weight:800}
.r-stat-l{font-size:9px;color:var(--t2);font-weight:600;letter-spacing:.4px;text-transform:uppercase;margin-top:2px}
.r-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;margin-bottom:16px}
.r-cell{background:var(--panel2);border:1px solid var(--border);border-radius:8px;padding:7px 4px;font-size:9px;text-align:center;line-height:1.3}
.r-cell.ok{border-color:rgba(34,197,94,.4);background:var(--gs)}
.r-cell.miss{opacity:.35}
.btn-red{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 22px;border-radius:12px;background:var(--red);color:#fff;border:none;font-family:var(--font);font-size:14px;font-weight:700;cursor:pointer;width:100%;transition:opacity .2s}
.btn-red:hover{opacity:.85}

/* TOOLTIP */
.tt{position:relative;display:inline-flex;width:100%;height:100%;align-items:center;justify-content:center}
.tt-body{visibility:hidden;opacity:0;position:absolute;bottom:calc(100% + 5px);left:50%;transform:translateX(-50%);background:var(--panel3);border:1px solid var(--border2);border-radius:8px;padding:5px 10px;font-size:10px;font-weight:600;white-space:nowrap;color:var(--text);z-index:100;pointer-events:none;transition:opacity .15s;max-width:160px;white-space:normal;text-align:center}
.tt:hover .tt-body{visibility:visible;opacity:1}

/* TOAST */
.toast-fba{position:fixed;bottom:70px;left:50%;transform:translateX(-50%);background:var(--panel3);border:1px solid rgba(245,158,11,.4);border-radius:10px;padding:8px 16px;font-size:12px;font-weight:700;color:var(--amber);z-index:500;white-space:nowrap;pointer-events:none;animation:tIn .25s ease}
.toast-fba.out{animation:tOut .25s ease forwards}
@keyframes tIn{from{opacity:0;transform:translateX(-50%) translateY(8px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
@keyframes tOut{to{opacity:0;transform:translateX(-50%) translateY(6px)}}

::-webkit-scrollbar{width:4px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border2);border-radius:2px}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-l">
    <a href="../games.php" class="back"><i class="bi bi-arrow-left"></i></a>
    <span class="title">Hoop <span>Grid</span><span class="badge-daily"><i class="bi bi-calendar3"></i> Diário</span></span>
  </div>
  <div class="topbar-r">
    <div class="chip" id="triesChip"><i class="bi bi-crosshair"></i><span id="triesLabel">9</span></div>
    <div class="chip" style="color:var(--amber)"><img src="../moeda.png" style="width:15px;height:15px;object-fit:contain;vertical-align:middle;margin-right:2px"><span id="scoreLabel">0</span></div>
  </div>
</div>

<!-- SEARCH -->
<div class="s-overlay hidden" id="sOverlay">
  <div class="s-box">
    <div class="s-ctx">
      <div id="ctxBadges" style="display:flex;gap:6px;flex-wrap:wrap;flex:1"></div>
      <span class="ctx-count" id="ctxCount"></span>
    </div>
    <div class="s-input-w">
      <i class="bi bi-search s-icon"></i>
      <input type="text" class="s-input" id="sInput" placeholder="Digite o nome do jogador…" autocomplete="off">
    </div>
    <div class="suggestions" id="suggestions"></div>
    <button class="s-cancel" id="sCancel">Cancelar</button>
  </div>
</div>

<!-- RESULT -->
<div class="r-overlay hidden" id="rOverlay">
  <div class="r-card">
    <span class="r-icon" id="rIcon">🏆</span>
    <div class="r-title" id="rTitle">Parabéns!</div>
    <div class="r-sub"   id="rSub"></div>
    <div class="r-stats">
      <div class="r-stat"><div class="r-stat-v" id="rCells">0/9</div><div class="r-stat-l">Acertos</div></div>
      <div class="r-stat"><div class="r-stat-v" id="rTries">0</div><div class="r-stat-l">Tentativas</div></div>
      <div class="r-stat"><div class="r-stat-v" id="rPts">0</div><div class="r-stat-l">Moedas</div></div>
    </div>
    <div class="r-grid" id="rGrid"></div>
    <button class="btn-red" onclick="document.getElementById('rOverlay').classList.add('hidden')">
      <i class="bi bi-eye"></i> Ver grade
    </button>
  </div>
</div>

<div class="main">
  <div class="grid-wrap" id="gameGrid">
    <div class="corner"></div>

    <?php foreach ([0,1,2] as $ci):
      $col = $grid['cols'][$ci];
      $tc  = 't-'.$col['type'];
      $tl  = ['team'=>'Time','nation'=>'País','award'=>'Prêmio','era'=>'Era'][$col['type']] ?? '';
    ?>
    <div class="hcol">
      <div class="tt">
        <span class="tt-body"><?= htmlspecialchars($col['full']) ?></span>
        <?php if ($col['type'] === 'team' && !empty($col['nba_id'])): ?>
          <img src="https://cdn.nba.com/logos/nba/<?= $col['nba_id'] ?>/global/L/logo.svg" class="h-logo" alt="" onerror="this.style.display='none'">
        <?php else: ?>
          <span class="h-icon"><?= $col['icon'] ?></span>
        <?php endif; ?>
      </div>
      <span class="h-label"><?= htmlspecialchars($col['label']) ?></span>
      <span class="h-type <?= $tc ?>"><?= $tl ?></span>
    </div>
    <?php endforeach; ?>

    <?php foreach ([0,1,2] as $ri):
      $row = $grid['rows'][$ri];
      $tr  = 't-'.$row['type'];
      $tl  = ['team'=>'Time','nation'=>'País','award'=>'Prêmio','era'=>'Era'][$row['type']] ?? '';
    ?>
      <div class="hrow">
        <div class="tt" style="flex-direction:column">
          <span class="tt-body"><?= htmlspecialchars($row['full']) ?></span>
          <?php if ($row['type'] === 'team' && !empty($row['nba_id'])): ?>
            <img src="https://cdn.nba.com/logos/nba/<?= $row['nba_id'] ?>/global/L/logo.svg" class="h-logo" alt="" onerror="this.style.display='none'">
          <?php else: ?>
            <span class="h-icon"><?= $row['icon'] ?></span>
          <?php endif; ?>
          <span class="h-label"><?= htmlspecialchars($row['label']) ?></span>
          <span class="h-type <?= $tr ?>"><?= $tl ?></span>
        </div>
      </div>
      <?php foreach ([0,1,2] as $ci):
        $key = "{$ri}_{$ci}";
        $cnt = $validMap[$key]['count'] ?? 0;
      ?>
        <div class="cell" id="cell_<?= $key ?>" onclick="openCell(<?= $ri ?>,<?= $ci ?>)">
          <span class="cell-plus">+</span>
          <?php if ($cnt > 0): ?><span class="cell-count"><?= $cnt ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>

  <div style="text-align:center;font-size:11px;color:var(--t3);padding-bottom:6px">
    Toque em uma célula · <?= count($allPlayerNames) ?> jogadores disponíveis · Nova grade às 00:00
  </div>
</div>

<script>
const VALID_MAP  = <?= json_encode($validMap) ?>;
const ALL_NAMES  = <?= json_encode($allPlayerNames) ?>;
const GRID_ROWS  = <?= json_encode(array_values($grid['rows'])) ?>;
const GRID_COLS  = <?= json_encode(array_values($grid['cols'])) ?>;
const PIDS       = <?= json_encode((object)$playerNbaIds) ?>;
const JA_FIM     = <?= $jaTerminou ? 'true' : 'false' ?>;

let answers  = {'0_0':null,'0_1':null,'0_2':null,'1_0':null,'1_1':null,'1_2':null,'2_0':null,'2_1':null,'2_2':null};
let tries    = <?= (int)$tentativasIniciais ?>;
let finished = JA_FIM;

// Carrega respostas salvas
<?php foreach ($respostasIniciais as $r): ?>
answers[<?= json_encode($r['key']) ?>] = <?= json_encode($r['player']) ?>;
<?php endforeach; ?>

function headshot(name) {
    const pid = PIDS[name];
    return pid
        ? `<img src="https://cdn.nba.com/headshots/nba/latest/260x190/${pid}.png" class="cell-headshot" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='block'"><span class="cell-icon" style="display:none">🏀</span>`
        : `<span class="cell-icon">🏀</span>`;
}

function renderCell(key) {
    const el = document.getElementById('cell_' + key);
    if (!el) return;
    const ans = answers[key];
    if (ans) {
        el.classList.add('done', 'correct');
        el.innerHTML = `<div class="cell-player">${headshot(ans)}<div class="cell-name">${ans}</div></div>`;
    } else if (finished) {
        el.classList.add('locked');
    }
}

function updateUI() {
    for (let r=0;r<3;r++) for (let c=0;c<3;c++) renderCell(`${r}_${c}`);
    const tc = document.getElementById('triesChip');
    document.getElementById('triesLabel').textContent = tries;
    tc.className = 'chip' + (tries <= 2 ? ' danger' : tries <= 4 ? ' warn' : '');
    const correct = Object.values(answers).filter(Boolean).length;
    document.getElementById('scoreLabel').textContent = correct * 25;
}

// ── SEARCH ──
let activeCell = null, focusedIdx = -1;

function openCell(r, c) {
    if (finished) return;
    const key = `${r}_${c}`;
    if (answers[key]) return;
    activeCell = {r, c};
    document.querySelectorAll('.cell').forEach(el => el.classList.remove('active'));
    document.getElementById('cell_' + key).classList.add('active');

    const rowC = GRID_ROWS[r], colC = GRID_COLS[c];
    const typeLabel = t => ({'team':'Time','nation':'País','award':'Prêmio','era':'Era'})[t] || t;
    const cnt = (VALID_MAP[key] || {}).count || 0;
    document.getElementById('ctxBadges').innerHTML =
        `<span class="ctx-b">${rowC.icon} ${rowC.label}</span><span style="font-size:12px;color:var(--t3)">×</span><span class="ctx-b">${colC.icon} ${colC.label}</span>`;
    document.getElementById('ctxCount').textContent = cnt ? `${cnt} possíveis` : '';

    document.getElementById('sInput').value = '';
    document.getElementById('suggestions').innerHTML = '';
    document.getElementById('sOverlay').classList.remove('hidden');
    setTimeout(() => document.getElementById('sInput').focus(), 60);
    focusedIdx = -1;
}

function closeSearch() {
    document.getElementById('sOverlay').classList.add('hidden');
    document.querySelectorAll('.cell').forEach(el => el.classList.remove('active'));
    activeCell = null; focusedIdx = -1;
}

function renderSuggestions(q) {
    const box = document.getElementById('suggestions');
    if (!q) { box.innerHTML = ''; return; }
    const norm = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');
    const qn = norm(q);
    const used = new Set(Object.values(answers).filter(Boolean).map(n => n.toLowerCase()));
    const matches = ALL_NAMES.filter(n => norm(n).includes(qn) && !used.has(n.toLowerCase())).slice(0, 8);
    if (!matches.length) { box.innerHTML = `<div class="no-sug">Nenhum jogador encontrado</div>`; focusedIdx=-1; return; }
    const esc = s => s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
    box.innerHTML = matches.map((n,i) => {
        const hi = n.replace(new RegExp(`(${esc(q)})`, 'gi'), '<mark>$1</mark>');
        const pid = PIDS[n];
        const av = pid
            ? `<img src="https://cdn.nba.com/headshots/nba/latest/260x190/${pid}.png" style="width:22px;height:16px;object-fit:contain;border-radius:3px;flex-shrink:0" onerror="this.style.display='none'">`
            : `<span style="font-size:13px;flex-shrink:0">🏀</span>`;
        return `<div class="sug-item" data-name="${n}" onclick="selectPlayer('${n.replace(/'/,"\\'")}')">${av} ${hi}</div>`;
    }).join('');
    focusedIdx = -1;
}

document.getElementById('sInput').addEventListener('input', e => renderSuggestions(e.target.value.trim()));
document.getElementById('sInput').addEventListener('keydown', e => {
    const items = document.querySelectorAll('.sug-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); focusedIdx = Math.min(focusedIdx+1, items.length-1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); focusedIdx = Math.max(focusedIdx-1, 0); }
    else if (e.key === 'Enter' && focusedIdx >= 0) { e.preventDefault(); selectPlayer(items[focusedIdx].dataset.name); return; }
    else if (e.key === 'Escape') { closeSearch(); return; }
    items.forEach((el,i) => el.classList.toggle('focused', i === focusedIdx));
    if (focusedIdx >= 0) items[focusedIdx].scrollIntoView({block:'nearest'});
});
document.getElementById('sCancel').addEventListener('click', closeSearch);
document.getElementById('sOverlay').addEventListener('click', e => { if (e.target === e.currentTarget) closeSearch(); });

function selectPlayer(name) {
    if (!activeCell || finished) { closeSearch(); return; }
    const {r, c} = activeCell;
    const key = `${r}_${c}`;
    const valid = (VALID_MAP[key] || {}).names || [];
    const isOk  = valid.includes(name.toLowerCase());

    if (isOk) {
        answers[key] = name;
        closeSearch();
        showToast('+25 moedas! 🪙');
        checkFinish();
    } else {
        tries = Math.max(0, tries - 1);
        const el = document.getElementById('cell_' + key);
        el.classList.add('wrong');
        setTimeout(() => el.classList.remove('wrong'), 420);
        closeSearch();
        checkFinish();
    }
    updateUI();
    if (!finished) saveState();
}

function checkFinish() {
    const correct = Object.values(answers).filter(Boolean).length;
    if (correct < 9 && tries > 0) return;
    finished = true;
    setTimeout(() => showResult(correct === 9), 350);
    saveState(true, correct === 9, correct < 9);
}

function showResult(won) {
    const correct = Object.values(answers).filter(Boolean).length;
    const used = 9 - tries;
    const pts = correct * 25;
    document.getElementById('rIcon').textContent  = won ? '🏆' : correct >= 5 ? '🏅' : '😔';
    document.getElementById('rTitle').textContent = won ? 'Grade Completa!' : correct >= 5 ? 'Bom jogo!' : 'Fim de jogo';
    document.getElementById('rSub').textContent   = won
        ? `Grade completa em ${used} tentativa${used===1?'':'s'}!`
        : `Você acertou ${correct} de 9 células.`;
    document.getElementById('rCells').textContent = `${correct}/9`;
    document.getElementById('rTries').textContent = used;
    document.getElementById('rPts').textContent   = pts;
    let html = '';
    for (let r=0;r<3;r++) for (let c=0;c<3;c++) {
        const key = `${r}_${c}`, ans = answers[key];
        html += `<div class="r-cell ${ans?'ok':'miss'}">${ans ? '🏀' : '—'}<br><span style="font-size:7px">${ans || ''}</span></div>`;
    }
    document.getElementById('rGrid').innerHTML = html;
    document.getElementById('rOverlay').classList.remove('hidden');
}

function saveState(forceSave=false, concluido=false, desistiu=false) {
    const correct = Object.values(answers).filter(Boolean).length;
    const pts = correct * 25;
    const respostas = Object.entries(answers).filter(([,v])=>v).map(([k,v])=>({key:k,player:v}));
    const fd = new FormData();
    fd.append('action','save');
    fd.append('respostas', JSON.stringify(respostas));
    fd.append('tentativas_restantes', tries);
    fd.append('concluido', concluido ? 1 : 0);
    fd.append('desistiu', desistiu ? 1 : 0);
    fd.append('pontos', pts);
    fetch('', {method:'POST', body:fd});
}

let _tt = null;
function showToast(msg) {
    let el = document.getElementById('_toast');
    if (el) { clearTimeout(_tt); el.remove(); }
    el = document.createElement('div');
    el.id = '_toast'; el.className = 'toast-fba'; el.textContent = msg;
    document.body.appendChild(el);
    _tt = setTimeout(() => { el.classList.add('out'); setTimeout(()=>el.remove(),250); }, 1800);
}

updateUI();
if (JA_FIM) {
    const c = Object.values(answers).filter(Boolean).length;
    setTimeout(() => showResult(c === 9), 300);
}
setInterval(() => { if (!finished) saveState(); }, 60000);
</script>
</body>
</html>
