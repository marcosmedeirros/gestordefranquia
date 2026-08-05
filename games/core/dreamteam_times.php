<?php
/**
 * Starting5x5 — times titulares históricos, curados à mão.
 *
 * Base própria do jogo, sem depender de hoopgrid_players/build_notas: cada
 * "time" aqui é a escalação titular real de uma temporada específica (não o
 * elenco inteiro), com posição(ões) e OVR por jogador. Vários jogadores têm
 * mais de uma posição elegível de propósito — é o que dá profundidade pra
 * escolha na hora de montar o próprio time (ver dreamteam.php).
 *
 * Escala de OVR livre (não é a mesma do Build-A-Player) — vai de ~73 (papel
 * coadjuvante real) até 99 (o pico de carreira de quem definiu a época).
 */
function dtTimesHistoricos(): array
{
    return [
        ['id' => 'bulls96', 'nome' => 'Bulls 1995-96', 'ano' => 1996, 'jogadores' => [
            ['nome' => 'Jordan',       'pos' => ['SG', 'SF'], 'ovr' => 99],
            ['nome' => 'Harper',       'pos' => ['PG', 'SG'], 'ovr' => 82],
            ['nome' => 'Pippen',       'pos' => ['SF', 'PF'], 'ovr' => 95],
            ['nome' => 'Rodman',       'pos' => ['PF', 'C'],  'ovr' => 88],
            ['nome' => 'Longley',      'pos' => ['C'],        'ovr' => 76],
        ]],
        ['id' => 'bulls97', 'nome' => 'Bulls 1996-97', 'ano' => 1997, 'jogadores' => [
            ['nome' => 'Jordan',       'pos' => ['SG', 'SF'], 'ovr' => 98],
            ['nome' => 'Harper',       'pos' => ['PG', 'SG'], 'ovr' => 81],
            ['nome' => 'Pippen',       'pos' => ['SF', 'PF'], 'ovr' => 94],
            ['nome' => 'Rodman',       'pos' => ['PF', 'C'],  'ovr' => 87],
            ['nome' => 'Longley',      'pos' => ['C'],        'ovr' => 76],
        ]],
        ['id' => 'lakers87', 'nome' => 'Lakers 1986-87', 'ano' => 1987, 'jogadores' => [
            ['nome' => 'Magic',        'pos' => ['PG'],       'ovr' => 97],
            ['nome' => 'Byron Scott',  'pos' => ['SG'],       'ovr' => 85],
            ['nome' => 'Worthy',       'pos' => ['SF', 'PF'], 'ovr' => 91],
            ['nome' => 'A.C. Green',   'pos' => ['PF'],       'ovr' => 80],
            ['nome' => 'Kareem',       'pos' => ['C'],        'ovr' => 91],
        ]],
        ['id' => 'celtics86', 'nome' => 'Celtics 1985-86', 'ano' => 1986, 'jogadores' => [
            ['nome' => 'D. Johnson',   'pos' => ['PG', 'SG'], 'ovr' => 88],
            ['nome' => 'Ainge',        'pos' => ['SG', 'PG'], 'ovr' => 82],
            ['nome' => 'Bird',         'pos' => ['SF', 'PF'], 'ovr' => 98],
            ['nome' => 'McHale',       'pos' => ['PF', 'C'],  'ovr' => 94],
            ['nome' => 'Parish',       'pos' => ['C'],        'ovr' => 89],
        ]],
        ['id' => 'warriors17', 'nome' => 'Warriors 2016-17', 'ano' => 2017, 'jogadores' => [
            ['nome' => 'Curry',        'pos' => ['PG'],       'ovr' => 97],
            ['nome' => 'Klay',         'pos' => ['SG'],       'ovr' => 90],
            ['nome' => 'Durant',       'pos' => ['SF', 'PF'], 'ovr' => 98],
            ['nome' => 'Draymond',     'pos' => ['PF', 'C'],  'ovr' => 89],
            ['nome' => 'Pachulia',     'pos' => ['C'],        'ovr' => 74],
        ]],
        ['id' => 'warriors18', 'nome' => 'Warriors 2017-18', 'ano' => 2018, 'jogadores' => [
            ['nome' => 'Curry',        'pos' => ['PG'],       'ovr' => 97],
            ['nome' => 'Klay',         'pos' => ['SG'],       'ovr' => 89],
            ['nome' => 'Durant',       'pos' => ['SF', 'PF'], 'ovr' => 98],
            ['nome' => 'Draymond',     'pos' => ['PF', 'C'],  'ovr' => 88],
            ['nome' => 'Pachulia',     'pos' => ['C'],        'ovr' => 73],
        ]],
        ['id' => 'lakers72', 'nome' => 'Lakers 1971-72', 'ano' => 1972, 'jogadores' => [
            ['nome' => 'West',          'pos' => ['PG', 'SG'], 'ovr' => 96],
            ['nome' => 'Goodrich',      'pos' => ['SG', 'PG'], 'ovr' => 87],
            ['nome' => 'McMillian',     'pos' => ['SF'],       'ovr' => 82],
            ['nome' => 'Hairston',      'pos' => ['PF'],       'ovr' => 79],
            ['nome' => 'Chamberlain',   'pos' => ['C'],        'ovr' => 95],
        ]],
        ['id' => 'sixers83', 'nome' => '76ers 1982-83', 'ano' => 1983, 'jogadores' => [
            ['nome' => 'Cheeks',       'pos' => ['PG'],       'ovr' => 85],
            ['nome' => 'Toney',        'pos' => ['SG'],       'ovr' => 87],
            ['nome' => 'Erving',       'pos' => ['SF', 'PF'], 'ovr' => 94],
            ['nome' => 'B. Jones',     'pos' => ['PF', 'SF'], 'ovr' => 84],
            ['nome' => 'Malone',       'pos' => ['C'],        'ovr' => 93],
        ]],
        ['id' => 'lakers01', 'nome' => 'Lakers 2000-01', 'ano' => 2001, 'jogadores' => [
            ['nome' => 'Kobe',         'pos' => ['SG', 'SF'], 'ovr' => 93],
            ['nome' => 'Fisher',       'pos' => ['PG'],       'ovr' => 78],
            ['nome' => 'Fox',          'pos' => ['SF'],       'ovr' => 79],
            ['nome' => 'Horry',        'pos' => ['PF', 'SF'], 'ovr' => 80],
            ['nome' => 'Shaq',         'pos' => ['C'],        'ovr' => 99],
        ]],
        ['id' => 'bulls92', 'nome' => 'Bulls 1991-92', 'ano' => 1992, 'jogadores' => [
            ['nome' => 'Jordan',       'pos' => ['SG', 'SF'], 'ovr' => 98],
            ['nome' => 'Paxson',       'pos' => ['PG', 'SG'], 'ovr' => 77],
            ['nome' => 'Pippen',       'pos' => ['SF', 'PF'], 'ovr' => 92],
            ['nome' => 'Grant',        'pos' => ['PF'],       'ovr' => 85],
            ['nome' => 'Cartwright',   'pos' => ['C'],        'ovr' => 78],
        ]],
        ['id' => 'pistons89', 'nome' => 'Pistons 1988-89', 'ano' => 1989, 'jogadores' => [
            ['nome' => 'Isiah',        'pos' => ['PG'],       'ovr' => 91],
            ['nome' => 'Dumars',       'pos' => ['SG', 'PG'], 'ovr' => 86],
            ['nome' => 'Aguirre',      'pos' => ['SF', 'PF'], 'ovr' => 84],
            ['nome' => 'Rodman',       'pos' => ['PF'],       'ovr' => 82],
            ['nome' => 'Laimbeer',     'pos' => ['C', 'PF'],  'ovr' => 85],
        ]],
        ['id' => 'pistons90', 'nome' => 'Pistons 1989-90', 'ano' => 1990, 'jogadores' => [
            ['nome' => 'Isiah',        'pos' => ['PG'],       'ovr' => 91],
            ['nome' => 'Dumars',       'pos' => ['SG', 'PG'], 'ovr' => 87],
            ['nome' => 'Aguirre',      'pos' => ['SF', 'PF'], 'ovr' => 82],
            ['nome' => 'Rodman',       'pos' => ['PF'],       'ovr' => 84],
            ['nome' => 'Laimbeer',     'pos' => ['C', 'PF'],  'ovr' => 84],
        ]],
        ['id' => 'knicks70', 'nome' => 'Knicks 1969-70', 'ano' => 1970, 'jogadores' => [
            ['nome' => 'Frazier',       'pos' => ['PG', 'SG'], 'ovr' => 91],
            ['nome' => 'Barnett',       'pos' => ['SG'],       'ovr' => 82],
            ['nome' => 'Bradley',       'pos' => ['SF'],       'ovr' => 82],
            ['nome' => 'DeBusschere',   'pos' => ['PF'],       'ovr' => 88],
            ['nome' => 'Reed',          'pos' => ['C', 'PF'],  'ovr' => 90],
        ]],
        ['id' => 'knicks73', 'nome' => 'Knicks 1972-73', 'ano' => 1973, 'jogadores' => [
            ['nome' => 'Frazier',       'pos' => ['PG', 'SG'], 'ovr' => 93],
            ['nome' => 'Monroe',        'pos' => ['SG', 'PG'], 'ovr' => 88],
            ['nome' => 'Bradley',       'pos' => ['SF'],       'ovr' => 83],
            ['nome' => 'DeBusschere',   'pos' => ['PF'],       'ovr' => 87],
            ['nome' => 'Reed',          'pos' => ['C', 'PF'],  'ovr' => 88],
        ]],
        ['id' => 'sixers67', 'nome' => '76ers 1966-67', 'ano' => 1967, 'jogadores' => [
            ['nome' => 'Greer',         'pos' => ['SG', 'PG'], 'ovr' => 88],
            ['nome' => 'Jones',         'pos' => ['SG'],       'ovr' => 78],
            ['nome' => 'Walker',        'pos' => ['SF', 'PF'], 'ovr' => 85],
            ['nome' => 'Jackson',       'pos' => ['PF', 'C'],  'ovr' => 79],
            ['nome' => 'Chamberlain',   'pos' => ['C'],        'ovr' => 98],
        ]],
        ['id' => 'celtics65', 'nome' => 'Celtics 1964-65', 'ano' => 1965, 'jogadores' => [
            ['nome' => 'K.C. Jones',    'pos' => ['PG'],       'ovr' => 80],
            ['nome' => 'Sam Jones',     'pos' => ['SG'],       'ovr' => 87],
            ['nome' => 'Havlicek',      'pos' => ['SF', 'SG'], 'ovr' => 89],
            ['nome' => 'Heinsohn',      'pos' => ['PF', 'SF'], 'ovr' => 83],
            ['nome' => 'Russell',       'pos' => ['C'],        'ovr' => 96],
        ]],
        ['id' => 'heat13', 'nome' => 'Heat 2012-13', 'ano' => 2013, 'jogadores' => [
            ['nome' => 'Chalmers',     'pos' => ['PG'],       'ovr' => 78],
            ['nome' => 'Wade',         'pos' => ['SG', 'PG'], 'ovr' => 91],
            ['nome' => 'LeBron',       'pos' => ['SF', 'PF'], 'ovr' => 98],
            ['nome' => 'Battier',      'pos' => ['SF', 'PF'], 'ovr' => 78],
            ['nome' => 'Bosh',         'pos' => ['PF', 'C'],  'ovr' => 86],
        ]],
        ['id' => 'spurs14', 'nome' => 'Spurs 2013-14', 'ano' => 2014, 'jogadores' => [
            ['nome' => 'Parker',       'pos' => ['PG'],       'ovr' => 89],
            ['nome' => 'Green',        'pos' => ['SG'],       'ovr' => 80],
            ['nome' => 'Kawhi',        'pos' => ['SF'],       'ovr' => 89],
            ['nome' => 'Diaw',         'pos' => ['PF', 'SF'], 'ovr' => 79],
            ['nome' => 'Duncan',       'pos' => ['C', 'PF'],  'ovr' => 90],
        ]],
        ['id' => 'lakers09', 'nome' => 'Lakers 2008-09', 'ano' => 2009, 'jogadores' => [
            ['nome' => 'Fisher',       'pos' => ['PG'],       'ovr' => 78],
            ['nome' => 'Kobe',         'pos' => ['SG', 'SF'], 'ovr' => 96],
            ['nome' => 'Ariza',        'pos' => ['SF', 'SG'], 'ovr' => 78],
            ['nome' => 'Gasol',        'pos' => ['PF', 'C'],  'ovr' => 90],
            ['nome' => 'Bynum',        'pos' => ['C'],        'ovr' => 82],
        ]],
        ['id' => 'lakers10', 'nome' => 'Lakers 2009-10', 'ano' => 2010, 'jogadores' => [
            ['nome' => 'Fisher',       'pos' => ['PG'],       'ovr' => 77],
            ['nome' => 'Kobe',         'pos' => ['SG', 'SF'], 'ovr' => 96],
            ['nome' => 'Artest',       'pos' => ['SF', 'SG'], 'ovr' => 80],
            ['nome' => 'Gasol',        'pos' => ['PF', 'C'],  'ovr' => 91],
            ['nome' => 'Odom',         'pos' => ['PF', 'C'],  'ovr' => 84],
        ]],
        ['id' => 'lakers80', 'nome' => 'Lakers 1979-80', 'ano' => 1980, 'jogadores' => [
            ['nome' => 'Magic',        'pos' => ['PG'],       'ovr' => 90],
            ['nome' => 'Nixon',        'pos' => ['PG', 'SG'], 'ovr' => 82],
            ['nome' => 'Wilkes',       'pos' => ['SF', 'PF'], 'ovr' => 84],
            ['nome' => 'Chones',       'pos' => ['PF', 'C'],  'ovr' => 78],
            ['nome' => 'Kareem',       'pos' => ['C'],        'ovr' => 96],
        ]],
        ['id' => 'celtics84', 'nome' => 'Celtics 1983-84', 'ano' => 1984, 'jogadores' => [
            ['nome' => 'D. Johnson',   'pos' => ['PG', 'SG'], 'ovr' => 89],
            ['nome' => 'Ainge',        'pos' => ['SG', 'PG'], 'ovr' => 82],
            ['nome' => 'Bird',         'pos' => ['SF', 'PF'], 'ovr' => 97],
            ['nome' => 'Maxwell',      'pos' => ['PF', 'SF'], 'ovr' => 82],
            ['nome' => 'Parish',       'pos' => ['C'],        'ovr' => 90],
        ]],
        ['id' => 'warriors19', 'nome' => 'Warriors 2018-19', 'ano' => 2019, 'jogadores' => [
            ['nome' => 'Curry',        'pos' => ['PG'],       'ovr' => 97],
            ['nome' => 'Klay',         'pos' => ['SG'],       'ovr' => 88],
            ['nome' => 'Durant',       'pos' => ['SF', 'PF'], 'ovr' => 97],
            ['nome' => 'Draymond',     'pos' => ['PF', 'C'],  'ovr' => 87],
            ['nome' => 'Cousins',      'pos' => ['C'],        'ovr' => 82],
        ]],
        ['id' => 'warriors76', 'nome' => 'Warriors 1975-76', 'ano' => 1976, 'jogadores' => [
            ['nome' => 'Beard',        'pos' => ['PG', 'SG'], 'ovr' => 79],
            ['nome' => 'C. Johnson',   'pos' => ['SG'],       'ovr' => 77],
            ['nome' => 'Wilkes',       'pos' => ['SF', 'PF'], 'ovr' => 82],
            ['nome' => 'Barry',        'pos' => ['SF', 'PF'], 'ovr' => 92],
            ['nome' => 'Ray',          'pos' => ['C', 'PF'],  'ovr' => 81],
        ]],
        ['id' => 'bullets78', 'nome' => 'Bullets 1977-78', 'ano' => 1978, 'jogadores' => [
            ['nome' => 'Grevey',       'pos' => ['SG'],       'ovr' => 80],
            ['nome' => 'Henderson',    'pos' => ['PG'],       'ovr' => 77],
            ['nome' => 'Dandridge',    'pos' => ['SF', 'PF'], 'ovr' => 86],
            ['nome' => 'Hayes',        'pos' => ['PF', 'C'],  'ovr' => 88],
            ['nome' => 'Unseld',       'pos' => ['C', 'PF'],  'ovr' => 87],
        ]],
        ['id' => 'rockets94', 'nome' => 'Rockets 1993-94', 'ano' => 1994, 'jogadores' => [
            ['nome' => 'K. Smith',     'pos' => ['PG'],       'ovr' => 79],
            ['nome' => 'Maxwell',      'pos' => ['SG', 'PG'], 'ovr' => 78],
            ['nome' => 'Horry',        'pos' => ['SF', 'PF'], 'ovr' => 81],
            ['nome' => 'Thorpe',       'pos' => ['PF'],       'ovr' => 82],
            ['nome' => 'Olajuwon',     'pos' => ['C'],        'ovr' => 97],
        ]],
        ['id' => 'rockets95', 'nome' => 'Rockets 1994-95', 'ano' => 1995, 'jogadores' => [
            ['nome' => 'K. Smith',     'pos' => ['PG'],       'ovr' => 78],
            ['nome' => 'Drexler',      'pos' => ['SG', 'SF'], 'ovr' => 90],
            ['nome' => 'Horry',        'pos' => ['SF', 'PF'], 'ovr' => 82],
            ['nome' => 'Thorpe',       'pos' => ['PF'],       'ovr' => 81],
            ['nome' => 'Olajuwon',     'pos' => ['C'],        'ovr' => 97],
        ]],
        ['id' => 'pistons04', 'nome' => 'Pistons 2003-04', 'ano' => 2004, 'jogadores' => [
            ['nome' => 'Billups',      'pos' => ['PG'],       'ovr' => 84],
            ['nome' => 'R. Hamilton',  'pos' => ['SG'],       'ovr' => 85],
            ['nome' => 'Prince',       'pos' => ['SF', 'PF'], 'ovr' => 80],
            ['nome' => 'R. Wallace',   'pos' => ['PF', 'C'],  'ovr' => 87],
            ['nome' => 'B. Wallace',   'pos' => ['C', 'PF'],  'ovr' => 87],
        ]],
        ['id' => 'bulls98', 'nome' => 'Bulls 1997-98', 'ano' => 1998, 'jogadores' => [
            ['nome' => 'Jordan',       'pos' => ['SG', 'SF'], 'ovr' => 97],
            ['nome' => 'Harper',       'pos' => ['PG', 'SG'], 'ovr' => 78],
            ['nome' => 'Pippen',       'pos' => ['SF', 'PF'], 'ovr' => 92],
            ['nome' => 'Rodman',       'pos' => ['PF', 'C'],  'ovr' => 84],
            ['nome' => 'Longley',      'pos' => ['C'],        'ovr' => 75],
        ]],
        ['id' => 'cavs16', 'nome' => 'Cavaliers 2015-16', 'ano' => 2016, 'jogadores' => [
            ['nome' => 'Kyrie',        'pos' => ['PG', 'SG'], 'ovr' => 89],
            ['nome' => 'J.R. Smith',   'pos' => ['SG', 'SF'], 'ovr' => 79],
            ['nome' => 'LeBron',       'pos' => ['SF', 'PF'], 'ovr' => 96],
            ['nome' => 'Love',         'pos' => ['PF', 'C'],  'ovr' => 87],
            ['nome' => 'T. Thompson',  'pos' => ['C', 'PF'],  'ovr' => 78],
        ]],
    ];
}

/** As 5 posições de um roster, na ordem em que aparecem na tela. */
function dtPosicoes(): array
{
    return ['PG', 'SG', 'SF', 'PF', 'C'];
}
