<?php
/**
 * ── AS LENDAS DO FBA DRAFT ───────────────────────────────────────────
 *
 * Pedido do Marcos (07/10/2026): "ovrs maiores, com lendas, claro". O
 * baralho dos elencos reais vai até 89 — o topo é Haaland, Mbappé, De
 * Bruyne. Pra chegar aos 92 que ele pediu, e pra a carta dourada ter o peso
 * que tem no FIFA, entram estas.
 *
 * ── OS NÚMEROS AQUI SÃO CURADOS, E ISSO É DECLARADO ──────────────────
 *
 * Nenhuma fonte do projeto traz OVR de Pelé. O que está aqui é avaliação
 * minha, feita pra o jogo funcionar: 90 a 94 pros nomes que qualquer um
 * reconhece, com a posição e o clube do auge. É o mesmo tratamento que o
 * projeto já dá às lendas da NBA, que também têm atributos escritos à mão
 * em games/core/build_lendas.php — e, como lá, mexer num número aqui é
 * mexer no jogo, não corrigir um dado.
 *
 * O CLUBE É O DO AUGE, e serve à química como qualquer outro: pegar Pelé e
 * um Santos atual dá o bônus de mesmo clube. É essa a graça da lenda no
 * FUT — ela conversa com o time montado em vez de ser um enfeite solto.
 *
 * ── RARAS DE PROPÓSITO ───────────────────────────────────────────────
 *
 * São 44 cartas contra 999 do baralho normal na faixa alta. A chance de uma
 * aparecer é pequena (@see DFUT_CHANCE_LENDA), porque lenda que sai toda
 * vaga não é lenda — é inflação. E o plano do Marcos é justamente esse: as
 * promoções e os pacotes virão depois, e este arquivo é o lugar onde elas
 * entram sem mexer no resto.
 */

/** [nome, posição, OVR, clube do auge, liga do clube]. */
const DFUT_LENDAS = [
    // ── Brasil ───────────────────────────────────────────────────────
    ['Pelé',                'ATA', 94, 'Santos',            'BR1'],
    ['Garrincha',           'PON', 92, 'Botafogo',          'BR1'],
    ['Ronaldo Fenômeno',    'ATA', 93, 'Real Madrid',       'ES1'],
    ['Ronaldinho Gaúcho',   'MEI', 93, 'Barcelona',         'ES1'],
    ['Romário',             'ATA', 92, 'Barcelona',         'ES1'],
    ['Rivaldo',             'MEI', 91, 'Barcelona',         'ES1'],
    ['Kaká',                'MEI', 91, 'Milan',             'IT1'],
    ['Zico',                'MEI', 92, 'Flamengo',          'BR1'],
    ['Sócrates',            'MEI', 90, 'Corinthians',       'BR1'],
    ['Roberto Carlos',      'LAT', 91, 'Real Madrid',       'ES1'],
    ['Cafu',                'LAT', 90, 'Milan',             'IT1'],
    ['Carlos Alberto Torres','LAT', 90, 'Santos',           'BR1'],
    ['Taffarel',            'GOL', 89, 'Galatasaray',       'TR1'],
    ['Falcão',              'VOL', 90, 'Roma',              'IT1'],

    // ── Argentina e América do Sul ───────────────────────────────────
    ['Diego Maradona',      'MEI', 94, 'Napoli',            'IT1'],
    ['Lionel Messi',        'ATA', 94, 'Barcelona',         'ES1'],
    ['Alfredo Di Stéfano',  'ATA', 92, 'Real Madrid',       'ES1'],
    ['Gabriel Batistuta',   'ATA', 90, 'Fiorentina',        'IT1'],
    ['Juan Román Riquelme', 'MEI', 90, 'Boca Juniors',      'AR1'],
    ['Daniel Passarella',   'ZAG', 89, 'River Plate',       'AR1'],
    ['Iván Zamorano',       'ATA', 88, 'Inter',             'IT1'],
    ['Enzo Francescoli',    'MEI', 89, 'River Plate',       'AR1'],

    // ── Europa ───────────────────────────────────────────────────────
    ['Cristiano Ronaldo',   'ATA', 93, 'Real Madrid',       'ES1'],
    ['Johan Cruyff',        'ATA', 93, 'Ajax',              'NL1'],
    ['Franz Beckenbauer',   'ZAG', 92, 'Bayern de Munique', 'DE1'],
    ['Zinedine Zidane',     'MEI', 93, 'Real Madrid',       'ES1'],
    ['Michel Platini',      'MEI', 92, 'Juventus',          'IT1'],
    ['Marco van Basten',    'ATA', 92, 'Milan',             'IT1'],
    ['Paolo Maldini',       'ZAG', 92, 'Milan',             'IT1'],
    ['Franco Baresi',       'ZAG', 91, 'Milan',             'IT1'],
    ['Lev Yashin',          'GOL', 92, 'Dínamo Moscou',     'RU1'],
    ['Gianluigi Buffon',    'GOL', 91, 'Juventus',          'IT1'],
    ['Iker Casillas',       'GOL', 90, 'Real Madrid',       'ES1'],
    ['Oliver Kahn',         'GOL', 90, 'Bayern de Munique', 'DE1'],
    ['Andrés Iniesta',      'MEI', 91, 'Barcelona',         'ES1'],
    ['Xavi Hernández',      'MEI', 91, 'Barcelona',         'ES1'],
    ['Lothar Matthäus',     'VOL', 90, 'Bayern de Munique', 'DE1'],
    ['Gerd Müller',         'ATA', 91, 'Bayern de Munique', 'DE1'],
    ['Eusébio',             'ATA', 91, 'Benfica',           'PT1'],
    ['Luís Figo',           'PON', 90, 'Real Madrid',       'ES1'],
    ['Thierry Henry',       'ATA', 91, 'Arsenal',           'EN1'],
    ['Ryan Giggs',          'PON', 89, 'Manchester United', 'EN1'],
    ['Andrea Pirlo',        'VOL', 90, 'Juventus',          'IT1'],
    ['Zlatan Ibrahimović',  'ATA', 90, 'Milan',             'IT1'],
];

/**
 * As lendas no formato de carta do baralho.
 *
 * O escudo sai do catálogo de clubes pelo nome do clube do auge — quando o
 * clube existe lá, a carta vem com o escudo certo de graça. Quando não
 * existe, fica sem, e a tela desenha o monograma: lenda sem escudo ainda é
 * lenda.
 */
function draftFutLendas(): array
{
    static $cartas = null;
    if ($cartas !== null) return $cartas;

    $escudoDe = [];
    foreach (COPERO_CLUBES as [$nome, $liga, $forca, $escudo]) $escudoDe[$nome] = $escudo;

    $cartas = [];
    foreach (DFUT_LENDAS as [$nome, $pos, $ovr, $clube, $liga]) {
        $cartas[] = [
            'nome'   => $nome,
            'pos'    => $pos,
            'ovr'    => $ovr,
            'idade'  => 27,
            'clube'  => $clube,
            'liga'   => $liga,
            'escudo' => $escudoDe[$clube] ?? '',
            'lenda'  => true,
        ];
    }
    return $cartas;
}
