<?php
/**
 * OS CLUBES BRASILEIROS DO JOGO DE CARREIRA.
 *
 * POR QUE UM CATÁLOGO PRÓPRIO, e não mais linhas no copero_clubes.php: o
 * Copero é uma carreira mundial, onde o jogador passa por alguns clubes de
 * cada país. Encher aquele arquivo com o Nova Mutum e o Águia de Marabá
 * desbalancearia a carreira dele — de repente metade dos clubes do mundo é do
 * interior do Brasil. Aqui, esses clubes são o ponto: sem eles não existe
 * Paranaense nem Copa Verde.
 *
 * O jogo lê OS DOIS: os grandes vêm do catálogo do Copero (que já tem força e
 * escudo calibrados) e este acrescenta o resto do Brasil.
 *
 * ── Os campos ─────────────────────────────────────────────────────────
 *
 *   nome     como aparece na tela
 *   div      'BR1' | 'BR2' | 'BR3' | '' (só disputa estadual e copas)
 *   uf       o estado, que define o estadual que ele joga
 *   forca    1 a 100, a MESMA régua do Copero — dá pra comparar um clube
 *            daqui com o River Plate sem converter nada
 *   regiao   'NE' entra na Copa do Nordeste, 'N' na Copa Verde, '' em nenhuma
 *
 * A força dos pequenos fica entre 30 e 55 de propósito: é o que faz o Palmeiras
 * (92) passear no Paulista e o estadual valer como aquecimento, não como
 * campeonato de verdade. Quando um time desses elimina um grande na Copa do
 * Brasil, é zebra — e o motor deixa acontecer, raramente.
 *
 * Nome de clube é referência factual. O jogo não é afiliado nem endossado por
 * nenhum deles e não hospeda escudo — quem tem URL usa a do Copero, quem não
 * tem aparece como monograma das iniciais.
 */

/** Os estaduais que o jogo disputa. */
const FUT_ESTADUAIS = [
    'SP' => 'Campeonato Paulista',
    'RJ' => 'Campeonato Carioca',
    'MG' => 'Campeonato Mineiro',
    'PR' => 'Campeonato Paranaense',
];

/** As copas regionais. */
const FUT_REGIONAIS = [
    'NE' => 'Copa do Nordeste',
    'N'  => 'Copa Verde',
];

/**
 * OS ESCUDOS QUE O CATÁLOGO DO COPERO NÃO TRAZ.
 *
 * O catálogo mundial já vem com o escudo dos grandes, mas quem entrou pelo
 * FUT_CLUBES_BR_EXTRA — a Série D inteira e boa parte da B e da C — não tinha
 * nenhum, e aparecia como duas letras num quadrado cinza. Numa tabela de 42
 * times da Série D isso é uma coluna inteira de quadrados iguais.
 *
 * DUAS PROCEDÊNCIAS, de propósito. Os caminhos relativos são os arquivos que o
 * dono da liga escolheu e o projeto serve; as URLs vêm da mesma base que já
 * abastece o catálogo do Copero. Quem lê não precisa saber a diferença: quem
 * resolve é futEscudoDoClube.
 *
 * O QUE AINDA FALTA está listado no fim. Não é esquecimento: são clubes que a
 * base não conhece, e mais quatro em que ela devolveu o homônimo errado — o
 * Rio Branco de Americana não é o do Acre nem o do Paraná, e a Portuguesa do
 * Rio voltou como um clube de Mato Grosso. Escudo errado é pior que escudo
 * nenhum, então esses esperam vir à mão.
 */
const FUT_ESCUDOS = [
    // ── Arquivos do projeto ──────────────────────────────────────
    'Amazonas'             => 'img/escudos/amazonas.png',
    'Athletic-MG'          => 'img/escudos/athletic-mg.png',
    'Atlético-GO'         => 'img/escudos/atletico-go.png',
    'Botafogo-SP'          => 'img/escudos/botafogo-sp.png',
    'CRB'                  => 'img/escudos/crb.png',
    'CSA'                  => 'img/escudos/csa.png',
    'Ferroviária'         => 'img/escudos/ferroviaria.png',
    'Guarani'              => 'img/escudos/guarani.png',
    'Manaus'               => 'img/escudos/manaus.png',
    'Maringá'             => 'img/escudos/maringa.png',
    'Náutico'             => 'img/escudos/nautico.png',
    'Operário-PR'         => 'img/escudos/operario-pr.png',
    'Ponte Preta'          => 'img/escudos/ponte-preta.png',
    'Portuguesa'           => 'img/escudos/portuguesa.png',
    'Sampaio Corrêa'      => 'img/escudos/sampaio-correa.png',
    'Santa Cruz'           => 'img/escudos/santa-cruz.webp',
    'São Bernardo'        => 'img/escudos/sao-bernardo.png',
    'Tombense'             => 'img/escudos/tombense.png',
    'Vitória'             => 'img/escudos/vitoria.png',
    'América-RN'          => 'img/escudos/america-rn.png',
    'Boavista-RJ'          => 'img/escudos/boavista-rj.png',
    'Paraná'              => 'img/escudos/parana.png',
    'Sergipe'              => 'img/escudos/sergipe.png',
    'Sousa'                => 'img/escudos/sousa.png',
    'São Joseense'        => 'img/escudos/sao-joseense.png',
    'São Raimundo-RR'     => 'img/escudos/sao-raimundo-rr.png',
    'Trem'                 => 'img/escudos/trem.png',
    'Treze'                => 'img/escudos/treze.webp',
    'Velo Clube'           => 'img/escudos/velo-clube.webp',
    'Villa Nova-MG'        => 'img/escudos/villa-nova-mg.webp',
    'Água Santa'          => 'img/escudos/agua-santa.png',
    'Águia de Marabá'    => 'img/escudos/aguia-de-maraba.png',
    'Portuguesa-RJ'        => 'img/escudos/portuguesa-rj.png',
    'Rio Branco-AC'        => 'img/escudos/rio-branco-ac.png',
    'Rio Branco-PR'        => 'img/escudos/rio-branco-pr.png',
    'Sampaio Corrêa-RJ'   => 'img/escudos/sampaio-correa-rj.png',
    'Uberlândia'          => 'img/escudos/uberlandia.png',

    // ── Da base que o catálogo do Copero já usa ──────────────────
    'Altos'                => 'https://r2.thesportsdb.com/images/media/team/badge/x9cimn1740845897.png',
    'Andraus'              => 'https://r2.thesportsdb.com/images/media/team/badge/6pgio61754136794.png',
    'Azuriz'               => 'https://r2.thesportsdb.com/images/media/team/badge/sy5amu1688106820.png',
    'Bangu'                => 'https://r2.thesportsdb.com/images/media/team/badge/3yrwfp1625417293.png',
    'Brasiliense'          => 'https://r2.thesportsdb.com/images/media/team/badge/8fd41g1593454003.png',
    'Caldense'             => 'https://r2.thesportsdb.com/images/media/team/badge/6yl2if1625417413.png',
    'Cianorte'             => 'https://r2.thesportsdb.com/images/media/team/badge/410zld1714066552.png',
    'Democrata'            => 'https://r2.thesportsdb.com/images/media/team/badge/swogcm1713439321.png',
    'FC Cascavel'          => 'https://r2.thesportsdb.com/images/media/team/badge/7mm0n41678204210.png',
    'Ferroviário'         => 'https://r2.thesportsdb.com/images/media/team/badge/c806qa1768450356.png',
    'Gama'                 => 'https://r2.thesportsdb.com/images/media/team/badge/dkcdrr1625418083.png',
    'Humaitá'             => 'https://r2.thesportsdb.com/images/media/team/badge/p1el131642707555.png',
    'Inter de Limeira'     => 'https://r2.thesportsdb.com/images/media/team/badge/26em5b1772177092.png',
    'Itabirito'            => 'https://r2.thesportsdb.com/images/media/team/badge/8y553y1733806140.png',
    'Juazeirense'          => 'https://r2.thesportsdb.com/images/media/team/badge/68c36e1767026288.png',
    'Madureira'            => 'https://r2.thesportsdb.com/images/media/team/badge/oy3gbu1737510151.png',
    'Maricá'              => 'https://r2.thesportsdb.com/images/media/team/badge/09p7jm1733808424.png',
    'Noroeste'             => 'https://r2.thesportsdb.com/images/media/team/badge/zgkcd81754137165.png',
    'Nova Iguaçu'         => 'https://r2.thesportsdb.com/images/media/team/badge/7fc7co1740846884.png',
    'Nova Mutum'           => 'https://r2.thesportsdb.com/images/media/team/badge/qtywlj1625422457.png',
    'Patrocinense'         => 'https://r2.thesportsdb.com/images/media/team/badge/m39wdh1736676574.png',
    'Porto Velho'          => 'https://r2.thesportsdb.com/images/media/team/badge/jzt3wf1708233908.png',
    'Pouso Alegre'         => 'https://r2.thesportsdb.com/images/media/team/badge/7kazoj1679129032.png',
    'Santo André'         => 'https://r2.thesportsdb.com/images/media/team/badge/t5flg01678205839.png',

    /* ── TROCAS DE SVG POR PNG ───────────────────────────────────
       Estes tres tinham escudo, mas em SVG — que o navegador desenha e o
       extrator de cores nao le, entao o clube ficava com o verde padrao em
       vez da cor dele. O PNG resolve os dois de uma vez.
       O Botafogo-PB e o Mainz 05 continuam em SVG de proposito: a busca so
       achou PNG do Botafogo DO RIO, que e outro clube. */
    'Nottingham Forest'     => 'https://r2.thesportsdb.com/images/media/team/badge/1i2kvh1719918076.png',
    'Midtjylland'           => 'https://r2.thesportsdb.com/images/media/team/badge/s5bpcr1755712262.png',
    'AGF'                   => 'https://r2.thesportsdb.com/images/media/team/badge/vxuuts1473535487.png',

    /* ── A EUROPA QUE FALTAVA ────────────────────────────────────
       Achados por games/core/fut_buscar_escudos_cli.php, que casa PELO
       PAÍS e não pelo nome: "Nacional" existe na Madeira, no Amazonas e
       no Uruguai, e "Rapid" existe em Viena e em Bucareste. O que não
       bateu de país ficou de fora, porque escudo errado é pior do que
       escudo nenhum — errado parece uma afirmação, e o monograma cinza
       só parece uma falta. */
    'Heidenheim'               => 'https://r2.thesportsdb.com/images/media/team/badge/lbj7g01608236988.png',
    'Bochum'                   => 'https://r2.thesportsdb.com/images/media/team/badge/kag3jy1599821108.png',
    'St. Pauli'                => 'https://r2.thesportsdb.com/images/media/team/badge/5qupxa1608237013.png',
    'Holstein Kiel'            => 'https://r2.thesportsdb.com/images/media/team/badge/1fpmgs1514394524.png',
    'Leicester'                => 'https://r2.thesportsdb.com/images/media/team/badge/xtxwtu1448813356.png',
    'Ipswich Town'             => 'https://r2.thesportsdb.com/images/media/team/badge/mdj1ey1634670785.png',
    'Las Palmas'               => 'https://r2.thesportsdb.com/images/media/team/badge/mmhyb11616443601.png',
    'Leganés'                 => 'https://r2.thesportsdb.com/images/media/team/badge/tm0adr1616443898.png',
    'Alavés'                  => 'https://r2.thesportsdb.com/images/media/team/badge/mfn99h1734673842.png',
    'Espanyol'                 => 'https://r2.thesportsdb.com/images/media/team/badge/867nzz1681703222.png',
    'Valladolid'               => 'https://r2.thesportsdb.com/images/media/team/badge/bnhu8b1719983736.png',
    'RB Salzburgo'             => 'https://r2.thesportsdb.com/images/media/team/badge/nc2cua1781541639.png',
    'Shakhtar'                 => 'https://r2.thesportsdb.com/images/media/team/badge/sqrxsr1421791799.png',
    'Dínamo Kiev'             => 'https://r2.thesportsdb.com/images/media/team/badge/ktbncx1781158762.png',
    'Royal Antwerp'            => 'https://r2.thesportsdb.com/images/media/team/badge/gawwcf1691182178.png',
    'Ferencváros'             => 'https://r2.thesportsdb.com/images/media/team/badge/wk17od1688115265.png',
    'Dínamo Zagreb'           => 'https://r2.thesportsdb.com/images/media/team/badge/zcb6f61784988620.png',
    'APOEL'                    => 'https://r2.thesportsdb.com/images/media/team/badge/j5m0pu1779579095.png',
    'Lech Poznan'              => 'https://r2.thesportsdb.com/images/media/team/badge/8zfxyx1685597440.png',
    'FCSB'                     => 'https://r2.thesportsdb.com/images/media/team/badge/123g021759420850.png',
    'Qarabag'                  => 'https://r2.thesportsdb.com/images/media/team/badge/f9h2by1725001244.png',
    'Pogon Szczecin'           => 'https://r2.thesportsdb.com/images/media/team/badge/uxyuwx1448219553.png',
    'Universitatea Craiova'    => 'https://r2.thesportsdb.com/images/media/team/badge/1jdz2y1579793710.png',
    'CFR Cluj'                 => 'https://r2.thesportsdb.com/images/media/team/badge/81uzfv1507891573.png',
    'Lillestrøm'              => 'https://r2.thesportsdb.com/images/media/team/badge/txpuvv1448823342.png',
    'HJK'                      => 'https://r2.thesportsdb.com/images/media/team/badge/z43x021775498790.png',
    'Le Havre'                 => 'https://r2.thesportsdb.com/images/media/team/badge/aikowk1546475003.png',
    'Angers'                   => 'https://r2.thesportsdb.com/images/media/team/badge/ix6q4w1678808069.png',
    'Monza'                    => 'https://r2.thesportsdb.com/images/media/team/badge/bxearg1603170113.png',
    'Lecce'                    => 'https://r2.thesportsdb.com/images/media/team/badge/j4vznr1567365249.png',
    'Parma'                    => 'https://r2.thesportsdb.com/images/media/team/badge/6yiaxs1627406063.png',
    'Hellas Verona'            => 'https://r2.thesportsdb.com/images/media/team/badge/p6camf1593457737.png',
    'Empoli'                   => 'https://r2.thesportsdb.com/images/media/team/badge/c1ie6b1622561483.png',
    'Casa Pia'                 => 'https://r2.thesportsdb.com/images/media/team/badge/d8ugsc1678717133.png',
    'Arouca'                   => 'https://r2.thesportsdb.com/images/media/team/badge/vgbzjq1628853833.png',
    'Gil Vicente'              => 'https://r2.thesportsdb.com/images/media/team/badge/88bsg41626201503.png',
    'Estoril'                  => 'https://r2.thesportsdb.com/images/media/team/badge/lq9h8m1628854051.png',
    'Moreirense'               => 'https://r2.thesportsdb.com/images/media/team/badge/yrquxu1471878153.png',
    'Estrela da Amadora'       => 'https://r2.thesportsdb.com/images/media/team/badge/2tx5621626095555.png',
    'Farense'                  => 'https://r2.thesportsdb.com/images/media/team/badge/0a09qc1690493592.png',
    'Santa Clara'              => 'https://r2.thesportsdb.com/images/media/team/badge/7337a61724860742.png',
    'AVS'                      => 'https://r2.thesportsdb.com/images/media/team/badge/xp8oqb1688676544.png',
];

/**
 * Os clubes que NÃO estão no catálogo do Copero.
 *
 * Os das Séries A e B que já existem lá não se repetem aqui — quem junta os
 * dois é futClubesDoBrasil(), e clube repetido viraria dois times com o mesmo
 * nome na mesma tabela.
 *
 * [nome, divisão, UF, força, região]
 */
const FUT_CLUBES_BR_EXTRA = [
    // ── Completam a Série A ──────────────────────────────────────────
    ['Vitória',            'BR1', 'BA', 76, 'NE'],
    ['Santos',             'BR1', 'SP', 76, ''],      // sobe da B do catálogo

    // ── Completam a Série B ──────────────────────────────────────────
    ['Atlético-GO',        'BR2', 'GO', 66, 'N'],
    ['Ponte Preta',        'BR2', 'SP', 62, ''],
    ['CRB',                'BR2', 'AL', 62, 'NE'],
    ['Operário-PR',        'BR2', 'PR', 61, ''],
    ['Botafogo-SP',        'BR2', 'SP', 60, ''],
    ['Amazonas',           'BR2', 'AM', 59, 'N'],
    ['Athletic-MG',        'BR2', 'MG', 58, ''],
    ['Ferroviária',        'BR2', 'SP', 58, ''],
    ['Náutico',            'BR2', 'PE', 60, 'NE'],
    ['Paysandu',           'BR2', 'PA', 58, 'N'],   // sobe da C do catálogo

    // ── Paulista ─────────────────────────────────────────────────────
    ['Guarani',            'BR3', 'SP', 55, ''],
    ['Portuguesa',         'BR3', 'SP', 52, ''],
    ['São Bernardo',       'BR3', 'SP', 50, ''],
    ['Inter de Limeira',   'BR4', 'SP', 45, ''],
    ['Água Santa',         'BR4', 'SP', 46, ''],
    ['Velo Clube',         'BR4', 'SP', 42, ''],
    ['Noroeste',           'BR4', 'SP', 41, ''],
    ['Santo André',        'BR4', 'SP', 44, ''],

    // ── Carioca ──────────────────────────────────────────────────────
    ['Bangu',              'BR4', 'RJ', 44, ''],
    ['Madureira',          'BR4', 'RJ', 43, ''],
    ['Nova Iguaçu',        'BR4', 'RJ', 46, ''],
    ['Portuguesa-RJ',      'BR4', 'RJ', 42, ''],
    ['Boavista-RJ',        'BR4', 'RJ', 43, ''],
    ['Sampaio Corrêa-RJ',  'BR4', 'RJ', 40, ''],
    ['Maricá',             'BR4', 'RJ', 45, ''],

    // ── Mineiro ──────────────────────────────────────────────────────
    ['Tombense',           'BR3', 'MG', 52, ''],
    ['Villa Nova-MG',      'BR4', 'MG', 43, ''],
    ['Democrata',          'BR4', 'MG', 39, ''],
    ['Itabirito',          'BR4', 'MG', 42, ''],
    ['Pouso Alegre',       'BR4', 'MG', 44, ''],
    ['Uberlândia',         'BR4', 'MG', 43, ''],
    ['Caldense',           'BR4', 'MG', 41, ''],
    ['Patrocinense',       'BR4', 'MG', 38, ''],

    // ── Paranaense ───────────────────────────────────────────────────
    ['Maringá',            'BR3', 'PR', 51, ''],
    ['Cianorte',           'BR4', 'PR', 44, ''],
    ['Azuriz',             'BR4', 'PR', 41, ''],
    ['FC Cascavel',        'BR4', 'PR', 45, ''],
    ['Rio Branco-PR',      'BR4', 'PR', 38, ''],
    ['Paraná',             'BR4', 'PR', 39, ''],
    ['São Joseense',       'BR4', 'PR', 40, ''],
    ['Andraus',            'BR4', 'PR', 36, ''],

    // ── Nordeste (Copa do Nordeste) ──────────────────────────────────
    ['Santa Cruz',         'BR3', 'PE', 54, 'NE'],
    ['CSA',                'BR3', 'AL', 53, 'NE'],
    ['América-RN',         'BR4', 'RN', 45, 'NE'],
    ['Sampaio Corrêa',     'BR3', 'MA', 52, 'NE'],
    ['Altos',              'BR4', 'PI', 42, 'NE'],
    ['Ferroviário',        'BR4', 'CE', 46, 'NE'],
    ['Sousa',              'BR4', 'PB', 40, 'NE'],
    ['Juazeirense',        'BR4', 'BA', 43, 'NE'],
    ['Sergipe',            'BR4', 'SE', 41, 'NE'],
    ['Treze',              'BR4', 'PB', 40, 'NE'],

    // ── Norte e Centro-Oeste (Copa Verde) ────────────────────────────
    ['Brasiliense',        'BR4', 'DF', 46, 'N'],
    ['Gama',               'BR4', 'DF', 44, 'N'],
    ['Manaus',             'BR3', 'AM', 48, 'N'],
    ['Águia de Marabá',    'BR4', 'PA', 42, 'N'],
    ['Porto Velho',        'BR4', 'RO', 38, 'N'],
    ['Rio Branco-AC',      'BR4', 'AC', 37, 'N'],
    ['Nova Mutum',         'BR4', 'MT', 40, 'N'],
    ['Trem',               'BR4', 'AP', 36, 'N'],
    ['São Raimundo-RR',    'BR4', 'RR', 36, 'N'],
    ['Humaitá',            'BR4', 'AC', 35, 'N'],
];

/**
 * A UF e a região dos clubes que vêm do catálogo do Copero.
 *
 * Aquele arquivo não tem estado — ele é mundial, e estado só importa aqui. Sem
 * este mapa, Palmeiras e Flamengo não teriam estadual pra disputar.
 */
const FUT_UF_DO_COPERO = [
    'Palmeiras'      => ['SP', ''],   'Flamengo'      => ['RJ', ''],
    'Corinthians'    => ['SP', ''],   'Fluminense'    => ['RJ', ''],
    'São Paulo'      => ['SP', ''],   'Botafogo'      => ['RJ', ''],
    'RB Bragantino'  => ['SP', ''],   'Vasco da Gama' => ['RJ', ''],
    'Mirassol'       => ['SP', ''],   'Atlético-MG'   => ['MG', ''],
    'Novorizontino'  => ['SP', ''],   'Cruzeiro'      => ['MG', ''],
    'Athletico-PR'   => ['PR', ''],   'América-MG'    => ['MG', ''],
    'Coritiba'       => ['PR', ''],   'Grêmio'        => ['RS', ''],
    'Londrina'       => ['PR', ''],   'Internacional' => ['RS', ''],
    'Juventude'      => ['RS', ''],   'Bahia'         => ['BA', 'NE'],
    'Chapecoense'    => ['SC', ''],   'Sport Recife'  => ['PE', 'NE'],
    'Criciúma'       => ['SC', ''],   'Ceará'         => ['CE', 'NE'],
    'Avaí'           => ['SC', ''],   'Fortaleza'     => ['CE', 'NE'],
    'Figueirense'    => ['SC', ''],   'ABC'           => ['RN', 'NE'],
    'Cuiabá'         => ['MT', 'N'],  'Botafogo-PB'   => ['PB', 'NE'],
    'Goiás'          => ['GO', 'N'],  'Paysandu'      => ['PA', 'N'],
    'Vila Nova'      => ['GO', 'N'],  'Remo'          => ['PA', 'N'],
    'Volta Redonda'  => ['RJ', ''],   'Ypiranga'      => ['RS', ''],
];

/**
 * A força que um elenco REAL dá ao clube, se ele tiver um.
 *
 * O número do catálogo é um palpite meu; o elenco real é o time de verdade.
 * Quando os dois discordam, quem manda é o elenco — foi assim que apareceu
 * que o Remo estava no catálogo como Série C (força 55) com um elenco que
 * vale 71. Sem isto, o clube jogaria como time de 71 e receberia dinheiro de
 * time de 55, com uma meta de time de 55.
 *
 * O resultado fica em cache: futClubesDoBrasil() é chamada muitas vezes por
 * requisição, e ler 25 arquivos em cada uma seria desperdício puro.
 */
function futForcaDoElencoReal(string $clube): ?int
{
    static $cache = [];
    if (array_key_exists($clube, $cache)) return $cache[$clube];

    $arq = __DIR__ . '/../data/elencos/' . futSlugDoClube($clube) . '.php';
    if (!is_file($arq)) return $cache[$clube] = null;

    $lista = require $arq;
    if (!is_array($lista) || $lista === []) return $cache[$clube] = null;

    require_once __DIR__ . '/fut_elencos.php';
    return $cache[$clube] = futForcaDoElenco($lista);
}

/**
 * TODOS os clubes brasileiros do jogo: os do Copero mais os daqui.
 *
 * @return array nome => ['nome','div','uf','forca','regiao','escudo']
 */
function futClubesDoBrasil(): array
{
    require_once __DIR__ . '/copero_clubes.php';

    $out = [];
    foreach (COPERO_CLUBES as $c) {
        if (!in_array($c[1], ['BR1', 'BR2', 'BR3'], true)) continue;
        [$uf, $regiao] = FUT_UF_DO_COPERO[$c[0]] ?? ['', ''];
        $out[$c[0]] = ['nome' => $c[0], 'div' => $c[1], 'uf' => $uf,
                       'forca' => (int)$c[2], 'regiao' => $regiao, 'escudo' => $c[3] ?? ''];
    }

    /* Os daqui ENTRAM POR CIMA: o Santos está como BR2 no Copero e como BR1
       aqui, e é esta divisão que vale no jogo. Sobrescrever em vez de pular
       é o que deixa a lista de cá ser a fonte da verdade da divisão. */
    foreach (FUT_CLUBES_BR_EXTRA as [$nome, $div, $uf, $forca, $regiao]) {
        $escudo = $out[$nome]['escudo'] ?? '';
        $out[$nome] = ['nome' => $nome, 'div' => $div, 'uf' => $uf,
                       'forca' => $forca, 'regiao' => $regiao, 'escudo' => $escudo];
    }

    /* ONDE HÁ ELENCO REAL, ELE MANDA na força. O número do catálogo continua
       servindo pra quem não tem lista — e é ele que gera o elenco fictício
       desses clubes, então não pode sair daqui. */
    // O escudo de quem não tem no catálogo do Copero (@see FUT_ESCUDOS).
    foreach ($out as $nome => $c) {
        $out[$nome]['escudo'] = futEscudoDoClube($nome, (string)($c['escudo'] ?? ''));
    }

    foreach ($out as $nome => $c) {
        $real = futForcaDoElencoReal($nome);
        if ($real !== null) $out[$nome]['forca'] = $real;
    }

    return $out;
}

/**
 * O ESCUDO DE UM CLUBE, venha ele de onde vier.
 *
 * O caminho relativo é resolvido a partir de games/, que é onde a tela mora.
 * Assim FUT_ESCUDOS guarda 'img/escudos/bangu.png' e não um caminho absoluto
 * que quebraria se o jogo mudar de pasta.
 */
function futEscudoDoClube(string $nome, string $doCatalogo = ''): string
{
    $meu = FUT_ESCUDOS[$nome] ?? '';
    if ($meu !== '') {
        return str_starts_with($meu, 'http') ? $meu : '../' . $meu;
    }
    return $doCatalogo;
}

/** Os clubes de uma divisão nacional. */
function futClubesDaDivisao(string $div): array
{
    return array_filter(futClubesDoBrasil(), fn($c) => $c['div'] === $div);
}

/** Os clubes de um estadual. */
function futClubesDoEstado(string $uf): array
{
    return array_filter(futClubesDoBrasil(), fn($c) => $c['uf'] === $uf);
}

/** Os clubes de uma copa regional (NE = Nordeste, N = Verde). */
function futClubesDaRegiao(string $regiao): array
{
    return array_filter(futClubesDoBrasil(), fn($c) => $c['regiao'] === $regiao);
}
