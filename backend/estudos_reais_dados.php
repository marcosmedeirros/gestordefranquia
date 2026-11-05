<?php
/**
 * ESTIMATIVAS DA VIDA REAL — peak end e aposentadoria de jogadores da NBA de verdade.
 *
 * Pedido do Marcos (06/11/2026): ter "peak end" (idade em que começou a cair) e "aposentadoria"
 * (idade em que parou de jogar). Onde o jogo ainda não mostra isso, estimar pela carreira real do
 * jogador; quem é jovem demais (Cooper Flagg e cia.) fica EM BRANCO até o jogo da FBA mostrar o que
 * acontece. O objetivo continua sendo o dado do 2K/FBA: estas estimativas ficam em colunas
 * SEPARADAS ("~real") e nunca entram nas médias do jogo.
 *
 * Formato: 'Nome' => [peak_end, aposentadoria]. null = não sei / ainda não aconteceu.
 *   peak_end      idade em que o rendimento real começou a cair
 *   aposentadoria idade na última temporada que jogou na NBA
 * ATENÇÃO: as idades aqui são as da NBA DE VERDADE. Na tela elas passam por estudosParaEscalaDoJogo()
 * (backend/estudos_ajustes.php), que as leva pra escala do 2K/FBA (peak end 30–38, aposentadoria 36+).
 * Só entra quem eu conheço bem; na dúvida, fica de fora. Idades de memória, ±1 ano: são ESTIMATIVAS
 * (a tela marca com "~") e se corrigem no editor da página (a correção vale mais que este arquivo).
 * Jogador ainda em atividade fica com aposentadoria null. Nomes ambíguos (Isaiah Thomas, Mike
 * Dunleavy, Johnny Davis) ficam de fora de propósito: o mesmo nome serve a dois jogadores.
 *
 * Este arquivo só ALIMENTA a tabela estudos_reais (INSERT IGNORE); o que o Marcos corrigir na tela
 * nunca é sobrescrito. Ver backend/estudos_ajustes.php (estudosReaisSincronizar).
 */
return [
    // ── ELITE (ativos) ───────────────────────────────────────────────
    'Al Horford' => [33, null], 'Al-Farouq Aminu' => [29, 31], 'Alex English' => [34, 37], 'Andrei Kirilenko' => [28, 34],
    'Andrew Bynum' => [25, 26], 'Antonio Davis' => [34, 38], 'Arvydas Sabonis' => [32, 38], 'Avery Bradley' => [28, 31],
    'Bill Cartwright' => [32, 37], 'Bill Laimbeer' => [33, 36], 'Bill Russell' => [33, 35], 'Bill Walton' => [25, 35],
    'Billy Cunningham' => [30, 32], 'Billy Knight' => [29, 32], 'Bison Dele' => [29, 30], 'Blake Griffin' => [28, 34],
    'Bob Cousy' => [32, 34], 'Bob Dandridge' => [32, 34], 'Bob Lanier' => [31, 35], 'Bob Love' => [33, 34],
    'Bob Pettit' => [31, 32], 'Bobby Jones' => [32, 34], 'Brad Daugherty' => [27, 27], 'Brandon Roy' => [26, 28],
    'C.J. McCollum' => [31, null], 'Calvin Murphy' => [31, 34], 'Carmelo Anthony' => [31, 37], 'Cedric Ceballos' => [28, 32],
    'Charlie Scott' => [29, 31], 'Chris Bosh' => [29, 31], 'Chris Paul' => [33, null], 'Chris Webber' => [31, 35],
    'Clyde Drexler' => [33, 35], 'Cliff T. Robinson' => [33, 35], 'Dale Davis' => [33, 37], 'Dan Issel' => [34, 36],
    'Danny Manning' => [31, 36], 'Darrell Griffith' => [28, 33], 'Dave Cowens' => [30, 34], 'Dave Debusschere' => [32, 33],
    'David Robinson' => [33, 37], 'David Thompson' => [25, 29], 'DeAndre Jordan' => [31, null], 'DeMarcus Cousins' => [28, 31],
    'Dee Brown' => [30, 33], 'Dell Curry' => [35, 37], 'Dennis Rodman' => [37, 39], 'Dennis Scott' => [28, 32],
    'Derrick Coleman' => [29, 37], 'Derrick Rose' => [24, 34], 'Dick Van Arsdale' => [31, 33], 'Dikembe Mutombo' => [35, 42],
    'Dwight Howard' => [27, 36], 'Dwyane Wade' => [31, 37], 'Earl Monroe' => [29, 35], 'Elgin Baylor' => [31, 37],
    'Eric Gordon' => [33, null], 'Gail Goodrich' => [34, 36], 'Gary Payton' => [34, 38], 'Geoff Petrie' => [27, 28],
    'George Gervin' => [31, 33], 'Gordon Hayward' => [27, 33], 'Grant Hill' => [28, 40], 'Hakeem Olajuwon' => [34, 39],
    'Jamaal Wilkes' => [30, 32], 'James Harden' => [33, null], 'Jayson Williams' => [29, 31], 'Jeff Hornacek' => [33, 37],
    'Jeremy Lin' => [27, 30], 'Jerry Sloan' => [31, 33], 'Jerry West' => [33, 35], 'Jimmy Butler' => [35, null],
    'Jo Jo White' => [31, 34], 'John Havlicek' => [36, 38], 'John Starks' => [32, 36], 'John Wall' => [27, 32],
    'Johnny Dawkins' => [28, 31], 'Julius Erving' => [34, 36], 'Kareem Abdul-Jabbar' => [37, 41], 'Kemba Walker' => [29, 32],
    'Kenny Anderson' => [29, 35], 'Kevin Garnett' => [33, 39], 'Kevin Love' => [29, null], 'Kiki Vandeweghe' => [30, 34],
    'Kirk Hinrich' => [27, 35], 'Klay Thompson' => [31, null], 'Kurt Rambis' => [31, 36], 'Kyle Lowry' => [35, null],
    'Larry Bird' => [32, 35], 'Larry Johnson' => [27, 31], 'LeBron James' => [36, null], 'Lenny Wilkens' => [34, 37],
    'Luc Longley' => [28, 32], 'Magic Johnson' => [32, 36], 'Mahmoud Abdul-Rauf' => [28, 31], 'Marc Gasol' => [33, 36],
    'Mark Price' => [30, 34], 'Maurice Cheeks' => [32, 36], 'Maurice Lucas' => [31, 35], 'Michael Cooper' => [31, 33],
    'Michael Jordan' => [35, 40], 'Mychal Thompson' => [31, 36], 'Nate Archibald' => [29, 35], 'Nate McMillan' => [30, 33],
    'Nikola Pekovic' => [27, 29], 'Norm Nixon' => [31, 33], 'Oscar Robertson' => [33, 35], 'Paul George' => [32, null],
    'Paul Pierce' => [34, 39], 'Penny Hardaway' => [26, 36], 'Anfernee Hardaway' => [26, 36], 'Pete Maravich' => [30, 32],
    'Reggie Theus' => [30, 34], 'Rick Barry' => [33, 35], 'Rudy Tomjanovich' => [31, 32], 'Russell Westbrook' => [31, null],
    'Scottie Pippen' => [35, 38], 'Serge Ibaka' => [27, 32], "Shaquile O'Neal" => [31, 39], "Shaquille O'Neal" => [31, 39],
    'Sidney Moncrief' => [30, 34], 'Steve Smith' => [31, 36], 'Terrell Brandon' => [29, 32], 'Tim Duncan' => [35, 39],
    'Toni Kukoč' => [32, 37], 'Tracy McGrady' => [27, 34], 'Tyson Chandler' => [32, 37], 'Walt Frazier' => [31, 35],
    'Wes Unseld' => [33, 35], 'Wilt Chamberlain' => [33, 36], 'World B. Free' => [31, 34],

    // ── Outras estrelas que já pararam (aparecem em NEXT, RISE e ROOKIE) ──
    'Kobe Bryant' => [34, 37], 'Karl Malone' => [38, 40], 'John Stockton' => [38, 41], 'Allen Iverson' => [31, 34],
    'Alonzo Mourning' => [30, 38], 'Charles Barkley' => [32, 37], 'Patrick Ewing' => [34, 39], 'Dominique Wilkins' => [35, 39],
    'Moses Malone' => [33, 39], 'Moses malone' => [33, 39], 'Robert Parish' => [37, 43], 'Kevin McHale' => [32, 35],
    'James Worthy' => [31, 33], 'Isiah Thomas' => [30, 33], 'Joe Dumars' => [33, 36], 'Reggie Miller' => [36, 39],
    'Ray Allen' => [35, 38], 'Vince Carter' => [32, 43], 'Steve Nash' => [37, 40], 'Dirk Nowitzki' => [35, 40],
    'Tony Parker' => [33, 36], 'Manu Ginobili' => [34, 40], 'Manu Ginóbili' => [34, 40], 'Yao Ming' => [28, 30],
    'Pau Gasol' => [33, 38], 'Ben Wallace' => [32, 37], 'Chauncey Billups' => [33, 37], 'Jason Kidd' => [35, 40],
    'Mitch Richmond' => [32, 36], 'Chris Mullin' => [31, 37], 'Dennis Johnson' => [32, 35], 'Adrian Dantley' => [31, 35],
    'Bernard King' => [28, 36], 'Rolando Blackman' => [31, 35], 'Bill Bradley' => [31, 33], 'Jerry Lucas' => [31, 34],
    'Kevin Johnson' => [29, 33], 'Peja Stojakovic' => [28, 33], 'Peja Stojaković' => [28, 33], 'Allan Houston' => [31, 33],
    'Rasheed Wallace' => [33, 38], 'Elton Brand' => [30, 36], 'Antawn Jamison' => [33, 37], "Amar'e Stoudemire" => [29, 33],
    'Ama’re Stoudemire' => [29, 33], 'Shawn Kemp' => [28, 33], 'Rik Smits' => [31, 33], 'Vlade Divac' => [34, 37],
    'Detlef Schrempf' => [34, 38], 'Ralph Sampson' => [25, 31], 'Gilbert Arenas' => [26, 30], 'Baron Davis' => [30, 33],
    'Stephon Marbury' => [29, 32], 'Steve Francis' => [26, 31], "Jermaine O'Neal" => [27, 35], 'Zydrunas Ilgauskas' => [32, 35],
    'Michael Finley' => [32, 37], 'Michael Redd' => [29, 33], 'Richard Hamilton' => [31, 35], 'David West' => [32, 37],
    'Danny Ainge' => [32, 36], 'Byron Scott' => [31, 36], 'A.C. Green' => [33, 38], 'Charles Oakley' => [34, 40],
];
