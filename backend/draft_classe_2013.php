<?php
/**
 * AS LETRINHAS DA CLASSE DE 2013, transcritas dos prints do jogo.
 *
 * Cadastradas em 14/10/2026, a pedido do Marcos. Cada linha é
 * [nome, posição, INS, MID, 3PT, INS D, PER D, PLMK, REB, IQ] — na ordem em
 * que o jogo as mostra, que é a ordem das colunas do print.
 *
 * ── NÃO É A CLASSE DO DRAFT DE 2013 ──────────────────────────────────
 *
 * O template que existia antes era o draft REAL de 2013: as 60 escolhas, de
 * Anthony Bennett a Lucas Nogueira, incluindo gente que nunca jogou (Erik
 * Murphy, Grant Jerrett, Colton Iverson). O print é a lista do JOGO, que é
 * outra coisa: entra quem virou jogador mesmo sem ter sido draftado —
 * Robert Covington, Dellavedova, Daniel Theis, Dedmon, Campazzo, Melli,
 * Seth Curry — e sai quem não vingou. São 59 nomes, e a lista do print
 * substituiu a antiga por inteiro.
 *
 * ── A ORDEM É A DO PRINT ─────────────────────────────────────────────
 *
 * Ordenada por OVR decrescente, com Giannis em 1º. É a ordem que a liga
 * quis; a anterior vinha da importação e começava em Anthony Bennett.
 *
 * ── DUAS LETRINHAS FICAM VAZIAS, DE PROPÓSITO ────────────────────────
 *
 * O sistema tem dez (IN, MID, 3PT, POST D, PER D, PLAY, REB, ATHL, IQ, POT).
 * O print corta à direita depois de IQ, então ATHL e POT ficam FORA do JSON
 * — mesmo estado das classes de 1986 e 2022. A tela do draft escreve "?" na
 * coluna sem nota, que diz o que precisa dizer: o atributo existe, ninguém
 * mediu.
 *
 * Havia ainda uma coluna começando depois de IQ, cortada no meio do rótulo
 * ("PL…"), igual à de 2022. Não dá pra saber o que é, então não entrou.
 *
 * ── COMO A TRANSCRIÇÃO FOI CONFERIDA ─────────────────────────────────
 *
 * São sete prints e eles se sobrepõem: o último nome de um é o primeiro do
 * seguinte. Toda linha repetida foi lida duas vezes, nos dois prints, e as
 * duas leituras bateram — é isso que dá confiança nas 472 letrinhas. As
 * colunas foram lidas com guias verticais desenhadas por cima da imagem,
 * porque a olho a coluna REB de uma linha vira a IQ da outra.
 *
 * @see draft_classe_2022.php  a mesma ideia, para a classe de 2022
 */

/** As oito colunas do print, na ordem, com as chaves que o sistema já usa. */
const CLASSE_2013_COLUNAS = ['IN', 'MID', '3PT', 'POST D', 'PER D', 'PLAY', 'REB', 'IQ'];

/** [nome, pos, INS, MID, 3PT, INSD, PERD, PLMK, REB, IQ] — ordem = posição na lista. */
const CLASSE_2013 = [
    ['Giannis Antetokounmpo',   'SF', 'A-', 'B',  'C',  'A-', 'B',  'B',  'C+', 'B'],
    ['C.J. McCollum',           'SG', 'A-', 'B',  'A+', 'D',  'B+', 'B+', 'D+', 'C+'],
    ['Victor Oladipo',          'SG', 'B+', 'B-', 'B+', 'C',  'B+', 'B-', 'C',  'B'],
    ['Rudy Gobert',             'C',  'B',  'D+', 'D-', 'A',  'C-', 'D',  'A',  'C-'],
    ['Otto Porter Jr.',         'SF', 'C+', 'C+', 'A-', 'C+', 'B+', 'C',  'C+', 'C+'],
    ['Nerlens Noel',            'C',  'B',  'C-', 'D',  'A-', 'B+', 'D+', 'A-', 'C'],
    ['Kentavious Caldwell-Pope','SG', 'C+', 'B',  'C+', 'B+', 'B',  'B-', 'D',  'B'],
    ['Anthony Bennett',         'PF', 'B',  'B-', 'C+', 'B+', 'B-', 'C+', 'B+', 'C+'],
    ['Cody Zeller',             'C',  'B-', 'C',  'D',  'B-', 'D',  'C',  'A-', 'D+'],
    ['Trey Burke',              'PG', 'B-', 'A-', 'A',  'C-', 'B',  'A',  'D',  'B-'],
    ['Michael Carter-Williams', 'PG', 'C+', 'D+', 'C-', 'C',  'B+', 'B+', 'C',  'B+'],
    ['Dennis Schroder',         'PG', 'B+', 'A-', 'B+', 'D-', 'B-', 'B+', 'F',  'C'],
    ['Tim Hardaway Jr.',        'SG', 'B',  'B',  'B',  'C',  'B+', 'B-', 'D+', 'B-'],
    ['Robert Covington',        'SF', 'C',  'B+', 'C+', 'C+', 'A-', 'C',  'C',  'B'],
    ['Alex Len',                'C',  'B-', 'D',  'D+', 'B+', 'C-', 'D+', 'A-', 'C-'],
    ['Steven Adams',            'C',  'B',  'C-', 'D-', 'B+', 'C',  'C-', 'A-', 'C'],
    ['André Roberson',          'SG', 'C+', 'C-', 'C+', 'B+', 'A+', 'C+', 'C-', 'A-'],
    ['Ben McLemore',            'SG', 'A-', 'B',  'B+', 'D',  'B+', 'C+', 'D',  'D'],
    ['Kelly Olynyk',            'C',  'C+', 'C-', 'C',  'B-', 'C+', 'C',  'B-', 'C+'],
    ['Lucas Nogueira',          'C',  'C+', 'D-', 'C-', 'A-', 'B',  'D+', 'A-', 'B-'],
    ['Shabazz Muhammad',        'SF', 'B-', 'C-', 'C+', 'F',  'C',  'C+', 'C',  'D-'],
    ['Seth Curry',              'SG', 'A-', 'A',  'A+', 'F',  'C+', 'B-', 'C-', 'D+'],
    ['Raul Neto',               'PG', 'B+', 'B-', 'B-', 'D+', 'B+', 'B',  'D',  'B-'],
    ['Gorgui Dieng',            'C',  'C+', 'B+', 'C+', 'B-', 'C+', 'D',  'B+', 'C'],
    ['Tony Snell',              'SG', 'C',  'B+', 'A',  'D',  'A-', 'C',  'D+', 'C-'],
    ['Matthew Dellavedova',     'PG', 'C+', 'B-', 'A-', 'F',  'B+', 'B',  'D',  'C+'],
    ['Mason Plumlee',           'C',  'B-', 'D',  'D+', 'B-', 'D+', 'C-', 'B+', 'D+'],
    ['Reggie Bullock Jr.',      'SF', 'D',  'B',  'A-', 'C-', 'C+', 'C+', 'C-', 'D'],
    ['Daniel Theis',            'C',  'B-', 'C+', 'C+', 'A-', 'C',  'C',  'B',  'D+'],
    ['Solomon Hill',            'SF', 'C+', 'B-', 'B+', 'C+', 'B+', 'C',  'C+', 'D'],
    ['Dewayne Dedmon',          'C',  'B-', 'C+', 'C-', 'B',  'C-', 'D',  'B+', 'D-'],
    ['Isaiah Canaan',           'PG', 'B+', 'B',  'B-', 'D-', 'B',  'B+', 'F',  'C-'],
    ['Shane Larkin',            'PG', 'A-', 'B',  'B',  'D+', 'B+', 'B+', 'C-', 'C+'],
    ['Sergey Karasev',          'SF', 'C',  'C+', 'C+', 'C+', 'B',  'C+', 'C',  'C-'],
    ['James Ennis III',         'SF', 'B-', 'B-', 'B',  'D',  'B-', 'C+', 'D',  'D'],
    ['Álex Abrines',            'SG', 'C+', 'C+', 'B+', 'D',  'B',  'C+', 'D',  'D-'],
    ['Jeff Withey',             'C',  'B-', 'C',  'D',  'C',  'D+', 'C-', 'A-', 'D-'],
    ['Rodney McGruder',         'SG', 'B-', 'B-', 'A-', 'C-', 'B-', 'C+', 'D-', 'D-'],
    ['Joffrey Lauvergne',       'C',  'C+', 'C',  'B-', 'B-', 'D+', 'C-', 'B+', 'D-'],
    ['Allen Crabbe',            'SG', 'A-', 'B-', 'B+', 'D-', 'B-', 'B-', 'D-', 'D-'],
    ['Brandon Paul',            'SG', 'C+', 'B',  'C+', 'D',  'B+', 'B-', 'D',  'D+'],
    ['Ryan Kelly',              'PF', 'C-', 'C+', 'B',  'C+', 'C+', 'C-', 'C+', 'D-'],
    ['Nate Wolters',            'PG', 'B-', 'B-', 'B',  'D+', 'C+', 'B+', 'F',  'D'],
    ['Nicolo Melli',            'PF', 'D+', 'A-', 'C',  'C+', 'D-', 'C-', 'C+', 'C-'],
    ['Troy Daniels',            'SG', 'C-', 'C+', 'A',  'F',  'C-', 'C+', 'D',  'D+'],
    ['Mike Muscala',            'C',  'B',  'B-', 'A-', 'B-', 'C+', 'C-', 'C+', 'D+'],
    ['Glen Rice Jr.',           'SG', 'C+', 'C+', 'B',  'C-', 'B',  'C+', 'C-', 'D+'],
    ['Archie Goodwin',          'SG', 'B-', 'B-', 'B-', 'D',  'B',  'B',  'C-', 'D+'],
    ['Ray McCallum',            'PG', 'B',  'B-', 'C+', 'F',  'B',  'B+', 'D-', 'D'],
    ['Ryan Broekhoff',          'SF', 'C',  'B-', 'B',  'D+', 'C+', 'C',  'C-', 'D-'],
    ['Nemanja Nedovic',         'SG', 'C+', 'B-', 'B-', 'D',  'B+', 'B-', 'D',  'D+'],
    ['Jamaal Franklin',         'SG', 'C',  'C',  'B-', 'C-', 'B+', 'C+', 'D+', 'D'],
    ['Pierre Jackson',          'PG', 'C',  'B',  'B-', 'D',  'B+', 'B+', 'F',  'D'],
    ['Ricky Ledo',              'SG', 'C',  'C-', 'B-', 'D+', 'B',  'C',  'C',  'D'],
    ['Lorenzo Brown',           'PG', 'C',  'C+', 'B',  'F',  'B',  'B+', 'F',  'D+'],
    ['Facundo Campazzo',        'PG', 'C+', 'C',  'B',  'F',  'C',  'B+', 'F',  'D+'],
    ['Ian Clark',               'SG', 'B-', 'C+', 'B+', 'F',  'B-', 'C+', 'F',  'C-'],
    ['Erick Green',             'PG', 'C+', 'B',  'B',  'D-', 'C+', 'B',  'D',  'D-'],
    ['Peyton Siva',             'PG', 'C+', 'B-', 'C+', 'F',  'B+', 'B',  'F',  'D'],
];
