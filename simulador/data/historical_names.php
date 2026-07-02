<?php
/**
 * Nomes históricos de franquias por era.
 * Chave: abbr atual  →  array de [start_year, end_year, city, name]
 * Se o jogo estiver num ano dentro do intervalo [start, end], exibe o nome histórico.
 */
return [

    // Oklahoma City Thunder → Seattle SuperSonics (até 2008)
    'OKC' => [
        ['start'=>1946,'end'=>2008,'city'=>'Seattle','name'=>'SuperSonics'],
    ],

    // Brooklyn Nets → New Jersey Nets (até 2011) → New York Nets (1968-1976)
    'BKN' => [
        ['start'=>1968,'end'=>1976,'city'=>'New York',  'name'=>'Nets'],
        ['start'=>1977,'end'=>2011,'city'=>'New Jersey','name'=>'Nets'],
    ],

    // Memphis Grizzlies → Vancouver Grizzlies (até 2001)
    'MEM' => [
        ['start'=>1995,'end'=>2001,'city'=>'Vancouver','name'=>'Grizzlies'],
    ],

    // New Orleans Pelicans → New Orleans Hornets (2002-2012) → Charlotte Hornets relocated
    'NOP' => [
        ['start'=>2002,'end'=>2012,'city'=>'New Orleans','name'=>'Hornets'],
    ],

    // Charlotte Hornets → Charlotte Bobcats (2004-2014)
    'CHA' => [
        ['start'=>2004,'end'=>2014,'city'=>'Charlotte','name'=>'Bobcats'],
    ],

    // Utah Jazz → New Orleans Jazz (até 1979)
    'UTA' => [
        ['start'=>1974,'end'=>1979,'city'=>'New Orleans','name'=>'Jazz'],
    ],

    // Golden State Warriors → San Francisco Warriors (1962-1971) → Philadelphia Warriors (até 1962)
    'GSW' => [
        ['start'=>1946,'end'=>1962,'city'=>'Philadelphia','name'=>'Warriors'],
        ['start'=>1963,'end'=>1971,'city'=>'San Francisco','name'=>'Warriors'],
    ],

    // Los Angeles Clippers → San Diego Clippers (até 1984) → Buffalo Braves (até 1978)
    'LAC' => [
        ['start'=>1970,'end'=>1978,'city'=>'Buffalo',   'name'=>'Braves'],
        ['start'=>1979,'end'=>1984,'city'=>'San Diego', 'name'=>'Clippers'],
    ],

    // Houston Rockets → San Diego Rockets (até 1971)
    'HOU' => [
        ['start'=>1967,'end'=>1971,'city'=>'San Diego','name'=>'Rockets'],
    ],

    // Sacramento Kings → Kansas City Kings (até 1985) → Cincinnati Royals (até 1972) → Rochester Royals (até 1957)
    'SAC' => [
        ['start'=>1946,'end'=>1957,'city'=>'Rochester',    'name'=>'Royals'],
        ['start'=>1958,'end'=>1972,'city'=>'Cincinnati',   'name'=>'Royals'],
        ['start'=>1973,'end'=>1985,'city'=>'Kansas City',  'name'=>'Kings'],
    ],

    // Atlanta Hawks → St. Louis Hawks (até 1968) → Milwaukee Hawks (1951-55) → Tri-Cities Blackhawks (1946-51)
    'ATL' => [
        ['start'=>1946,'end'=>1951,'city'=>'Tri-Cities', 'name'=>'Blackhawks'],
        ['start'=>1952,'end'=>1955,'city'=>'Milwaukee',  'name'=>'Hawks'],
        ['start'=>1956,'end'=>1968,'city'=>'St. Louis',  'name'=>'Hawks'],
        ['start'=>1969,'end'=>1969,'city'=>'Atlanta',    'name'=>'Hawks'], // já é Atlanta
    ],

    // Washington Wizards → Washington Bullets (até 1997) → Capital Bullets (1974-75) → Baltimore Bullets (até 1973)
    'WAS' => [
        ['start'=>1963,'end'=>1973,'city'=>'Baltimore',  'name'=>'Bullets'],
        ['start'=>1974,'end'=>1974,'city'=>'Capital',    'name'=>'Bullets'],
        ['start'=>1975,'end'=>1996,'city'=>'Washington', 'name'=>'Bullets'],
    ],

    // Minnesota Timberwolves (1989 — sempre foi Minnesota; sem mudança)

    // Dallas Mavericks (1980 — sempre foi Dallas; sem mudança)

    // Denver Nuggets → Denver Rockets (ABA, até 1974) — usando mesma abbr
    'DEN' => [
        ['start'=>1967,'end'=>1974,'city'=>'Denver','name'=>'Rockets'],
    ],

    // Indiana Pacers (sempre foi Indiana desde 1967 na ABA; sem mudança relevante)

    // San Antonio Spurs → Dallas Chaparrals (ABA, até 1973) → Texas Chaparrals (1970-71)
    'SAS' => [
        ['start'=>1967,'end'=>1970,'city'=>'Dallas',   'name'=>'Chaparrals'],
        ['start'=>1971,'end'=>1971,'city'=>'Texas',    'name'=>'Chaparrals'],
        ['start'=>1972,'end'=>1973,'city'=>'Dallas',   'name'=>'Chaparrals'],
    ],

    // New Jersey / Brooklyn sempre foram NJN/BKN (já coberto acima)

    // New York Knicks (sempre NYC; sem mudança)
    // Boston Celtics (sempre Boston; sem mudança)
    // Chicago Bulls (sempre Chicago; sem mudança)
    // Cleveland Cavaliers (sempre Cleveland; sem mudança)
    // Detroit Pistons → Fort Wayne Pistons (até 1957)
    'DET' => [
        ['start'=>1941,'end'=>1957,'city'=>'Fort Wayne','name'=>'Pistons'],
    ],

    // Milwaukee Bucks (1968 — sempre Milwaukee; sem mudança)
    // Toronto Raptors (1995 — sempre Toronto; sem mudança)
    // Philadelphia 76ers → Syracuse Nationals (até 1963)
    'PHI' => [
        ['start'=>1946,'end'=>1963,'city'=>'Syracuse','name'=>'Nationals'],
    ],

    // Miami Heat (1988 — sempre Miami; sem mudança)
    // Orlando Magic (1989 — sempre Orlando; sem mudança)

    // Los Angeles Lakers → Minneapolis Lakers (até 1960)
    'LAL' => [
        ['start'=>1947,'end'=>1960,'city'=>'Minneapolis','name'=>'Lakers'],
    ],

    // Phoenix Suns (1968 — sempre Phoenix; sem mudança)
    // Portland Trail Blazers (1970 — sempre Portland; sem mudança)

];
