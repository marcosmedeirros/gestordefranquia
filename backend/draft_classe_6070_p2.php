<?php
/**
 * AS LETRINHAS DOS JOGADORES DOS ANOS 60/70 (PARTE 2), dos prints do jogo.
 *
 * Transcritas em 21/10/2026, a pedido do Marcos. Cada linha é
 * [nome, SCT%, OVR, INS, MID, 3PT, INS D, PER D, PLMK, REB, IQ, PHYS] — na
 * ordem em que o jogo mostra, que é a ordem das colunas do print, e na ordem
 * em que os jogadores aparecem, que é por OVR decrescente.
 *
 * ── NOVE LETRINHAS, E DESTA VEZ COM A PHYS ───────────────────────────
 *
 * O sistema tem dez (IN, MID, 3PT, POST D, PER D, PLAY, REB, ATHL, IQ, POT).
 * Os prints de 1986, 2013 e 2022 cortavam depois de IQ e deixavam ATHL e POT
 * de fora; estes vão até PHYS, que é a ATHL do sistema. Só POT fica sem nota,
 * e a tela do draft escreve "?" na coluna sem nota — o atributo existe,
 * ninguém mediu.
 *
 * @see backend/draft_class_csv.php  DRAFT_CSV_NOTAS, as chaves do sistema
 *
 * ── O OVR VEM EM DOIS FORMATOS, E NÃO É ERRO DE LEITURA ──────────────
 *
 * Quem está com SCT% em 100% aparece com o número (Bill Russell 82, Sidney
 * Moncrief 80, Earl Cureton 70); abaixo disso o jogo mostra só a letra, que
 * é a faixa que o olheiro conseguiu estimar. São 14 jogadores com número e
 * 55 com letra. Por isso o OVR daqui NÃO serve para preencher a coluna `ovr`
 * da tabela: ela é INT, e inventar um número para 54 jogadores seria
 * fabricar o dado que o jogo deliberadamente não mostra. As classes já
 * cadastradas (1979, a Parte 1, a Parte 3) entram todas com ovr 60 e idade
 * 18, que é o padrão de prospecto da liga, e esta segue o mesmo caminho.
 *
 * ── A POSIÇÃO NÃO ESTÁ NOS PRINTS, E VEM DE FORA ─────────────────────
 *
 * As outras classes têm PG/SG/SF/PF/C porque o print delas trazia a coluna
 * POS. Estes prints não trazem: vão de NAME direto para SCT%. A pedido do
 * Marcos (21/10), a posição foi deduzida do jogador REAL da NBA, e por isso
 * mora num mapa à parte — CLASSE_6070_P2_POSICOES, logo abaixo da lista.
 * Separar não é capricho: tudo que está em CLASSE_6070_P2 foi lido do
 * print e pode ser conferido contra ele; o mapa é conhecimento de fora, e
 * em alguns casos é opinião. Billy Cunningham e Mychal Thompson jogaram em
 * duas posições; Dan Issel e Reggie Theus também mudaram ao longo da
 * carreira. Misturar os dois no mesmo array faria a dedução parecer tão
 * conferível quanto a letrinha, e ela não é.
 *
 * TRÊS FICAM SEM POSIÇÃO: Junior Marion, Kelly Tate e Jorge Barbosa não
 * correspondem a nenhum jogador da NBA que eu reconheça — os dois últimos
 * aparecem com scouting de 1% e 6% e nota F em tudo, que é como o jogo
 * mostra quem ninguém olhou. Chutar a posição deles seria inventar.
 *
 * ── COMO A TRANSCRIÇÃO FOI CONFERIDA ─────────────────────────────────
 *
 * São oito prints que se sobrepõem: os últimos nomes de um reaparecem no
 * começo do seguinte. Toda linha repetida foi lida duas vezes, nos dois
 * prints, e as duas leituras bateram — são 18 linhas conferidas em
 * duplicata, de 69 no total. As colunas foram lidas da esquerda para a
 * direita acompanhando o cabeçalho, porque a olho a REB de uma linha vira a
 * IQ da outra.
 */

/** As nove colunas do print, na ordem, com as chaves que o sistema já usa. */
const CLASSE_6070_P2_COLUNAS = ['IN', 'MID', '3PT', 'POST D', 'PER D', 'PLAY', 'REB', 'IQ', 'ATHL'];

/**
 * [nome, sct, ovr, INS, MID, 3PT, INSD, PERD, PLMK, REB, IQ, PHYS]
 *
 * `sct` e `ovr` ficam aqui porque estão no print e são o que explica o
 * formato duplo do OVR; não vão para o banco.
 */
const CLASSE_6070_P2 = [
    ['Bill Russell',            '100%', '82', 'B-', 'D-', 'D-', 'A+', 'C',  'C',  'A+', 'A-', 'A'],
    ['Wes Unseld',              '99%',  'B',  'B-', 'B+', 'B-', 'A-', 'B-', 'B',  'A-', 'C-', 'A'],
    ['Bob Cousy',               '63%',  'B',  'A-', 'A-', 'A+', 'F',  'A',  'A',  'B',  'A',  'B+'],
    ['David Thompson',          '98%',  'B',  'B+', 'A-', 'B-', 'C+', 'A',  'B+', 'A-', 'A-', 'A'],
    ['Walt Frazier',            '65%',  'B',  'A-', 'B',  'C+', 'C-', 'A+', 'A-', 'C-', 'A+', 'A'],
    ['Sidney Moncrief',         '100%', '80', 'C+', 'B-', 'D+', 'C-', 'A+', 'C+', 'D+', 'B+', 'B+'],
    ['Alex English',            '98%',  'B-', 'B',  'B-', 'D+', 'B',  'B+', 'B-', 'C+', 'C',  'A'],
    ['Bob Lanier',              '97%',  'B-', 'B-', 'B',  'D+', 'A-', 'B',  'C',  'B+', 'B+', 'C'],
    ['Dan Issel',               '100%', '79', 'B-', 'B-', 'D+', 'B-', 'B-', 'D+', 'A',  'C+', 'C'],
    ['Pete Maravich',           '94%',  'B-', 'A-', 'B',  'C+', 'F',  'B',  'A',  'D-', 'C+', 'D-'],
    ['Mike Woodson',            '95%',  'B-', 'B',  'B',  'C',  'C',  'B',  'B-', 'D+', 'B',  'C+'],
    ['Dave Debusschere',        '100%', '79', 'B-', 'C+', 'C-', 'A-', 'B-', 'B-', 'A',  'B+', 'B+'],
    ['Earl Monroe',             '60%',  'B-', 'A-', 'B',  'C+', 'F',  'B+', 'B+', 'F',  'B-', 'B-'],
    ['Dave Greenwood',          '94%',  'B-', 'B-', 'C+', 'D-', 'B-', 'D-', 'D+', 'B+', 'D-', 'B'],
    ['Reggie Theus',            '95%',  'B-', 'B+', 'B+', 'C-', 'D+', 'B',  'A-', 'D+', 'B',  'C+'],
    ['World B. Free',           '92%',  'B-', 'A-', 'B',  'B-', 'D-', 'C+', 'B-', 'D+', 'C-', 'A-'],
    ['Billy Cunningham',        '100%', '78', 'B',  'B-', 'C-', 'B-', 'B+', 'B-', 'A',  'B+', 'B+'],
    ['Mickey Johnson',          '96%',  'B-', 'B',  'B',  'C-', 'B+', 'C+', 'C-', 'B+', 'D',  'B'],
    ['Calvin Murphy',           '56%',  'B-', 'B-', 'B+', 'C-', 'D-', 'B',  'A-', 'F',  'B+', 'C+'],
    ['Joe Barry Carroll',       '89%',  'B-', 'A',  'C+', 'F',  'B+', 'C',  'D+', 'C+', 'C+', 'C+'],
    ['Jeff Ruland',             '93%',  'B-', 'B+', 'C',  'D',  'B',  'D',  'C',  'A',  'C-', 'C-'],
    ['Darrell Griffith',        '55%',  'B-', 'B',  'B-', 'B-', 'C-', 'B+', 'B-', 'F',  'B+', 'D+'],
    ['Micheal Ray Richardson',  '87%',  'B-', 'B-', 'C-', 'D+', 'C+', 'A',  'A-', 'C+', 'A-', 'B+'],
    ['Phil Ford',               '56%',  'B-', 'B-', 'A-', 'C-', 'D',  'B+', 'A-', 'C-', 'B+', 'B-'],
    ['Kiki Vandeweghe',         '100%', '77', 'C+', 'B+', 'A+', 'D+', 'B-', 'C+', 'D-', 'C',  'C+'],
    ['Willis Reed',             '97%',  'C+', 'B+', 'B',  'D',  'A',  'C-', 'C-', 'A-', 'C+', 'D-'],
    ['George Johnson',          '100%', '77', 'B-', 'C+', 'D+', 'B+', 'C+', 'D+', 'B-', 'C+', 'D+'],
    ['Gerald Henderson Sr.',    '59%',  'C+', 'C',  'B+', 'B-', 'D',  'B+', 'B+', 'D+', 'B+', 'B'],
    ['Purvis Short',            '93%',  'C+', 'B-', 'A-', 'D+', 'C+', 'A-', 'B-', 'C-', 'B',  'B+'],
    ['Michael Cooper',          '92%',  'C+', 'C+', 'C-', 'B-', 'B',  'A+', 'C+', 'D-', 'B+', 'C+'],
    ['Wally Walker',            '100%', '77', 'C',  'C+', 'B-', 'C',  'B',  'C+', 'C-', 'B',  'C+'],
    ['Robert Reid',             '55%',  'C+', 'C+', 'C-', 'C+', 'C',  'B+', 'B',  'C',  'B+', 'C+'],
    ['T.R. Dunn',               '54%',  'C+', 'C',  'C-', 'D-', 'C+', 'A',  'B-', 'C+', 'A-', 'B'],
    ['Maurice Cheeks',          '54%',  'C+', 'C+', 'B-', 'C-', 'C',  'A',  'B+', 'F',  'B+', 'B-'],
    ['Bill Laimbeer',           '92%',  'C+', 'D+', 'B-', 'B+', 'A',  'B+', 'D+', 'B+', 'B',  'F'],
    ['Cliff T. Robinson',       '88%',  'C+', 'B+', 'C+', 'D+', 'C+', 'C',  'C-', 'A',  'D-', 'C+'],
    ['Quinn Buckner',           '57%',  'C+', 'C',  'B-', 'D-', 'F',  'A+', 'B+', 'D+', 'B',  'B-'],
    ['Larry Smith',             '85%',  'C+', 'B',  'D-', 'F',  'C+', 'C',  'C',  'A+', 'D-', 'B'],
    ['Phil Hubbard',            '87%',  'C+', 'B',  'C-', 'D',  'C+', 'B',  'C+', 'B-', 'B-', 'C+'],
    ['Ron Brewer',              '82%',  'C+', 'C',  'B-', 'C',  'F',  'B-', 'B-', 'D-', 'C',  'C'],
    ['Wayne Cooper',            '81%',  'C+', 'D+', 'C',  'D',  'B-', 'D+', 'D',  'A-', 'D-', 'C-'],
    ['Geoff Huston',            '85%',  'C',  'C+', 'C-', 'C-', 'F',  'B-', 'A-', 'F',  'C+', 'C+'],
    ['Allen Leavell',           '83%',  'C',  'C+', 'B-', 'C',  'F',  'B-', 'A-', 'F',  'C-', 'B+'],
    ['Johnny R. Davis',         '85%',  'C',  'C+', 'B',  'D+', 'D-', 'B+', 'B+', 'D-', 'C',  'C+'],
    ['Junior Marion',           '56%',  'C',  'A-', 'C+', 'B',  'D',  'B-', 'B-', 'D-', 'D',  'A-'],
    ['Brad Davis',              '87%',  'C',  'B-', 'C-', 'B-', 'D-', 'A',  'B+', 'F',  'C',  'C'],
    ['Otis Birdsong',           '56%',  'C',  'B',  'B-', 'C+', 'B',  'F',  'B',  'D',  'C-', 'C-'],
    ['Jim Paxson',              '55%',  'C',  'C+', 'C',  'C-', 'C-', 'A-', 'B',  'D',  'B',  'B-'],
    ['Mychal Thompson',         '85%',  'C',  'B-', 'C-', 'D-', 'A-', 'B+', 'D',  'B+', 'B-', 'F'],
    ['Armond Hill',             '81%',  'C',  'B-', 'B+', 'D-', 'F',  'C',  'B',  'D-', 'C+', 'C'],
    ['Mike Bratz',              '81%',  'C',  'C-', 'B',  'B-', 'F',  'B-', 'B+', 'D',  'C',  'C-'],
    ['Norm Nixon',              '59%',  'C',  'C+', 'C',  'D+', 'B',  'D-', 'B',  'D',  'C+', 'B+'],
    ['Vinnie Johnson',          '84%',  'C',  'C',  'B',  'C-', 'D',  'B+', 'B',  'C-', 'C+', 'C+'],
    ['Major Jones',             '100%', '72', 'C-', 'D-', 'D-', 'B-', 'C-', 'D',  'B',  'D-', 'C+'],
    ['Mike Dunleavy',           '50%',  'C',  'D',  'B',  'C+', 'F',  'B-', 'B',  'D-', 'D',  'D'],
    ['Ben Poquette',            '82%',  'C-', 'D+', 'B',  'C-', 'B-', 'F',  'D+', 'B-', 'D-', 'D'],
    ['Eddie Jordan',            '81%',  'C-', 'C',  'C',  'C',  'D-', 'B',  'B+', 'D',  'B',  'C+'],
    ['Alvin Scott',             '100%', '71', 'C-', 'C',  'C-', 'D',  'C+', 'C+', 'C-', 'C-', 'C-'],
    ['Ernie Grunfeld',          '84%',  'C-', 'C-', 'B-', 'C+', 'D+', 'B-', 'C+', 'D+', 'C-', 'D'],
    ['Billy McKinney',          '79%',  'C-', 'C',  'B',  'D',  'F',  'B',  'B+', 'F',  'C-', 'C'],
    ['Jerry Sichting',          '79%',  'C-', 'D',  'B+', 'C+', 'F',  'B',  'B-', 'D-', 'C-', 'F'],
    ['Calvin Garrett',          '100%', '70', 'C',  'C',  'C-', 'D',  'C',  'C',  'C-', 'D-', 'D+'],
    ['Jim Spanarkel',           '78%',  'C-', 'C',  'C+', 'C',  'D-', 'C',  'B-', 'C-', 'D-', 'F'],
    ['Earl Cureton',            '100%', '70', 'D+', 'F',  'F',  'C',  'D',  'D',  'A-', 'D-', 'C'],
    ['Steve Hayes',             '100%', '69', 'C-', 'F',  'F',  'B-', 'F',  'D',  'B-', 'D',  'F'],
    ['James Edwards',           '100%', '69', 'B-', 'C-', 'F',  'B',  'C+', 'D-', 'B-', 'D',  'F'],
    ['Clint Richardson',        '80%',  'D+', 'C',  'B',  'C+', 'F',  'C',  'C',  'D-', 'D-', 'D-'],
    ['Kelly Tate',              '1%',   'F',  'F',  'F',  'F',  'F',  'F',  'F',  'F',  'F',  'F'],
    ['Jorge Barbosa',           '6%',   'F',  'F',  'F',  'F',  'D-', 'F',  'F',  'F',  'F',  'F'],
];

/**
 * A posição de cada um, deduzida do jogador REAL — não está no print.
 *
 * Onde o jogador alternou de posição na carreira, vale a que ele jogou mais
 * tempo: Billy Cunningham entra como SF (jogou SF e PF), Mychal Thompson
 * como C (PF e C), Reggie Theus como SG (começou PG).
 *
 * Quem não está aqui fica sem posição, de propósito. @see o cabeçalho.
 */
const CLASSE_6070_P2_POSICOES = [
    'Bill Russell'           => 'C',
    'Wes Unseld'             => 'C',
    'Bob Cousy'              => 'PG',
    'David Thompson'         => 'SG',
    'Walt Frazier'           => 'PG',
    'Sidney Moncrief'        => 'SG',
    'Alex English'           => 'SF',
    'Bob Lanier'             => 'C',
    'Dan Issel'              => 'C',
    'Pete Maravich'          => 'PG',
    'Mike Woodson'           => 'SG',
    'Dave Debusschere'       => 'PF',
    'Earl Monroe'            => 'SG',
    'Dave Greenwood'         => 'PF',
    'Reggie Theus'           => 'SG',
    'World B. Free'          => 'SG',
    'Billy Cunningham'       => 'SF',
    'Mickey Johnson'         => 'PF',
    'Calvin Murphy'          => 'PG',
    'Joe Barry Carroll'      => 'C',
    'Jeff Ruland'            => 'C',
    'Darrell Griffith'       => 'SG',
    'Micheal Ray Richardson' => 'PG',
    'Phil Ford'              => 'PG',
    'Kiki Vandeweghe'        => 'SF',
    'Willis Reed'            => 'C',
    'George Johnson'         => 'C',
    'Gerald Henderson Sr.'   => 'PG',
    'Purvis Short'           => 'SF',
    'Michael Cooper'         => 'SG',
    'Wally Walker'           => 'SF',
    'Robert Reid'            => 'SF',
    'T.R. Dunn'              => 'SG',
    'Maurice Cheeks'         => 'PG',
    'Bill Laimbeer'          => 'C',
    'Cliff T. Robinson'      => 'PF',
    'Quinn Buckner'          => 'PG',
    'Larry Smith'            => 'PF',
    'Phil Hubbard'           => 'PF',
    'Ron Brewer'             => 'SG',
    'Wayne Cooper'           => 'C',
    'Geoff Huston'           => 'PG',
    'Allen Leavell'          => 'PG',
    'Johnny R. Davis'        => 'SG',
    'Brad Davis'             => 'PG',
    'Otis Birdsong'          => 'SG',
    'Jim Paxson'             => 'SG',
    'Mychal Thompson'        => 'C',
    'Armond Hill'            => 'PG',
    'Mike Bratz'             => 'PG',
    'Norm Nixon'             => 'PG',
    'Vinnie Johnson'         => 'SG',
    'Major Jones'            => 'PF',
    'Mike Dunleavy'          => 'PG',
    'Ben Poquette'           => 'PF',
    'Eddie Jordan'           => 'PG',
    'Alvin Scott'            => 'SF',
    'Ernie Grunfeld'         => 'SF',
    'Billy McKinney'         => 'PG',
    'Jerry Sichting'         => 'PG',
    'Calvin Garrett'         => 'SG',
    'Jim Spanarkel'          => 'SG',
    'Earl Cureton'           => 'PF',
    'Steve Hayes'            => 'C',
    'James Edwards'          => 'C',
    'Clint Richardson'       => 'SG',
];
