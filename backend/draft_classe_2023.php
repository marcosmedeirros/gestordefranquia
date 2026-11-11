<?php
/**
 * AS LETRINHAS DA CLASSE DE 2023, transcritas dos prints do jogo.
 *
 * Cadastradas em 11/11/2026, a pedido do Marcos. Mesmo formato das classes
 * de 2013 e 2022 (@see draft_classe_2022.php): cada linha é a ordem das
 * colunas do print.
 *
 * ── O OVR SÓ EXISTE PARA QUEM FOI 100% ESCOTEADO ─────────────────────
 *
 * A tela do 2K mostra NÚMERO na coluna OVR quando o scouting chegou a 100%,
 * e uma LETRA (a faixa estimada) quando não chegou. Dos 67 da lista, 21 têm
 * número; os outros 46 o jogo ainda não revelou.
 *
 * Por isso cada linha guarda as duas coisas: `sct` (o % de scouting) e `ovr`
 * (o número, ou null quando o print só deu a letra). Quem cadastra decide o
 * que fazer com o null — o que não dá é inventar número a partir da letra,
 * porque a letra é uma faixa e o draft ordena por número.
 *
 * ── DUAS LETRINHAS FICAM VAZIAS, DE PROPÓSITO ────────────────────────
 *
 * O sistema tem dez (IN, MID, 3PT, POST D, PER D, PLAY, REB, ATHL, IQ, POT).
 * O print corta à direita depois de IQ, então ATHL e POT ficam FORA — mesmo
 * estado das classes de 1986, 2013 e 2022. A tela do draft escreve "?" na
 * coluna sem nota: o atributo existe, ninguém mediu.
 *
 * Há uma coluna depois de IQ, cortada no rótulo ("PL…"), com nota visível em
 * todos. Não dá pra saber o que é — PLMK já veio antes de REB —, então, como
 * em 2022, ela não foi aproveitada.
 *
 * ── A ORDEM É A DO PRINT, E A LISTA TAMBÉM ───────────────────────────
 *
 * Ordenada por OVR decrescente, com Wembanyama em 1º. São 67 nomes contra os
 * 58 que o template tinha: entra quem o jogo lista e o template não trazia
 * (Drew Timme, Oscar Tshiebwe, Yuki Kawamura e mais doze) e sai quem o jogo
 * não lista (James Nnaji, Amari Bailey, Mojave King, Keyontae Johnson, Jalen
 * Slawson, Tarik Biberovic). Mesma decisão da classe de 2013.
 *
 * O print também corrige POSIÇÃO: Amen Thompson é SF e não PG, Podziemski é
 * SG e não SF, Whitmore é SF e não PF, Coulibaly é SG e não SF.
 */

/** As oito colunas do print, na ordem, com as chaves que o sistema já usa. */
const CLASSE_2023_COLUNAS = ['IN', 'MID', '3PT', 'POST D', 'PER D', 'PLAY', 'REB', 'IQ'];

/**
 * [nome, pos, INS, MID, 3PT, INSD, PERD, PLMK, REB, IQ, sct, ovr]
 *
 * `sct` é o % de scouting do print; `ovr` é o número quando ele apareceu, e
 * null quando o jogo mostrou só a letra. A ordem da lista é a do print.
 */
const CLASSE_2023 = [
    ['Victor Wembanyama',       'C',  'B',  'B',  'B-', 'A-', 'D+', 'C+', 'A',  'B',   91, null],
    ['Brandon Miller',          'SF', 'B',  'B',  'A-', 'C+', 'B',  'B-', 'C+', 'C+',  93, null],
    ['Amen Thompson',           'SF', 'A-', 'C-', 'D+', 'D+', 'B+', 'B+', 'B',  'B-', 100,   81],
    ['Scoot Henderson',         'PG', 'A-', 'B+', 'C+', 'D',  'B-', 'A-', 'C-', 'B-',  92, null],
    ['Ausar Thompson',          'SF', 'A-', 'C-', 'C',  'B-', 'B+', 'B',  'B-', 'B-', 100,   78],
    ['Brandin Podziemski',      'SG', 'B',  'B-', 'B+', 'D+', 'B-', 'B',  'C+', 'C',   91, null],
    ['Keyonte George',          'PG', 'B',  'B',  'A-', 'D-', 'B-', 'C',  'A',  'C',  100,   77],
    ['Cason Wallace',           'SG', 'B',  'B-', 'B+', 'C+', 'A',  'B+', 'C',  'B+',  92, null],
    ['Jaime Jaquez Jr.',        'SG', 'C+', 'B',  'C+', 'B+', 'B+', 'B',  'B+', 'B+', 100,   76],
    ['Cam Whitmore',            'SF', 'B',  'C-', 'C+', 'C',  'B-', 'B-', 'C-', 'D',   86, null],
    ['Bilal Coulibaly',         'SG', 'A-', 'C-', 'B-', 'A-', 'A-', 'C+', 'C',  'C+', 100,   75],
    ['Anthony Black',           'PG', 'B',  'C',  'B-', 'C',  'A-', 'B+', 'C',  'B-', 100,   74],
    ['Noah Clowney',            'PF', 'B',  'C+', 'C+', 'B-', 'C+', 'C',  'B+', 'C-', 100,   74],
    ['Nick Smith Jr.',          'SG', 'B-', 'B',  'B',  'D-', 'B-', 'B',  'D+', 'C-',  86, null],
    ['Dereck Lively II',        'C',  'B-', 'C-', 'D+', 'B+', 'C',  'C',  'A-', 'C',  100,   74],
    ['Jalen Wilson',            'SF', 'C',  'C+', 'B-', 'F',  'C',  'C',  'C+', 'D',   83, null],
    ['Gradey Dick',             'SG', 'C+', 'B',  'A-', 'C',  'B+', 'B-', 'C',  'C+', 100,   73],
    ['G.G. Jackson',            'PF', 'B',  'C',  'B+', 'C',  'C',  'C-', 'C',  'D',   86, null],
    ['Jalen Hood-Schifino',     'SG', 'C',  'B+', 'C+', 'C-', 'B+', 'B+', 'D',  'C',   87, null],
    ['Taylor Hendricks',        'PF', 'C',  'B-', 'B',  'B-', 'B',  'C',  'C+', 'C',   87, null],
    ['Jordan Hawkins',          'SG', 'C',  'B+', 'D+', 'B-', 'B-', 'C-', 'C',  'C',   54, null],
    ['Olivier-Maxence Prosper', 'SF', 'B',  'C',  'C+', 'C+', 'B+', 'C',  'C',  'D+', 100,   73],
    ['Jarace Walker',           'SF', 'B+', 'C',  'C+', 'B',  'B-', 'C+', 'B',  'D',  100,   73],
    ['Trey Jemison III',        'C',  'C+', 'D-', 'F',  'B+', 'D-', 'D',  'B-', 'D-',  78, null],
    ['Dariq Whitehead',         'SF', 'B-', 'B-', 'B+', 'D',  'C+', 'C+', 'D+', 'D+',  55, null],
    ['Jett Howard',             'SG', 'C',  'B',  'B+', 'C',  'B-', 'C+', 'C-', 'C-', 100,   72],
    ['Kobe Bufkin',             'SG', 'B-', 'B-', 'B-', 'D+', 'C+', 'B',  'D+', 'D',   84, null],
    ['Kris Murray',             'PF', 'C',  'C+', 'B-', 'C+', 'B-', 'C',  'C+', 'C-',  85, null],
    ['Leonard Miller',          'PF', 'B',  'C',  'C+', 'C',  'C+', 'C',  'C+', 'D-', 100,   71],
    ['Drew Timme',              'C',  'B-', 'B-', 'B',  'C',  'D-', 'C',  'B-', 'D',   48, null],
    ['Craig Porter Jr.',        'PG', 'C+', 'B-', 'C+', 'D+', 'B-', 'B',  'C',  'C',   87, null],
    ['Oscar Tshiebwe',          'PF', 'C+', 'C',  'D-', 'B',  'C-', 'D+', 'A-', 'D-',  77, null],
    ['Marcus Bagley',           'SF', 'B',  'C-', 'C+', 'C-', 'C+', 'C',  'B-', 'D-', 100,   70],
    ['Hunter Tyson',            'PF', 'C',  'B-', 'B+', 'C',  'C+', 'C',  'C',  'D+', 100,   70],
    ['Jordan Walsh',            'SG', 'B-', 'C-', 'C',  'C+', 'B+', 'C+', 'C-', 'C+',  51, null],
    ['Kobe Brown',              'SF', 'C-', 'C+', 'B-', 'C+', 'C+', 'C',  'C+', 'D-',  78, null],
    ['Ben Sheppard',            'SG', 'D+', 'B',  'B+', 'C-', 'C+', 'C+', 'C-', 'D+', 100,   70],
    ['Marcus Sasser',           'PG', 'C+', 'B',  'B',  'D-', 'B',  'B',  'D+', 'D+',  56, null],
    ['Colby Jones',             'SG', 'C',  'C',  'B-', 'D+', 'B',  'C+', 'C-', 'C-',  50, null],
    ['Brice Sensabaugh',        'SF', 'C',  'B+', 'B+', 'C-', 'C',  'C+', 'C+', 'C-', 100,   70],
    ['Andre Jackson Jr.',       'SG', 'B',  'C+', 'C+', 'C',  'C+', 'C+', 'C+', 'D',   45, null],
    ['Julian Phillips',         'PF', 'B-', 'C',  'C+', 'C-', 'B-', 'C',  'C',  'C',  100,   69],
    ['Yuri Collins',            'PG', 'C+', 'C+', 'B-', 'F',  'B',  'A-', 'D-', 'D+',  50, null],
    ['Adama Sanogo',            'PF', 'C',  'C+', 'C',  'C',  'D',  'D+', 'B+', 'D-',  78, null],
    ['Sidy Cissoko',            'SG', 'C+', 'C',  'C-', 'C',  'C+', 'C',  'C',  'D',   45, null],
    ['Tristan Vukcevic',        'C',  'C',  'C+', 'C+', 'C+', 'D+', 'C-', 'C+', 'D-', 100,   69],
    ['Rayan Rupert',            'SG', 'C-', 'C-', 'C+', 'C-', 'B',  'C+', 'D',  'C+', 100,   69],
    ['Toumani Camara',          'SF', 'C-', 'C-', 'C+', 'C-', 'C-', 'C-', 'B',  'D-',  43, null],
    ['Mouhamed Gueye',          'PF', 'C-', 'C',  'C-', 'C',  'C-', 'C-', 'B-', 'D-',  78, null],
    ['Maxwell Lewis',           'SF', 'C-', 'C',  'B-', 'F',  'B-', 'C',  'D-', 'D-',  43, null],
    ['Chris Livingston',        'SF', 'C+', 'C',  'C',  'C-', 'B-', 'C',  'C-', 'D-',  45, null],
    ['Seth Lundy',              'SF', 'C',  'B',  'C',  'D',  'C+', 'C',  'C+', 'D-',  43, null],
    ['Julian Strawther',        'SG', 'C',  'B-', 'B+', 'D+', 'B-', 'C+', 'D+', 'D-',  48, null],
    ['Jaylen Clark',            'SG', 'D+', 'C+', 'C-', 'A-', 'C-', 'C-', 'D+', 'B',  100,   68],
    ['Jalen Pickett',           'PG', 'C+', 'C',  'C+', 'D+', 'C+', 'B-', 'C',  'C-',  77, null],
    ['Trayce Jackson-Davis',    'PF', 'C',  'D',  'C+', 'C',  'C+', 'B',  'D-', 'B',  100,   68],
    ['Isaiah Wong',             'PG', 'C',  'B-', 'C+', 'D-', 'C+', 'B-', 'D-', 'D-',  76, null],
    ['Yuki Kawamura',           'PG', 'C-', 'C',  'B-', 'F',  'C+', 'B',  'D',  'D-',  43, null],
    ['Jazian Gortman',          'PG', 'C-', 'C+', 'C+', 'F',  'C+', 'B-', 'D+', 'D-',  78, null],
    ['Emoni Bates',             'PF', 'C',  'B',  'B-', 'D',  'C-', 'C',  'C-', 'D-',  74, null],
    ['Erik Stevenson',          'SG', 'D+', 'B-', 'B-', 'D',  'C',  'C+', 'C-', 'D-',  42, null],
    ['Colin Castleton',         'C',  'D+', 'C-', 'D-', 'C+', 'F',  'C-', 'C+', 'D-',   9, null],
    ['Liam Robbins',            'C',  'D+', 'C',  'C+', 'C-', 'D-', 'C-', 'C+', 'D-',  43, null],
    ['Jaylen Martin',           'SG', 'B-', 'D',  'D',  'D',  'B-', 'C',  'B-', 'D-',  44, null],
    ['Jordan Miller',           'SG', 'B-', 'C+', 'C+', 'C',  'C+', 'C',  'D',  'D-',  44, null],
    ['Mitch Knox',              'PF', 'F',  'F',  'F',  'F',  'F',  'F',  'F',  'F',    5, null],
    ['Boris York',              'C',  'F',  'F',  'F',  'D-', 'F',  'F',  'F',  'F',    3, null],
];
