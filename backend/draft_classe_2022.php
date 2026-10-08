<?php
/**
 * AS LETRINHAS DA CLASSE DE 2022, transcritas dos prints do jogo.
 *
 * Cadastradas em 07/10/2026, a pedido do Marcos. Cada linha é
 * [nome, posição, INS, MID, 3PT, INS D, PER D, PLMK, REB, IQ] — na ordem em
 * que o jogo as mostra, que é a ordem das colunas do print.
 *
 * ── DUAS LETRINHAS FICAM VAZIAS, DE PROPÓSITO ────────────────────────
 *
 * O sistema tem dez (IN, MID, 3PT, POST D, PER D, PLAY, REB, ATHL, IQ,
 * POT). O print corta à direita antes de ATHL e POT, então elas ficam FORA do
 * JSON — decisão dele, e é o mesmo estado da classe de 1986, que também veio
 * sem as duas. A tela do draft escreve "?" na coluna sem nota, que diz o que
 * precisa dizer: o atributo existe, ninguém mediu.
 *
 * Havia ainda uma coluna depois de QI no print, cortada no meio do rótulo
 * ("PL…"). Não dá pra saber o que é — PLMK já veio antes de REB —, então ela
 * não foi aproveitada. Se você reconhecer o rótulo, é só dizer.
 *
 * ── A ORDEM É A DO PRINT ─────────────────────────────────────────────
 *
 * O print vem ordenado por OVR decrescente, e é essa a ordem que a liga
 * quis: Paolo Banchero em 1º. A classe tinha outra (Jalen Williams em 1º),
 * de quando foi importada — essa foi substituída.
 */

/**
 * As oito colunas do print, na ordem, com as chaves que o sistema já usa.
 *
 * O draft guarda as letrinhas num JSON na coluna `notas`, com estas chaves —
 * é o que a tela do draft e o big board leem (ORDEM_NOTAS em drafts.php).
 * ATHL e POT simplesmente não entram no JSON quando não há nota: é assim que
 * a classe de 1986 está gravada, e a tela escreve "?" na coluna.
 */
const CLASSE_2022_COLUNAS = ['IN', 'MID', '3PT', 'POST D', 'PER D', 'PLAY', 'REB', 'IQ'];

/** [nome, pos, INS, MID, 3PT, INSD, PERD, PLMK, REB, IQ] — ordem = posição na lista. */
const CLASSE_2022 = [
    ['Paolo Banchero',      'PF', 'B',  'A-', 'B-', 'B+', 'B+', 'B-', 'B+', 'B'],
    ['Jabari Smith Jr.',    'PF', 'B',  'B',  'B-', 'B-', 'B',  'C+', 'B-', 'C+'],
    ['Jalen Williams',      'SG', 'B',  'B',  'B',  'B',  'A-', 'B',  'C+', 'B+'],
    ['Chet Holmgren',       'C',  'B',  'C+', 'B-', 'A-', 'B+', 'C',  'A-', 'B-'],
    ['Keegan Murray',       'PF', 'B-', 'B-', 'B-', 'B-', 'B',  'C+', 'B+', 'C'],
    ['Jaden Ivey',          'SG', 'B+', 'B-', 'C+', 'C+', 'B+', 'B+', 'D+', 'B'],
    ['Andrew Nembhard',     'SG', 'B-', 'B',  'B+', 'D+', 'B+', 'B+', 'C-', 'B-'],
    ['Jalen Duren',         'C',  'C+', 'D',  'D-', 'B+', 'C+', 'C-', 'A-', 'D-'],
    ['Shaedon Sharpe',      'SG', 'B',  'C+', 'C+', 'C-', 'B',  'C+', 'C-', 'C-'],
    ['Dyson Daniels',       'SG', 'B-', 'B-', 'C',  'B',  'B+', 'A-', 'C',  'B+'],
    ['Max Christie',        'SG', 'B',  'B',  'B+', 'D+', 'B',  'B-', 'C-', 'C-'],
    ['Gui Santos',          'PF', 'C+', 'B-', 'B',  'B-', 'B+', 'C+', 'B-', 'C'],
    ['Bennedict Mathurin',  'SG', 'C+', 'B-', 'B+', 'D-', 'B',  'B-', 'C+', 'C'],
    ['Jeremy Sochan',       'PF', 'B-', 'C+', 'C',  'C+', 'B',  'C+', 'C+', 'C'],
    ['Karlo Matkovic',      'PF', 'C',  'C',  'C+', 'B-', 'D+', 'C-', 'B',  'D'],
    ['Johnny Davis',        'SG', 'C',  'B',  'C+', 'C+', 'B',  'B',  'D-', 'C'],
    ['Ousmane Dieng',       'PF', 'C+', 'B-', 'C+', 'C-', 'C+', 'B-', 'C+', 'C-'],
    ['Ochai Agbaji',        'SG', 'C+', 'C+', 'B-', 'C',  'B',  'C+', 'C-', 'C'],
    ['Mark Williams',       'C',  'C+', 'D',  'D-', 'B-', 'D+', 'D',  'C+', 'D'],
    ['Tari Eason',          'PF', 'B-', 'C',  'C+', 'C+', 'B',  'C',  'C+', 'D'],
    ['Dalen Terry',         'SF', 'B-', 'C+', 'C+', 'D',  'B',  'B',  'C',  'C'],
    ['Malaki Branham',      'SF', 'C',  'B',  'B+', 'D+', 'C+', 'C',  'C-', 'D-'],
    ['David Roddy',         'SF', 'C+', 'C',  'B-', 'C+', 'C+', 'C',  'C+', 'D'],
    ['Jaylin Williams',     'PF', 'C-', 'C',  'B-', 'B',  'D+', 'C',  'B-', 'C-'],
    ['Everett Burke',       'SF', 'C',  'B-', 'A-', 'C-', 'B-', 'C-', 'D-', 'D'],
    ['Shaun Evans III',     'SF', 'B-', 'B-', 'C+', 'C',  'C',  'C-', 'C-', 'C-'],
    ['Jake LaRavia',        'SF', 'C',  'C+', 'B+', 'C+', 'D+', 'C+', 'C',  'D'],
    ['Christian Braun',     'SG', 'C',  'B+', 'B',  'D-', 'B-', 'B-', 'C-', 'C'],
    ['MarJon Beauchamp',    'SF', 'C',  'B-', 'C+', 'C',  'B',  'C+', 'C+', 'D'],
    ['TyTy Washington Jr.', 'PG', 'C+', 'B-', 'B',  'C-', 'B-', 'B',  'C',  'C'],
    ['Tyson Etienne',       'PG', 'C+', 'B-', 'B',  'F',  'C',  'B',  'C-', 'D+'],
    ['A.J. Green',          'SG', 'C-', 'B',  'A-', 'D-', 'C+', 'C',  'D+', 'D-'],
    ['Jamal Cain',          'PF', 'C+', 'D+', 'C+', 'C+', 'B-', 'D+', 'B',  'D'],
    ['Allen Andersen',      'SF', 'C',  'C-', 'B-', 'C-', 'C',  'C-', 'C-', 'D-'],
    ['Dalen Phillips',      'C',  'B',  'C-', 'C-', 'B+', 'C-', 'D',  'C-', 'D'],
    ['Seth Wall',           'SG', 'B+', 'B-', 'C+', 'D+', 'B+', 'C',  'D+', 'D'],
    ['J.R. Nance',          'SF', 'C-', 'B',  'A-', 'C-', 'C',  'C-', 'D+', 'D-'],
    ['Walker Kessler',      'C',  'C-', 'C-', 'C',  'B+', 'C+', 'D+', 'B-', 'D-'],
    ['Blake Wesley',        'PG', 'B+', 'B-', 'C+', 'D+', 'B',  'B-', 'C-', 'C-'],
    ['Caleb Houstan',       'SF', 'C-', 'B-', 'B-', 'D+', 'C+', 'C+', 'C-', 'D-'],
    ['E.J. Liddell',        'SF', 'C+', 'B+', 'B',  'B-', 'C',  'C',  'B-', 'D-'],
    ['Josh Minott',         'SF', 'B-', 'C-', 'C-', 'C-', 'C+', 'C',  'B-', 'D-'],
    ['Jabari Walker',       'SF', 'C-', 'C-', 'C+', 'B-', 'C+', 'C-', 'B+', 'D'],
    ['Kevon Harris',        'SG', 'C',  'C+', 'B+', 'C',  'C+', 'C',  'C',  'D+'],
    ['Willis Bayless',      'SF', 'C+', 'C-', 'C',  'D+', 'C',  'C-', 'D+', 'D'],
    ['Kevin Simms',         'SF', 'D+', 'C',  'B',  'C',  'C',  'C',  'C',  'D'],
    ['Bryce Powell',        'C',  'B-', 'C-', 'D',  'B+', 'D',  'D',  'B',  'D-'],
    ['Bruno Radja',         'PF', 'C',  'D',  'C',  'C',  'C-', 'C-', 'C-', 'D'],
    ['Vlad Olic',           'C',  'D+', 'D',  'D',  'B-', 'C-', 'D-', 'A-', 'D-'],
    ['Simon Pickett',       'PG', 'C',  'B-', 'A-', 'C-', 'C+', 'C+', 'D+', 'D-'],
    ['Wendell Moore Jr.',   'SG', 'B-', 'C',  'B-', 'C+', 'B',  'C',  'D+', 'C'],
    ['Nikola Jovic',        'PF', 'C+', 'B-', 'C+', 'C-', 'C+', 'B-', 'C-', 'C-'],
    ['Christian Koloko',    'C',  'C',  'D+', 'F',  'B+', 'D-', 'D',  'B-', 'D-'],
    ['Jaden Hardy',         'PG', 'B',  'B-', 'C+', 'D-', 'C',  'B-', 'C-', 'D-'],
    ['Bryce McGowens',      'SG', 'B-', 'B-', 'C+', 'D-', 'C+', 'C',  'D+', 'D-'],
    ['Moussa Diabate',      'PF', 'B-', 'C',  'C',  'C+', 'D',  'D',  'B-', 'D-'],
    ['Isaiah Mobley',       'PF', 'C-', 'C-', 'C-', 'C+', 'D',  'C',  'B-', 'D-'],
    ['Tyrese Martin',       'SF', 'D',  'C+', 'B',  'D+', 'C+', 'C+', 'D-', 'D-'],
    ['Luke Travers',        'SF', 'C-', 'C+', 'C+', 'C-', 'C+', 'C',  'F',  'D-'],
    ['Alondes Williams',    'SG', 'C+', 'B-', 'C+', 'D+', 'C+', 'B-', 'C-', 'D-'],
    ['Keon Ellis',          'SG', 'C',  'C',  'C+', 'F',  'C+', 'C+', 'C-', 'D-'],
    ['Jim Claxton',         'C',  'A',  'C-', 'C+', 'B-', 'C-', 'C-', 'C',  'D-'],
    ['Warren Pollard',      'SG', 'D',  'B-', 'C+', 'D+', 'C+', 'C-', 'D',  'D-'],
    ['Adrian Landry',       'SF', 'C+', 'C-', 'C+', 'C-', 'C',  'C-', 'C-', 'D-'],
    ['Patrick Baldwin Jr.', 'PF', 'C',  'C+', 'C+', 'C-', 'C',  'C-', 'C-', 'D'],
    ['Vince Williams Jr.',  'SG', 'C-', 'C-', 'A-', 'F',  'B',  'C',  'C',  'D-'],
    ['JD Davison',          'PG', 'B',  'C+', 'C+', 'F',  'C',  'B+', 'C-', 'D-'],
    ['Javante McCoy',       'SG', 'C-', 'C+', 'B-', 'F',  'C+', 'B',  'F',  'D-'],
    ['Jaylen Sims',         'SG', 'C-', 'B-', 'B',  'D',  'C+', 'C',  'C-', 'D-'],
    ['Collin Gillespie',    'PG', 'C',  'B-', 'B+', 'D-', 'C+', 'B',  'D+', 'D-'],
    ['Julian Champagnie',   'SF', 'C',  'B-', 'B-', 'D',  'B-', 'C+', 'C-', 'D-'],
    ['Ron Harper Jr.',      'PG', 'C',  'C+', 'B-', 'D',  'C',  'C',  'D',  'D-'],
    ['Scotty Pippen Jr.',   'PG', 'C',  'B-', 'C+', 'F',  'C+', 'B-', 'C-', 'D'],
    ['Justin Minaya',       'PF', 'D',  'C-', 'B',  'C+', 'B',  'C',  'C-', 'C-'],
];
