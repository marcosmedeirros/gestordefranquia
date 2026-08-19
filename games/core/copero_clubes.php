<?php
/**
 * As ligas e os clubes do Copero.
 *
 * Separado do jogo de propósito: é catálogo, muda por manutenção (clube sobe,
 * cai, muda de nome) e não por regra de jogo. Quem mexe aqui não precisa
 * entender o motor da carreira.
 *
 * ── Sobre os escudos ──────────────────────────────────────────────────
 *
 * Nome de clube é referência factual e fica aqui. Escudo é marca dos clubes:
 * o projeto NÃO hospeda a imagem — o campo `escudo` guarda uma URL pública
 * (thesportsdb), igual ao LOGOS_CLUBE do caminho.php faz com os times de
 * basquete. Clube sem URL cai no monograma das iniciais, então o jogo
 * funciona inteiro mesmo com o campo vazio, e a tela nunca fica com buraco.
 *
 * As URLs entram aos poucos e só dá pra conferir abrindo no navegador — o
 * ambiente onde este arquivo foi escrito bloqueia host externo.
 *
 * ── Sobre a força ─────────────────────────────────────────────────────
 *
 * `forca` (1 a 100) é o que o motor usa pra decidir quem te procura, quanto
 * você vale e o quanto evolui jogando ali. Não é ranking oficial de nada: é
 * a régua interna do jogo, e existe pra que sair do Colón pro Palmeiras
 * signifique alguma coisa.
 *
 * `nivel` é a divisão dentro do país: 1 é a principal. A carreira começa
 * embaixo e sobe, então as divisões de baixo são parte do jogo, não enfeite.
 */

/** A ordem em que os continentes aparecem, e o rótulo de cada um. */
const COPERO_CONTINENTES = [
    'SAM' => 'América do Sul',
    'EUR' => 'Europa',
    'NAM' => 'América do Norte',
    'ASI' => 'Ásia',
    'AFR' => 'África',
    'OCE' => 'Oceania',
];

/**
 * As ligas: id => [país, continente, nome, nível, força média].
 *
 * Padrão é uma liga por país. Brasil e Inglaterra têm três divisões;
 * Argentina, Espanha, Itália,
 * Alemanha e França têm duas — o suficiente pra existir degrau sem
 * transformar o catálogo em enciclopédia.
 */
const COPERO_LIGAS = [
    // ── América do Sul ────────────────────────────────────────────────
    'BR1'  => ['BRA', 'SAM', 'Brasileirão Série A', 1, 86],
    'BR2'  => ['BRA', 'SAM', 'Brasileirão Série B', 2, 68],
    'BR3'  => ['BRA', 'SAM', 'Brasileirão Série C', 3, 54],
    'AR1'  => ['ARG', 'SAM', 'Liga Profesional',    1, 82],
    'AR2'  => ['ARG', 'SAM', 'Primera Nacional',    2, 62],
    'UY1'  => ['URU', 'SAM', 'Primera División',    1, 68],
    'CL1'  => ['CHI', 'SAM', 'Primera División',    1, 66],
    'CO1'  => ['COL', 'SAM', 'Categoría Primera A', 1, 68],

    // ── Europa ────────────────────────────────────────────────────────
    'EN1'  => ['ENG', 'EUR', 'Premier League',      1, 96],
    'EN2'  => ['ENG', 'EUR', 'Championship',        2, 76],
    'EN3'  => ['ENG', 'EUR', 'League One',          3, 58],
    'ES1'  => ['ESP', 'EUR', 'LaLiga',              1, 94],
    'ES2'  => ['ESP', 'EUR', 'LaLiga 2',            2, 70],
    'IT1'  => ['ITA', 'EUR', 'Serie A',             1, 92],
    'IT2'  => ['ITA', 'EUR', 'Serie B',             2, 68],
    'DE1'  => ['GER', 'EUR', 'Bundesliga',          1, 92],
    'DE2'  => ['GER', 'EUR', '2. Bundesliga',       2, 70],
    'FR1'  => ['FRA', 'EUR', 'Ligue 1',             1, 88],
    'FR2'  => ['FRA', 'EUR', 'Ligue 2',             2, 66],
    'PT1'  => ['POR', 'EUR', 'Primeira Liga',       1, 82],
    'NL1'  => ['NED', 'EUR', 'Eredivisie',          1, 80],
    'BE1'  => ['BEL', 'EUR', 'Pro League',          1, 76],
    'TR1'  => ['TUR', 'EUR', 'Süper Lig',           1, 78],
    'RU1'  => ['RUS', 'EUR', 'Premier Liga',        1, 76],
    'SC1'  => ['SCO', 'EUR', 'Premiership',         1, 72],
    'GR1'  => ['GRE', 'EUR', 'Super League',        1, 72],

    // ── América do Norte ──────────────────────────────────────────────
    'US1'  => ['USA', 'NAM', 'MLS',                 1, 76],
    'MX1'  => ['MEX', 'NAM', 'Liga MX',             1, 78],

    // ── Ásia ──────────────────────────────────────────────────────────
    'SA1'  => ['KSA', 'ASI', 'Saudi Pro League',    1, 82],
    'JP1'  => ['JPN', 'ASI', 'J1 League',           1, 74],
    'KR1'  => ['KOR', 'ASI', 'K League 1',          1, 72],

    // ── África ────────────────────────────────────────────────────────
    'EG1'  => ['EGY', 'AFR', 'Premier League',      1, 70],
    'MA1'  => ['MAR', 'AFR', 'Botola Pro',          1, 68],
    'ZA1'  => ['RSA', 'AFR', 'Premiership',         1, 66],

    // ── Oceania ───────────────────────────────────────────────────────
    'AU1'  => ['AUS', 'OCE', 'A-League',            1, 68],
];

/**
 * Os clubes: [nome, liga, força, escudo].
 *
 * A força varia DENTRO da liga — é o que faz um Palmeiras não ser um
 * Cuiabá, e é dela que sai quem te procura conforme seu OVR sobe.
 *
 * `escudo` vazio vira monograma. Preencher é manutenção, não pré-requisito.
 */
const COPERO_CLUBES = [
    // ── Brasil ────────────────────────────────────────────────────────
    ['Palmeiras',        'BR1', 92, ''], ['Flamengo',        'BR1', 92, ''],
    ['Atlético-MG',      'BR1', 86, ''], ['Fluminense',      'BR1', 84, ''],
    ['São Paulo',        'BR1', 84, ''], ['Internacional',   'BR1', 83, ''],
    ['Grêmio',           'BR1', 83, ''], ['Corinthians',     'BR1', 82, ''],
    ['Botafogo',         'BR1', 84, ''], ['Cruzeiro',        'BR1', 80, ''],
    ['Athletico-PR',     'BR1', 80, ''], ['Fortaleza',       'BR1', 79, ''],
    ['RB Bragantino',    'BR1', 78, ''], ['Bahia',           'BR1', 78, ''],
    ['Vasco da Gama',    'BR1', 77, ''], ['Cuiabá',          'BR1', 72, ''],
    ['Santos',           'BR2', 74, ''], ['Sport Recife',    'BR2', 70, ''],
    ['Ceará',            'BR2', 68, ''], ['Goiás',           'BR2', 67, ''],
    ['Coritiba',         'BR2', 66, ''], ['Avaí',            'BR2', 62, ''],
    ['Paysandu',         'BR3', 56, ''], ['Remo',            'BR3', 55, ''],
    ['Volta Redonda',    'BR3', 52, ''], ['Ypiranga',        'BR3', 50, ''],

    // ── Argentina ─────────────────────────────────────────────────────
    ['River Plate',      'AR1', 88, ''], ['Boca Juniors',    'AR1', 87, ''],
    ['Racing',           'AR1', 82, ''], ['San Lorenzo',     'AR1', 79, ''],
    ['Independiente',    'AR1', 79, ''], ['Estudiantes',     'AR1', 80, ''],
    ['Vélez Sarsfield',  'AR1', 78, ''], ['Talleres',        'AR1', 79, ''],
    ['Rosario Central',  'AR1', 78, ''], ['Newell\'s',       'AR1', 77, ''],
    ['Belgrano',         'AR1', 74, ''], ['Godoy Cruz',      'AR1', 73, ''],
    ['Lanús',            'AR1', 75, ''], ['Barracas Central','AR1', 70, ''],
    ['Gimnasia (M)',     'AR2', 62, ''], ['Colón',           'AR2', 64, ''],
    ['Temperley',        'AR2', 58, ''], ['Almirante Brown', 'AR2', 57, ''],

    // ── Resto da América do Sul ───────────────────────────────────────
    ['Peñarol',          'UY1', 72, ''], ['Nacional',        'UY1', 72, ''],
    ['Defensor',         'UY1', 64, ''],
    ['Colo-Colo',        'CL1', 72, ''], ['Universidad de Chile', 'CL1', 70, ''],
    ['Cobresal',         'CL1', 62, ''],
    ['Atlético Nacional','CO1', 74, ''], ['Millonarios',     'CO1', 72, ''],
    ['Junior',           'CO1', 70, ''],

    // ── Inglaterra ────────────────────────────────────────────────────
    ['Manchester City',  'EN1', 99, ''], ['Liverpool',       'EN1', 97, ''],
    ['Arsenal',          'EN1', 96, ''], ['Manchester United','EN1', 92, ''],
    ['Chelsea',          'EN1', 92, ''], ['Tottenham',       'EN1', 90, ''],
    ['Newcastle',        'EN1', 89, ''], ['Aston Villa',     'EN1', 88, ''],
    ['Brighton',         'EN1', 85, ''], ['West Ham',        'EN1', 84, ''],
    ['Everton',          'EN1', 82, ''], ['Fulham',          'EN1', 82, ''],
    ['Crystal Palace',   'EN1', 81, ''], ['Wolves',          'EN1', 80, ''],
    ['Leeds United',     'EN2', 76, ''], ['Southampton',     'EN2', 75, ''],
    ['Norwich City',     'EN2', 72, ''], ['Sunderland',      'EN2', 72, ''],
    ['Sheffield United', 'EN2', 71, ''], ['Watford',         'EN2', 70, ''],
    ['Bolton',           'EN3', 58, ''], ['Charlton',        'EN3', 57, ''],
    ['Barnsley',         'EN3', 55, ''], ['Wigan',           'EN3', 55, ''],

    // ── Espanha ───────────────────────────────────────────────────────
    ['Real Madrid',      'ES1', 99, ''], ['Barcelona',       'ES1', 97, ''],
    ['Atlético de Madrid','ES1', 93, ''], ['Athletic Club',  'ES1', 88, ''],
    ['Real Sociedad',    'ES1', 87, ''], ['Villarreal',      'ES1', 86, ''],
    ['Real Betis',       'ES1', 85, ''], ['Sevilla',         'ES1', 85, ''],
    ['Valencia',         'ES1', 83, ''], ['Celta de Vigo',   'ES1', 80, ''],
    ['Getafe',           'ES1', 79, ''], ['Osasuna',         'ES1', 79, ''],
    ['Real Zaragoza',    'ES2', 66, ''], ['Sporting Gijón',  'ES2', 65, ''],
    ['Racing Santander', 'ES2', 64, ''], ['Eibar',           'ES2', 64, ''],

    // ── Itália ────────────────────────────────────────────────────────
    ['Inter de Milão',   'IT1', 95, ''], ['Juventus',        'IT1', 93, ''],
    ['AC Milan',         'IT1', 92, ''], ['Napoli',          'IT1', 91, ''],
    ['Roma',             'IT1', 88, ''], ['Atalanta',        'IT1', 88, ''],
    ['Lazio',            'IT1', 86, ''], ['Fiorentina',      'IT1', 85, ''],
    ['Bologna',          'IT1', 83, ''], ['Torino',          'IT1', 80, ''],
    ['Udinese',          'IT1', 78, ''], ['Venezia',         'IT1', 74, ''],
    ['Sampdoria',        'IT2', 66, ''], ['Palermo',         'IT2', 65, ''],
    ['Bari',             'IT2', 62, ''], ['Avellino',        'IT2', 58, ''],

    // ── Alemanha ──────────────────────────────────────────────────────
    ['Bayern de Munique','DE1', 98, ''], ['Bayer Leverkusen','DE1', 93, ''],
    ['Borussia Dortmund','DE1', 91, ''], ['RB Leipzig',      'DE1', 90, ''],
    ['Stuttgart',        'DE1', 85, ''], ['Eintracht Frankfurt','DE1', 85, ''],
    ['Wolfsburg',        'DE1', 82, ''], ['Werder Bremen',   'DE1', 80, ''],
    ['Borussia M\'gladbach','DE1', 80, ''], ['Union Berlin',  'DE1', 79, ''],
    ['Hamburgo',         'DE2', 68, ''], ['Schalke 04',      'DE2', 68, ''],
    ['Hertha Berlim',    'DE2', 67, ''], ['Kaiserslautern',  'DE2', 62, ''],

    // ── França ────────────────────────────────────────────────────────
    ['PSG',              'FR1', 96, ''], ['Monaco',          'FR1', 88, ''],
    ['Marseille',        'FR1', 87, ''], ['Lyon',            'FR1', 85, ''],
    ['Lille',            'FR1', 85, ''], ['Nice',            'FR1', 83, ''],
    ['Rennes',           'FR1', 83, ''], ['Lens',            'FR1', 82, ''],
    ['Nantes',           'FR1', 78, ''], ['Strasbourg',      'FR1', 77, ''],
    ['Saint-Étienne',    'FR2', 66, ''], ['Bordeaux',        'FR2', 65, ''],
    ['Metz',             'FR2', 62, ''], ['Caen',            'FR2', 58, ''],
    ['Saint-Étienne',    'FR2', 66, ''], ['Bordeaux',        'FR2', 65, ''],
    ['Metz',             'FR2', 62, ''], ['Caen',            'FR2', 58, ''],

    // ── Resto da Europa ───────────────────────────────────────────────
    ['Benfica',          'PT1', 87, ''], ['Porto',           'PT1', 86, ''],
    ['Sporting CP',      'PT1', 87, ''], ['Braga',           'PT1', 80, ''],
    ['Vitória de Guimarães','PT1', 74, ''],
    ['Ajax',             'NL1', 84, ''], ['PSV',             'NL1', 85, ''],
    ['Feyenoord',        'NL1', 84, ''], ['AZ Alkmaar',      'NL1', 78, ''],
    ['Twente',           'NL1', 75, ''],
    ['Club Brugge',      'BE1', 80, ''], ['Anderlecht',      'BE1', 77, ''],
    ['Genk',             'BE1', 76, ''], ['Standard Liège',  'BE1', 72, ''],
    ['Galatasaray',      'TR1', 84, ''], ['Fenerbahçe',      'TR1', 83, ''],
    ['Beşiktaş',         'TR1', 80, ''], ['Trabzonspor',     'TR1', 77, ''],
    ['Zenit',            'RU1', 80, ''], ['CSKA Moscou',     'RU1', 77, ''],
    ['Spartak Moscou',   'RU1', 77, ''], ['Rubin Kazan',     'RU1', 72, ''],
    ['Celtic',           'SC1', 78, ''], ['Rangers',         'SC1', 77, ''],
    ['Hearts',           'SC1', 66, ''],
    ['Olympiacos',       'GR1', 76, ''], ['PAOK',            'GR1', 74, ''],
    ['AEK Atenas',       'GR1', 73, ''], ['Panathinaikos',   'GR1', 73, ''],

    // ── América do Norte ──────────────────────────────────────────────
    ['Inter Miami',      'US1', 80, ''], ['LAFC',            'US1', 78, ''],
    ['LA Galaxy',        'US1', 77, ''], ['Seattle Sounders','US1', 76, ''],
    ['Atlanta United',   'US1', 74, ''],
    ['Club América',     'MX1', 82, ''], ['Chivas',          'MX1', 78, ''],
    ['Monterrey',        'MX1', 80, ''], ['Tigres',          'MX1', 80, ''],
    ['Cruz Azul',        'MX1', 78, ''], ['Necaxa',          'MX1', 70, ''],

    // ── Ásia ──────────────────────────────────────────────────────────
    ['Al Hilal',         'SA1', 88, ''], ['Al Nassr',        'SA1', 86, ''],
    ['Al Ahli',          'SA1', 84, ''], ['Al Ittihad',      'SA1', 84, ''],
    ['Al Shabab',        'SA1', 76, ''],
    ['Kawasaki Frontale','JP1', 76, ''], ['Urawa Reds',      'JP1', 74, ''],
    ['Vissel Kobe',      'JP1', 75, ''], ['Machida Zelvia',  'JP1', 70, ''],
    ['Ulsan HD',         'KR1', 74, ''], ['Jeonbuk',         'KR1', 73, ''],
    ['FC Seoul',         'KR1', 71, ''],

    // ── África ────────────────────────────────────────────────────────
    ['Al Ahly',          'EG1', 78, ''], ['Zamalek',         'EG1', 75, ''],
    ['Pyramids',         'EG1', 72, ''],
    ['Wydad',            'MA1', 74, ''], ['Raja Casablanca', 'MA1', 73, ''],
    ['RS Berkane',       'MA1', 70, ''],
    ['Mamelodi Sundowns','ZA1', 74, ''], ['Orlando Pirates', 'ZA1', 70, ''],
    ['Kaizer Chiefs',    'ZA1', 69, ''],

    // ── Oceania ───────────────────────────────────────────────────────
    ['Melbourne City',   'AU1', 70, ''], ['Sydney FC',       'AU1', 69, ''],
    ['Melbourne Victory','AU1', 68, ''], ['Auckland FC',     'AU1', 64, ''],
];

/** Os clubes de uma liga. */
function coperoClubesDaLiga(string $liga): array
{
    return array_values(array_filter(COPERO_CLUBES, fn($c) => $c[1] === $liga));
}

/**
 * Os clubes que fazem sentido pra um jogador de determinado OVR.
 *
 * A faixa é aberta pra baixo e apertada pra cima: um jogador de 90 pode
 * assinar com um clube de 70 (acontece, e é escolha dele), mas um de 60 não
 * vai pro Real Madrid — senão a progressão perde a graça e todo mundo
 * termina no mesmo lugar.
 */
function coperoClubesPara(int $ovr, int $quantos = 3, array $exceto = []): array
{
    $teto = $ovr + 8;
    $piso = max(40, $ovr - 25);

    $elegiveis = array_values(array_filter(COPERO_CLUBES, function ($c) use ($teto, $piso, $exceto) {
        return $c[2] <= $teto && $c[2] >= $piso && !in_array($c[0], $exceto, true);
    }));
    if (!$elegiveis) return [];

    shuffle($elegiveis);
    return array_slice($elegiveis, 0, $quantos);
}

/** Nome e nível da liga de um clube, pra mostrar embaixo do escudo. */
function coperoLigaDoClube(string $liga): array
{
    $l = COPERO_LIGAS[$liga] ?? null;
    return $l ? ['pais' => $l[0], 'continente' => $l[1], 'nome' => $l[2], 'nivel' => $l[3]]
              : ['pais' => '?', 'continente' => '?', 'nome' => '?', 'nivel' => 1];
}
