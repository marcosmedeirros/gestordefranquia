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

/** [nome, posição, OVR, clube do auge, liga do clube, tipo]. */
const DFUT_LENDAS = [
    // ── Brasil ───────────────────────────────────────────────────────
    ['Pelé',                'ATA', 94, 'Santos',            'BR1', 'icone'],
    ['Garrincha',           'PON', 92, 'Botafogo',          'BR1', 'heroi'],
    ['Ronaldo Fenômeno',    'ATA', 93, 'Real Madrid',       'ES1', 'icone'],
    ['Ronaldinho Gaúcho',   'MEI', 93, 'Barcelona',         'ES1', 'icone'],
    ['Romário',             'ATA', 92, 'Barcelona',         'ES1', 'icone'],
    ['Rivaldo',             'MEI', 91, 'Barcelona',         'ES1', 'heroi'],
    ['Kaká',                'MEI', 91, 'AC Milan',          'IT1', 'heroi'],
    ['Zico',                'MEI', 92, 'Flamengo',          'BR1', 'icone'],
    ['Sócrates',            'MEI', 90, 'Corinthians',       'BR1', 'heroi'],
    ['Roberto Carlos',      'LAT', 91, 'Real Madrid',       'ES1', 'icone'],
    ['Cafu',                'LAT', 90, 'AC Milan',          'IT1', 'heroi'],
    ['Carlos Alberto Torres','LAT', 90, 'Santos',           'BR1', 'icone'],
    ['Taffarel',            'GOL', 89, 'Galatasaray',       'TR1', 'heroi'],
    ['Falcão',              'VOL', 90, 'Roma',              'IT1', 'heroi'],

    // ── Argentina e América do Sul ───────────────────────────────────
    ['Diego Maradona',      'MEI', 94, 'Napoli',            'IT1', 'icone'],
    ['Lionel Messi',        'ATA', 94, 'Barcelona',         'ES1', 'icone'],
    ['Alfredo Di Stéfano',  'ATA', 92, 'Real Madrid',       'ES1', 'icone'],
    ['Gabriel Batistuta',   'ATA', 90, 'Fiorentina',        'IT1', 'heroi'],
    ['Juan Román Riquelme', 'MEI', 90, 'Boca Juniors',      'AR1', 'heroi'],
    ['Daniel Passarella',   'ZAG', 89, 'River Plate',       'AR1', 'heroi'],
    ['Iván Zamorano',       'ATA', 88, 'Inter de Milão',    'IT1', 'heroi'],
    ['Enzo Francescoli',    'MEI', 89, 'River Plate',       'AR1', 'heroi'],

    // ── Europa ───────────────────────────────────────────────────────
    ['Cristiano Ronaldo',   'ATA', 93, 'Real Madrid',       'ES1', 'icone'],
    ['Johan Cruyff',        'ATA', 93, 'Ajax',              'NL1', 'icone'],
    ['Franz Beckenbauer',   'ZAG', 92, 'Bayern de Munique', 'DE1', 'icone'],
    ['Zinedine Zidane',     'MEI', 93, 'Real Madrid',       'ES1', 'icone'],
    ['Michel Platini',      'MEI', 92, 'Juventus',          'IT1', 'icone'],
    ['Marco van Basten',    'ATA', 92, 'AC Milan',          'IT1', 'icone'],
    ['Paolo Maldini',       'ZAG', 92, 'AC Milan',          'IT1', 'icone'],
    ['Franco Baresi',       'ZAG', 91, 'AC Milan',          'IT1', 'heroi'],
    ['Lev Yashin',          'GOL', 92, 'Dínamo Moscou',     'RU1', 'icone'],
    ['Gianluigi Buffon',    'GOL', 91, 'Juventus',          'IT1', 'heroi'],
    ['Iker Casillas',       'GOL', 90, 'Real Madrid',       'ES1', 'heroi'],
    ['Oliver Kahn',         'GOL', 90, 'Bayern de Munique', 'DE1', 'heroi'],
    ['Andrés Iniesta',      'MEI', 91, 'Barcelona',         'ES1', 'icone'],
    ['Xavi Hernández',      'MEI', 91, 'Barcelona',         'ES1', 'icone'],
    ['Lothar Matthäus',     'VOL', 90, 'Bayern de Munique', 'DE1', 'heroi'],
    ['Gerd Müller',         'ATA', 91, 'Bayern de Munique', 'DE1', 'icone'],
    ['Eusébio',             'ATA', 91, 'Benfica',           'PT1', 'icone'],
    ['Luís Figo',           'PON', 90, 'Real Madrid',       'ES1', 'heroi'],
    ['Thierry Henry',       'ATA', 91, 'Arsenal',           'EN1', 'icone'],
    ['Ryan Giggs',          'PON', 89, 'Manchester United', 'EN1', 'heroi'],
    ['Andrea Pirlo',        'VOL', 90, 'Juventus',          'IT1', 'heroi'],
    ['Zlatan Ibrahimović',  'ATA', 90, 'AC Milan',          'IT1', 'heroi'],
];

/**
 * A nacionalidade de cada lenda, escrita à mão.
 *
 * São 44 nomes que qualquer um sabe de cor, e deduzir do clube erraria
 * justamente nos mais famosos: Di Stéfano jogou no Real, Maradona no Napoli,
 * Ibrahimović no Milan. A química do EA FC depende de nação, então errar
 * aqui estragaria a carta mais valiosa do baralho.
 */
const DFUT_LENDA_NACAO = [
    'Pelé' => 'Brazil', 'Garrincha' => 'Brazil', 'Ronaldo Fenômeno' => 'Brazil',
    'Ronaldinho Gaúcho' => 'Brazil', 'Romário' => 'Brazil', 'Rivaldo' => 'Brazil',
    'Kaká' => 'Brazil', 'Zico' => 'Brazil', 'Sócrates' => 'Brazil',
    'Roberto Carlos' => 'Brazil', 'Cafu' => 'Brazil', 'Carlos Alberto Torres' => 'Brazil',
    'Taffarel' => 'Brazil', 'Falcão' => 'Brazil',
    'Diego Maradona' => 'Argentina', 'Lionel Messi' => 'Argentina',
    'Alfredo Di Stéfano' => 'Argentina', 'Gabriel Batistuta' => 'Argentina',
    'Juan Román Riquelme' => 'Argentina', 'Daniel Passarella' => 'Argentina',
    'Iván Zamorano' => 'Chile', 'Enzo Francescoli' => 'Uruguay',
    'Cristiano Ronaldo' => 'Portugal', 'Luís Figo' => 'Portugal', 'Eusébio' => 'Portugal',
    'Johan Cruyff' => 'Netherlands', 'Marco van Basten' => 'Netherlands',
    'Franz Beckenbauer' => 'Germany', 'Lothar Matthäus' => 'Germany',
    'Gerd Müller' => 'Germany', 'Oliver Kahn' => 'Germany',
    'Zinedine Zidane' => 'France', 'Michel Platini' => 'France', 'Thierry Henry' => 'France',
    'Paolo Maldini' => 'Italy', 'Franco Baresi' => 'Italy', 'Gianluigi Buffon' => 'Italy',
    'Andrea Pirlo' => 'Italy',
    'Lev Yashin' => 'Russia', 'Iker Casillas' => 'Spain', 'Andrés Iniesta' => 'Spain',
    'Xavi Hernández' => 'Spain', 'Ryan Giggs' => 'Wales', 'Zlatan Ibrahimović' => 'Sweden',
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

    /* AS FOTOS, quando existem. Pedido do Marcos (08/10/2026): "consegue
       pegar as fotos dos icones e herois, todos esses que nao tem". São 36
       das 44 — oito a fonte não tem sob nome nenhum, e essas continuam com o
       escudo em marca d'água. @see draftfut_lendas_fotos_cli.php */
    $fotos = [];
    $arq = __DIR__ . '/../data/draftfut_fotos_lendas.php';
    if (is_file($arq)) $fotos = (array)require $arq;

    $cartas = [];
    foreach (DFUT_LENDAS as [$nome, $pos, $ovr, $clube, $liga, $tipo]) {
        $cartas[] = [
            'nome'   => $nome,
            'pos'    => $pos,
            'ovr'    => $ovr,
            'idade'  => 27,
            'clube'  => $clube,
            'liga'   => $liga,
            'escudo' => $escudoDe[$clube] ?? '',
            'tipo'   => $tipo,          // 'icone' ou 'heroi'
            'lenda'  => true,
            /* A NAÇÃO DA LENDA VEM DO PAÍS DO CLUBE DO AUGE, e essa é a
               única carta em que isso é aceitável: o Pelé do Santos é
               brasileiro de qualquer jeito. Onde erra — Di Stéfano era
               argentino e vai contar como Espanha pelo Real —, erra num
               punhado de cartas, não em cinco mil. As comuns pegam a nação
               de verdade, da API. */
            'nac'    => DFUT_LENDA_NACAO[$nome] ?? '',
            'foto'     => (string)($fotos[$nome]['src'] ?? ''),
            /* 'recorte' é o PNG sem fundo; 'retrato' é a foto quadrada que a
               fonte tem de quem jogou antes do recorte existir. A carta
               desenha cada um de um jeito — ver .dfc-foto-retrato. */
            'fotoTipo' => (string)($fotos[$nome]['tipo'] ?? ''),
        ];
    }
    return $cartas;
}
