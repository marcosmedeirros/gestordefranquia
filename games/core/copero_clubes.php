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
    'IR1'  => ['IRN', 'ASI', 'Persian Gulf Pro League', 1, 70],
    'QA1'  => ['QAT', 'ASI', 'Qatar Stars League',  1, 70],

    // ── África ────────────────────────────────────────────────────────
    'EG1'  => ['EGY', 'AFR', 'Premier League',      1, 70],
    'MA1'  => ['MAR', 'AFR', 'Botola Pro',          1, 68],
    'ZA1'  => ['RSA', 'AFR', 'Premiership',         1, 66],
    'DZ1'  => ['ALG', 'AFR', 'Ligue 1',             1, 68],
    'TN1'  => ['TUN', 'AFR', 'Ligue 1',             1, 68],
    'NG1'  => ['NGA', 'AFR', 'NPFL',                1, 62],
    'CD1'  => ['COD', 'AFR', 'Linafoot',            1, 64],

    // A Austrália joga na Ásia, como na vida real desde 2007: com só quatro
    // clubes, a Oceania virava uma Champions que se ganha em nove de cada dez anos.
    'AU1'  => ['AUS', 'ASI', 'A-League',            1, 68],

    /* ══ AS TRINTA LIGAS NOVAS ═════════════════════════════════════════
     *
     * O mundo tinha 41 ligas em 32 países, e as seleções eram 62 — metade
     * do jogo era gente que disputava a Copa e não tinha onde jogar. Isso
     * deixava buracos de verdade: a Croácia, o Senegal e a Costa do Marfim
     * eram nacionalidades ESCOLHÍVEIS sem uma liga em casa, então o garoto
     * nascia sem clube do próprio país e caía direto na Europa aos 16.
     *
     * As forças médias não são chute: são a régua do próprio catálogo, onde
     * a Premier é 96 e a Série C brasileira é 54. Uma liga que vale 56 não
     * é enfeite — é o degrau que faltava embaixo, e é ele que faz a carreira
     * de quem não vira craque ter para onde ir.
     */

    // ── América do Sul: a CONMEBOL inteira ────────────────────────────
    'EC1'  => ['ECU', 'SAM', 'LigaPro',             1, 66],
    'PY1'  => ['PAR', 'SAM', 'Primera División',    1, 64],
    'PE1'  => ['PER', 'SAM', 'Liga 1',              1, 64],
    'VE1'  => ['VEN', 'SAM', 'Liga FUTVE',          1, 58],
    'BO1'  => ['BOL', 'SAM', 'División Profesional',1, 56],

    // ── Europa ────────────────────────────────────────────────────────
    'HR1'  => ['CRO', 'EUR', 'HNL',                 1, 72],
    'CH1'  => ['SUI', 'EUR', 'Super League',        1, 72],
    'AT1'  => ['AUT', 'EUR', 'Bundesliga Austríaca',1, 72],
    'UA1'  => ['UKR', 'EUR', 'Premier Liha',        1, 70],
    'DK1'  => ['DEN', 'EUR', 'Superliga',           1, 70],
    'RS1'  => ['SRB', 'EUR', 'Superliga Sérvia',    1, 68],
    'PL1'  => ['POL', 'EUR', 'Ekstraklasa',         1, 68],
    'CZ1'  => ['CZE', 'EUR', 'Chance Liga',         1, 68],
    'NO1'  => ['NOR', 'EUR', 'Eliteserien',         1, 66],
    'SE1'  => ['SWE', 'EUR', 'Allsvenskan',         1, 66],
    // Segundas divisões onde a primeira já existia: é degrau, e degrau é o
    // que faz a queda ser queda e a subida ser subida.
    'PT2'  => ['POR', 'EUR', 'Liga Portugal 2',     2, 62],
    'NL2'  => ['NED', 'EUR', 'Eerste Divisie',      2, 62],

    // ── Ásia ──────────────────────────────────────────────────────────
    'CN1'  => ['CHN', 'ASI', 'Chinese Super League',1, 66],
    'UZ1'  => ['UZB', 'ASI', 'Superliga Uzbeque',   1, 58],
    'IQ1'  => ['IRQ', 'ASI', 'Stars League',        1, 58],

    // ── África ────────────────────────────────────────────────────────
    'GH1'  => ['GHA', 'AFR', 'Premier League Ganesa',1, 58],
    'SN1'  => ['SEN', 'AFR', 'Ligue 1 Senegalesa',  1, 56],
    'CI1'  => ['CIV', 'AFR', 'Ligue 1 Marfinense',  1, 56],
    'CM1'  => ['CMR', 'AFR', 'Elite One',           1, 54],
    'ML1'  => ['MLI', 'AFR', 'Première Division',   1, 54],

    // ── América do Norte e Central ────────────────────────────────────
    'CR1'  => ['CRC', 'NAM', 'Liga Promerica',      1, 62],
    'CA1'  => ['CAN', 'NAM', 'Canadian Premier',    1, 58],
    'HN1'  => ['HON', 'NAM', 'Liga Nacional',       1, 56],
    'PA1'  => ['PAN', 'NAM', 'LPF Panamá',          1, 54],
    'JM1'  => ['JAM', 'NAM', 'Jamaica Premier',     1, 52],
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

    // Brasileirão Série A
    ['Palmeiras', 'BR1', 92, 'https://r2.thesportsdb.com/images/media/team/badge/vsqwqp1473538105.png'],
    ['Flamengo', 'BR1', 92, 'https://r2.thesportsdb.com/images/media/team/badge/syptwx1473538074.png'],
    ['Atlético-MG', 'BR1', 86, 'https://r2.thesportsdb.com/images/media/team/badge/x5lixs1743742872.png'],
    ['Fluminense', 'BR1', 84, 'https://r2.thesportsdb.com/images/media/team/badge/stvvwp1473538082.png'],
    ['São Paulo', 'BR1', 84, 'https://r2.thesportsdb.com/images/media/team/badge/sxpupx1473538135.png'],
    ['Internacional', 'BR1', 83, 'https://r2.thesportsdb.com/images/media/team/badge/yprvxx1473538097.png'],
    ['Grêmio', 'BR1', 83, 'https://r2.thesportsdb.com/images/media/team/badge/uvpwyt1473538089.png'],
    ['Corinthians', 'BR1', 82, 'https://r2.thesportsdb.com/images/media/team/badge/vvuvps1473538042.png'],
    ['Botafogo', 'BR1', 84, 'https://r2.thesportsdb.com/images/media/team/badge/bs5mbw1733004596.png'],
    ['Cruzeiro', 'BR1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/upsvvu1473538059.png'],
    ['Athletico-PR', 'BR1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/irzu1u1554237406.png'],
    ['Fortaleza', 'BR1', 79, 'https://r2.thesportsdb.com/images/media/team/badge/tosmdr1532853458.png'],
    ['RB Bragantino', 'BR1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/sqwrcu1646600551.png'],
    ['Bahia', 'BR1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/xuvtsv1473539308.png'],
    ['Vasco da Gama', 'BR1', 77, 'https://r2.thesportsdb.com/images/media/team/badge/ynqlxo1630521109.png'],
    ['Cuiabá', 'BR1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/ykbxfa1766506334.png'],

    // Brasileirão Série B
    ['Santos', 'BR2', 74, 'https://r2.thesportsdb.com/images/media/team/badge/j8xk9g1679447486.png'],
    ['Sport Recife', 'BR2', 70, 'https://r2.thesportsdb.com/images/media/team/badge/tyrbls1545421563.png'],
    ['Ceará', 'BR2', 68, 'https://r2.thesportsdb.com/images/media/team/badge/rxxvyp1464886685.png'],
    ['Goiás', 'BR2', 67, 'https://r2.thesportsdb.com/images/media/team/badge/qhfhdp1635869930.png'],
    ['Coritiba', 'BR2', 66, 'https://r2.thesportsdb.com/images/media/team/badge/ywwsyu1473538050.png'],
    ['Avaí', 'BR2', 62, 'https://r2.thesportsdb.com/images/media/team/badge/bblkat1766506007.png'],

    // Brasileirão Série C
    ['Paysandu', 'BR3', 56, 'https://r2.thesportsdb.com/images/media/team/badge/9rrdrn1740851540.png'],
    ['Remo', 'BR3', 55, 'https://r2.thesportsdb.com/images/media/team/badge/u36jfy1579341655.png'],
    ['Volta Redonda', 'BR3', 52, 'https://r2.thesportsdb.com/images/media/team/badge/phc5fs1736379773.png'],
    ['Ypiranga', 'BR3', 50, 'https://r2.thesportsdb.com/images/media/team/badge/ryok221688146977.png'],

    // Liga Profesional
    ['River Plate', 'AR1', 88, 'https://r2.thesportsdb.com/images/media/team/badge/03dmi31645539717.png'],
    ['Boca Juniors', 'AR1', 87, 'https://r2.thesportsdb.com/images/media/team/badge/bm7krb1775741582.png'],
    ['Racing', 'AR1', 82, 'https://r2.thesportsdb.com/images/media/team/badge/vi4mu41695734959.png'],
    ['San Lorenzo', 'AR1', 79, '/games/img/escudos/sanlorenzo.png'],
    ['Independiente', 'AR1', 79, 'https://r2.thesportsdb.com/images/media/team/badge/eki4nd1580842605.png'],
    ['Estudiantes', 'AR1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/pf08dq1760634366.png'],
    ['Vélez Sarsfield', 'AR1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/jo98m71517769587.png'],
    ['Talleres', 'AR1', 79, 'https://r2.thesportsdb.com/images/media/team/badge/7hum2t1769310938.png'],
    ['Rosario Central', 'AR1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/y6q1ds1769660256.png'],
    ['Newell\'s', 'AR1', 77, 'https://r2.thesportsdb.com/images/media/team/badge/23aftf1580842633.png'],
    ['Belgrano', 'AR1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/0twgzi1517768087.png'],
    ['Godoy Cruz', 'AR1', 73, 'https://r2.thesportsdb.com/images/media/team/badge/d3c0ds1517768584.png'],
    ['Lanús', 'AR1', 75, 'https://r2.thesportsdb.com/images/media/team/badge/ddty0w1769146364.png'],
    ['Barracas Central', 'AR1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/rbkjba1707458543.png'],

    // Primera Nacional
    ['Gimnasia (M)', 'AR2', 62, 'https://r2.thesportsdb.com/images/media/team/badge/epmttr1678849271.png'],
    ['Colón', 'AR2', 64, 'https://r2.thesportsdb.com/images/media/team/badge/l72gz21712750680.png'],
    ['Temperley', 'AR2', 58, 'https://r2.thesportsdb.com/images/media/team/badge/48yf6p1517769378.png'],
    ['Almirante Brown', 'AR2', 57, 'https://r2.thesportsdb.com/images/media/team/badge/81i8mf1615822788.png'],

    // Primera División
    ['Peñarol', 'UY1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/uuwpux1473541171.png'],
    ['Nacional', 'UY1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/yxi2wi1736387630.png'],
    ['Defensor', 'UY1', 64, 'https://r2.thesportsdb.com/images/media/team/badge/dx13rd1703003044.png'],

    // Primera División
    ['Colo-Colo', 'CL1', 72, '/games/img/escudos/colocolo.png'],
    ['Universidad de Chile', 'CL1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/po0o3c1578347873.png'],
    ['Cobresal', 'CL1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/36tqoo1602187477.png'],

    // Categoría Primera A
    ['Atlético Nacional', 'CO1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/efl4o41576157720.png'],
    ['Millonarios', 'CO1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/mc73ix1629122587.png'],
    ['Junior', 'CO1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/zzarls1576161686.png'],

    // Premier League
    ['Manchester City', 'EN1', 99, 'https://r2.thesportsdb.com/images/media/team/badge/vwpvry1467462651.png'],
    ['Liverpool', 'EN1', 97, 'https://r2.thesportsdb.com/images/media/team/badge/kfaher1737969724.png'],
    ['Arsenal', 'EN1', 96, 'https://r2.thesportsdb.com/images/media/team/badge/uyhbfe1612467038.png'],
    ['Manchester United', 'EN1', 92, 'https://r2.thesportsdb.com/images/media/team/badge/xzqdr11517660252.png'],
    ['Chelsea', 'EN1', 92, 'https://r2.thesportsdb.com/images/media/team/badge/pbf4ul1782638263.png'],
    ['Tottenham', 'EN1', 90, 'https://r2.thesportsdb.com/images/media/team/badge/3dhd0j1605371995.png'],
    ['Newcastle', 'EN1', 89, '/games/img/escudos/newcastle.png'],
    ['Aston Villa', 'EN1', 88, 'https://r2.thesportsdb.com/images/media/team/badge/cgvxt61785930389.png'],
    ['Brighton', 'EN1', 85, 'https://r2.thesportsdb.com/images/media/team/badge/ywypts1448810904.png'],
    ['West Ham', 'EN1', 84, 'https://r2.thesportsdb.com/images/media/team/badge/hfum4l1599931799.png'],
    ['Everton', 'EN1', 82, 'https://r2.thesportsdb.com/images/media/team/badge/eqayrf1523184794.png'],
    ['Fulham', 'EN1', 82, 'https://r2.thesportsdb.com/images/media/team/badge/xwwvyt1448811086.png'],
    ['Crystal Palace', 'EN1', 81, 'https://r2.thesportsdb.com/images/media/team/badge/ia6i3m1656014992.png'],
    ['Wolves', 'EN1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/16posu1727839976.png'],

    // Championship
    ['Leeds United', 'EN2', 76, 'https://r2.thesportsdb.com/images/media/team/badge/jcgrml1756649030.png'],
    ['Southampton', 'EN2', 75, 'https://r2.thesportsdb.com/images/media/team/badge/ggqtd01621593274.png'],
    ['Norwich City', 'EN2', 72, 'https://r2.thesportsdb.com/images/media/team/badge/pabczm1679951464.png'],
    ['Sunderland', 'EN2', 72, 'https://r2.thesportsdb.com/images/media/team/badge/tprtus1448813498.png'],
    ['Sheffield United', 'EN2', 71, 'https://r2.thesportsdb.com/images/media/team/badge/w7f8pj1672950689.png'],
    ['Watford', 'EN2', 70, 'https://r2.thesportsdb.com/images/media/team/badge/rsuswy1448813519.png'],

    // League One
    ['Bolton', 'EN3', 58, 'https://r2.thesportsdb.com/images/media/team/badge/yvxxrv1448808301.png'],
    ['Charlton', 'EN3', 57, 'https://r2.thesportsdb.com/images/media/team/badge/o08wvi1635872307.png'],
    ['Barnsley', 'EN3', 55, 'https://r2.thesportsdb.com/images/media/team/badge/glbmdm1781719675.png'],
    ['Wigan', 'EN3', 55, 'https://r2.thesportsdb.com/images/media/team/badge/wtxwyw1448759640.png'],

    // LaLiga
    ['Real Madrid', 'ES1', 99, 'https://r2.thesportsdb.com/images/media/team/badge/vwvwrw1473502969.png'],
    ['Barcelona', 'ES1', 97, 'https://r2.thesportsdb.com/images/media/team/badge/wq9sir1639406443.png'],
    ['Atlético de Madrid', 'ES1', 93, 'https://r2.thesportsdb.com/images/media/team/badge/0ulh3q1719984315.png'],
    ['Athletic Club', 'ES1', 88, 'https://r2.thesportsdb.com/images/media/team/badge/68w7fe1639408210.png'],
    ['Real Sociedad', 'ES1', 87, 'https://r2.thesportsdb.com/images/media/team/badge/vptvpr1473502986.png'],
    ['Villarreal', 'ES1', 86, 'https://r2.thesportsdb.com/images/media/team/badge/vrypqy1473503073.png'],
    ['Real Betis', 'ES1', 85, 'https://r2.thesportsdb.com/images/media/team/badge/2oqulv1663245386.png'],
    ['Sevilla', 'ES1', 85, 'https://r2.thesportsdb.com/images/media/team/badge/vpsqqx1473502977.png'],
    ['Valencia', 'ES1', 83, 'https://r2.thesportsdb.com/images/media/team/badge/dm8l6o1655594864.png'],
    ['Celta de Vigo', 'ES1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/xfjtku1690436219.png'],
    ['Getafe', 'ES1', 79, 'https://r2.thesportsdb.com/images/media/team/badge/eyh2891655594452.png'],
    ['Osasuna', 'ES1', 79, 'https://r2.thesportsdb.com/images/media/team/badge/rvspvt1473502960.png'],

    // LaLiga 2
    ['Real Zaragoza', 'ES2', 66, 'https://r2.thesportsdb.com/images/media/team/badge/sxpwxs1473503702.png'],
    ['Sporting Gijón', 'ES2', 65, 'https://r2.thesportsdb.com/images/media/team/badge/oom09m1578831384.png'],
    ['Racing Santander', 'ES2', 64, 'https://r2.thesportsdb.com/images/media/team/badge/97kkiq1536575158.png'],
    ['Eibar', 'ES2', 64, 'https://r2.thesportsdb.com/images/media/team/badge/hccive1680933599.png'],

    // Serie A
    ['Inter de Milão', 'IT1', 95, 'https://r2.thesportsdb.com/images/media/team/badge/ryhu6d1617113103.png'],
    ['Juventus', 'IT1', 93, 'https://r2.thesportsdb.com/images/media/team/badge/uxf0gr1742983727.png'],
    ['AC Milan', 'IT1', 92, 'https://r2.thesportsdb.com/images/media/team/badge/wvspur1448806617.png'],
    ['Napoli', 'IT1', 91, 'https://r2.thesportsdb.com/images/media/team/badge/l8qyxv1742982541.png'],
    ['Roma', 'IT1', 88, 'https://r2.thesportsdb.com/images/media/team/badge/jwro2s1760820674.png'],
    ['Atalanta', 'IT1', 88, 'https://r2.thesportsdb.com/images/media/team/badge/qix5ku1780561327.png'],
    ['Lazio', 'IT1', 86, 'https://r2.thesportsdb.com/images/media/team/badge/rwqyvs1448806608.png'],
    ['Fiorentina', 'IT1', 85, 'https://r2.thesportsdb.com/images/media/team/badge/hc8nhu1656098030.png'],
    ['Bologna', 'IT1', 83, 'https://r2.thesportsdb.com/images/media/team/badge/2qi1u31655592366.png'],
    ['Torino', 'IT1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/xxprty1448806802.png'],
    ['Udinese', 'IT1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/vwvstr1448806811.png'],
    ['Venezia', 'IT1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/vbiget1781026964.png'],

    // Serie B
    ['Sampdoria', 'IT2', 66, 'https://r2.thesportsdb.com/images/media/team/badge/pr6co21655592769.png'],
    ['Palermo', 'IT2', 65, 'https://r2.thesportsdb.com/images/media/team/badge/zi1tb01579708939.png'],
    ['Bari', 'IT2', 62, 'https://r2.thesportsdb.com/images/media/team/badge/isfrtg1579724972.png'],
    ['Avellino', 'IT2', 58, 'https://r2.thesportsdb.com/images/media/team/badge/swsu5p1579724964.png'],

    // Bundesliga
    ['Bayern de Munique', 'DE1', 98, 'https://r2.thesportsdb.com/images/media/team/badge/01ogkh1716960412.png'],
    ['Bayer Leverkusen', 'DE1', 93, 'https://r2.thesportsdb.com/images/media/team/badge/3x9k851726760113.png'],
    ['Borussia Dortmund', 'DE1', 91, 'https://r2.thesportsdb.com/images/media/team/badge/tqo8ge1716960353.png'],
    ['RB Leipzig', 'DE1', 90, 'https://r2.thesportsdb.com/images/media/team/badge/zjgapo1594244951.png'],
    ['Stuttgart', 'DE1', 85, 'https://r2.thesportsdb.com/images/media/team/badge/yppyux1473454085.png'],
    ['Eintracht Frankfurt', 'DE1', 85, 'https://r2.thesportsdb.com/images/media/team/badge/rurwpy1473453269.png'],
    ['Wolfsburg', 'DE1', 82, 'https://r2.thesportsdb.com/images/media/team/badge/ci9trv1778399557.png'],
    ['Werder Bremen', 'DE1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/tkvqan1716960454.png'],
    ['Borussia M\'gladbach', 'DE1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/rn6vsx1580141896.png'],
    ['Union Berlin', 'DE1', 79, 'https://r2.thesportsdb.com/images/media/team/badge/q0o5001599679795.png'],

    // 2. Bundesliga
    ['Hamburgo', 'DE2', 68, '/games/img/escudos/hamburgo.svg'],
    ['Schalke 04', 'DE2', 68, 'https://r2.thesportsdb.com/images/media/team/badge/hnci291621593978.png'],
    ['Hertha Berlim', 'DE2', 67, 'https://r2.thesportsdb.com/images/media/team/badge/aszcmz1782623817.png'],
    ['Kaiserslautern', 'DE2', 62, 'https://r2.thesportsdb.com/images/media/team/badge/jghax31740165513.png'],

    // Ligue 1
    ['PSG', 'FR1', 96, '/games/img/escudos/psg.png'],
    ['Monaco', 'FR1', 88, 'https://r2.thesportsdb.com/images/media/team/badge/exjf5l1678808044.png'],
    ['Marseille', 'FR1', 87, 'https://r2.thesportsdb.com/images/media/team/badge/c6bazh1779212287.png'],
    ['Lyon', 'FR1', 85, 'https://r2.thesportsdb.com/images/media/team/badge/blk9771656932845.png'],
    ['Lille', 'FR1', 85, 'https://r2.thesportsdb.com/images/media/team/badge/2giize1534005340.png'],
    ['Nice', 'FR1', 83, 'https://r2.thesportsdb.com/images/media/team/badge/msy7ly1621593859.png'],
    ['Rennes', 'FR1', 83, 'https://r2.thesportsdb.com/images/media/team/badge/ypturx1473504818.png'],
    ['Lens', 'FR1', 82, 'https://r2.thesportsdb.com/images/media/team/badge/3pxoum1598797195.png'],
    ['Nantes', 'FR1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/mla9x61678808018.png'],
    ['Strasbourg', 'FR1', 77, 'https://r2.thesportsdb.com/images/media/team/badge/b8k77w1766625501.png'],

    // Ligue 2
    ['Saint-Étienne', 'FR2', 66, 'https://r2.thesportsdb.com/images/media/team/badge/m4ej831656423694.png'],
    ['Bordeaux', 'FR2', 65, 'https://r2.thesportsdb.com/images/media/team/badge/u45vc51627132724.png'],
    ['Metz', 'FR2', 62, 'https://r2.thesportsdb.com/images/media/team/badge/1iuew61688452857.png'],
    ['Caen', 'FR2', 58, 'https://r2.thesportsdb.com/images/media/team/badge/416kon1784484564.png'],

    // Primeira Liga
    ['Benfica', 'PT1', 87, 'https://r2.thesportsdb.com/images/media/team/badge/hj4kyc1781152436.png'],
    ['Porto', 'PT1', 86, 'https://r2.thesportsdb.com/images/media/team/badge/xu47rb1628855600.png'],
    ['Sporting CP', 'PT1', 87, 'https://r2.thesportsdb.com/images/media/team/badge/5hiuk71783137875.png'],
    ['Braga', 'PT1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/skbiwo1785775946.png'],
    ['Vitória de Guimarães', 'PT1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/af52z61628855707.png'],

    // Eredivisie
    ['Ajax', 'NL1', 84, 'https://r2.thesportsdb.com/images/media/team/badge/zg9tii1755495289.png'],
    ['PSV', 'NL1', 85, 'https://r2.thesportsdb.com/images/media/team/badge/xfsz6i1721297428.png'],
    ['Feyenoord', 'NL1', 84, 'https://r2.thesportsdb.com/images/media/team/badge/uturtx1473534803.png'],
    ['AZ Alkmaar', 'NL1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/wtqwvv1473534757.png'],
    ['Twente', 'NL1', 75, 'https://r2.thesportsdb.com/images/media/team/badge/rsrxrt1473534783.png'],

    // Pro League
    ['Club Brugge', 'BE1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/mz8y0q1771129880.png'],
    ['Anderlecht', 'BE1', 77, 'https://r2.thesportsdb.com/images/media/team/badge/auindn1771129464.png'],
    ['Genk', 'BE1', 76, 'https://r2.thesportsdb.com/images/media/team/badge/tp06te1534875918.png'],
    ['Standard Liège', 'BE1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/0ynlvb1771130035.png'],

    // Süper Lig
    ['Galatasaray', 'TR1', 84, 'https://r2.thesportsdb.com/images/media/team/badge/io7jk21767941298.png'],
    ['Fenerbahçe', 'TR1', 83, 'https://r2.thesportsdb.com/images/media/team/badge/twxxvs1448199691.png'],
    ['Beşiktaş', 'TR1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/svo05k1776827439.png'],
    ['Trabzonspor', 'TR1', 77, 'https://r2.thesportsdb.com/images/media/team/badge/96s34o1776827629.png'],

    // Premier Liga
    ['Zenit', 'RU1', 80, '/games/img/escudos/zenit.png'],
    ['CSKA Moscou', 'RU1', 77, 'https://r2.thesportsdb.com/images/media/team/badge/1hf19s1681319986.png'],
    ['Spartak Moscou', 'RU1', 77, 'https://r2.thesportsdb.com/images/media/team/badge/zpj2el1754674286.png'],
    ['Rubin Kazan', 'RU1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/hp9kw81579347672.png'],

    // Premiership
    ['Celtic', 'SC1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/3uv1641758780002.png'],
    ['Rangers', 'SC1', 77, 'https://r2.thesportsdb.com/images/media/team/badge/ti24j61614290048.png'],
    ['Hearts', 'SC1', 66, 'https://r2.thesportsdb.com/images/media/team/badge/twqvyt1447597939.png'],

    // Super League
    ['Olympiacos', 'GR1', 76, 'https://r2.thesportsdb.com/images/media/team/badge/xckasq1721291508.png'],
    ['PAOK', 'GR1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/m15zsh1602774126.png'],
    ['AEK Atenas', 'GR1', 73, 'https://r2.thesportsdb.com/images/media/team/badge/4nogst1602773624.png'],
    ['Panathinaikos', 'GR1', 73, 'https://r2.thesportsdb.com/images/media/team/badge/vtpwwt1448208397.png'],

    // MLS
    ['Inter Miami', 'US1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/m4it3e1602103647.png'],
    ['LAFC', 'US1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/7nbj2a1602103638.png'],
    ['LA Galaxy', 'US1', 77, 'https://r2.thesportsdb.com/images/media/team/badge/ysyysr1420227188.png'],
    ['Seattle Sounders', 'US1', 76, 'https://r2.thesportsdb.com/images/media/team/badge/2dy5cx1706711036.png'],
    ['Atlanta United', 'US1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/ej091x1602103070.png'],

    // Liga MX
    ['Club América', 'MX1', 82, 'https://r2.thesportsdb.com/images/media/team/badge/amy1xs1581857392.png'],
    ['Chivas', 'MX1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/mp1box1593452087.png'],
    ['Monterrey', 'MX1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/yglj911721542561.png'],
    ['Tigres', 'MX1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/x6mzk41615832215.png'],
    ['Cruz Azul', 'MX1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/wcd2yi1781543370.png'],
    ['Necaxa', 'MX1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/tqdk9e1779772432.png'],

    // Saudi Pro League
    ['Al Hilal', 'SA1', 88, 'https://r2.thesportsdb.com/images/media/team/badge/5trzvq1660439102.png'],
    ['Al Nassr', 'SA1', 86, 'https://r2.thesportsdb.com/images/media/team/badge/84yvqi1748524565.png'],
    ['Al Ahli', 'SA1', 84, 'https://r2.thesportsdb.com/images/media/team/badge/5jxyip1687165392.png'],
    ['Al Ittihad', 'SA1', 84, 'https://r2.thesportsdb.com/images/media/team/badge/1z3n911666899419.png'],
    ['Al Shabab', 'SA1', 76, 'https://r2.thesportsdb.com/images/media/team/badge/x9pqf01618586414.png'],

    // J1 League
    ['Kawasaki Frontale', 'JP1', 76, 'https://r2.thesportsdb.com/images/media/team/badge/c6pot51578239112.png'],
    ['Urawa Reds', 'JP1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/ce3lhk1578239741.png'],
    ['Vissel Kobe', 'JP1', 75, 'https://r2.thesportsdb.com/images/media/team/badge/2axjch1578239819.png'],
    ['Machida Zelvia', 'JP1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/99zl6k1590070905.png'],

    // K League 1
    ['Ulsan HD', 'KR1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/0wooic1706533767.png'],
    ['Jeonbuk', 'KR1', 73, 'https://r2.thesportsdb.com/images/media/team/badge/8jif3b1747853225.png'],
    ['FC Seoul', 'KR1', 71, 'https://r2.thesportsdb.com/images/media/team/badge/31z1zf1579473186.png'],
    ['Pohang Steelers', 'KR1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/63jst01769097748.png'],

    // Persian Gulf Pro League
    ['Persepolis', 'IR1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/tzqvmg1725936631.png'],
    ['Esteghlal', 'IR1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/tu1o951756536150.png'],
    ['Sepahan', 'IR1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/ehx9kf1580934615.png'],
    ['Tractor', 'IR1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/url13x1722468959.png'],

    // Qatar Stars League
    ['Al Sadd', 'QA1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/908a011774579337.png'],
    ['Al Duhail', 'QA1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/n1vtjm1577985347.png'],
    ['Al Rayyan', 'QA1', 69, 'https://r2.thesportsdb.com/images/media/team/badge/hvb3r41653090047.png'],

    // Premier League
    ['Al Ahly', 'EG1', 78, '/games/img/escudos/alahly.png'],
    ['Zamalek', 'EG1', 75, 'https://r2.thesportsdb.com/images/media/team/badge/tgekj81580930027.png'],
    ['Pyramids', 'EG1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/8liy611607352549.png'],

    // Botola Pro
    ['Wydad', 'MA1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/t2xqqm1674614389.png'],
    ['Raja Casablanca', 'MA1', 73, 'https://r2.thesportsdb.com/images/media/team/badge/1cg64m1551428003.png'],
    ['RS Berkane', 'MA1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/f296p91743053568.png'],

    // Premiership
    ['Mamelodi Sundowns', 'ZA1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/u3md311784744590.png'],
    ['Orlando Pirates', 'ZA1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/y6dbv61583616330.png'],
    ['Kaizer Chiefs', 'ZA1', 69, 'https://r2.thesportsdb.com/images/media/team/badge/akwtlr1583614121.png'],

    // Ligue 1 (Argélia)
    ['CR Belouizdad', 'DZ1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/88vcd41695448814.png'],
    ['USM Alger', 'DZ1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/8tf8my1776275531.png'],
    ['JS Kabylie', 'DZ1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/h3w0w71581543584.png'],
    ['MC Alger', 'DZ1', 67, 'https://r2.thesportsdb.com/images/media/team/badge/xxr6gc1776382874.png'],

    // Ligue 1 (Tunísia)
    ['Espérance', 'TN1', 73, 'https://r2.thesportsdb.com/images/media/team/badge/csvbqw1753934121.png'],
    ['Étoile du Sahel', 'TN1', 69, 'https://r2.thesportsdb.com/images/media/team/badge/zyy5p81753933927.png'],
    ['Club Africain', 'TN1', 67, 'https://r2.thesportsdb.com/images/media/team/badge/2gijg71753933998.png'],
    ['CS Sfaxien', 'TN1', 66, 'https://r2.thesportsdb.com/images/media/team/badge/d89tpa1589898020.png'],

    // NPFL
    ['Enyimba', 'NG1', 66, 'https://r2.thesportsdb.com/images/media/team/badge/uiz3l01786855143.png'],
    ['Rivers United', 'NG1', 63, 'https://r2.thesportsdb.com/images/media/team/badge/4atnuh1720155248.png'],
    ['Rangers International', 'NG1', 61, 'https://r2.thesportsdb.com/images/media/team/badge/j6uqt31720154917.png'],

    // Linafoot
    ['TP Mazembe', 'CD1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/niib4k1761259428.png'],
    ['AS Vita Club', 'CD1', 65, 'https://r2.thesportsdb.com/images/media/team/badge/jqsymd1581544723.png'],
    ['Maniema Union', 'CD1', 61, 'https://r2.thesportsdb.com/images/media/team/badge/dhx9ek1724570684.png'],

    // A-League
    ['Melbourne City', 'AU1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/rkeqme1603301840.png'],
    ['Sydney FC', 'AU1', 69, 'https://r2.thesportsdb.com/images/media/team/badge/utgq8z1546110747.png'],
    ['Melbourne Victory', 'AU1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/wwvsqx1473454564.png'],
    ['Auckland FC', 'AU1', 64, 'https://r2.thesportsdb.com/images/media/team/badge/f36lst1730017502.png'],

    /* ══ OS CLUBES DAS LIGAS NOVAS, E OS QUE FALTAVAM NAS ANTIGAS ══════
     *
     * Sem escudo, de propósito: o catálogo antigo tem a URL do badge de cada
     * clube e essas eu não tenho como inventar — link chutado dá imagem
     * quebrada, que é pior que não ter. O jogo já resolve isso sozinho, com
     * o monograma colorido, e preencher depois é manutenção e não conserto.
     *
     * A força SEMPRE varia dentro da liga, porque é dela que sai o degrau:
     * uma liga onde todo clube vale a mesma coisa não é liga, é uma linha.
     */

    // ── LigaPro (Equador) ─────────────────────────────────────────────
    ['Independiente del Valle', 'EC1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/qku0lv1737622736.png'],
    ['Barcelona SC', 'EC1', 69, 'https://r2.thesportsdb.com/images/media/team/badge/c5yr001653075296.png'],
    ['LDU Quito', 'EC1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/5tf5ch1736404372.png'],
    ['Emelec', 'EC1', 65, 'https://r2.thesportsdb.com/images/media/team/badge/15ovlq1579959696.png'],
    ['Aucas', 'EC1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/6vf1py1770395139.png'],
    ['Delfín', 'EC1', 60, 'https://r2.thesportsdb.com/images/media/team/badge/drhsv91579961166.png'],

    // ── Primera División (Paraguai) ───────────────────────────────────
    ['Olimpia', 'PY1', 69, 'https://r2.thesportsdb.com/images/media/team/badge/tyfnp11765859175.png'],
    ['Cerro Porteño', 'PY1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/xkxqtz1652126507.png'],
    ['Libertad', 'PY1', 67, 'https://r2.thesportsdb.com/images/media/team/badge/np5lsg1579989360.png'],
    ['Guaraní', 'PY1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/rlsr7u1579989354.png'],
    ['Club Nacional', 'PY1', 60, 'https://r2.thesportsdb.com/images/media/team/badge/uv7z921579989369.png'],

    // ── Liga 1 (Peru) ─────────────────────────────────────────────────
    ['Universitario', 'PE1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/xt290m1603137966.png'],
    ['Alianza Lima', 'PE1', 67, 'https://r2.thesportsdb.com/images/media/team/badge/y5wov41580040189.png'],
    ['Sporting Cristal', 'PE1', 66, 'https://r2.thesportsdb.com/images/media/team/badge/ive4ya1766423194.png'],
    ['Melgar', 'PE1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/0puqm41765861529.png'],
    ['Cienciano', 'PE1', 59, 'https://r2.thesportsdb.com/images/media/team/badge/yla9k51734242161.png'],

    // ── Liga FUTVE (Venezuela) ────────────────────────────────────────
    ['Caracas', 'VE1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/ifzl6p1781288768.png'],
    ['Deportivo Táchira', 'VE1', 60, 'https://r2.thesportsdb.com/images/media/team/badge/bdwm1e1734241932.png'],
    ['Carabobo', 'VE1', 57, 'https://r2.thesportsdb.com/images/media/team/badge/yeu8bn1639000596.png'],
    ['Monagas', 'VE1', 55, 'https://r2.thesportsdb.com/images/media/team/badge/xv00ru1751998362.png'],
    ['Estudiantes de Mérida', 'VE1', 53, 'https://r2.thesportsdb.com/images/media/team/badge/8i8kc81766113962.png'],

    // ── División Profesional (Bolívia) ────────────────────────────────
    ['Bolívar', 'BO1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/0o5jrz1579798499.png'],
    ['The Strongest', 'BO1', 60, 'https://upload.wikimedia.org/wikipedia/commons/2/22/Club_the_strongest_escudo_transparent_background_png_700px.png'],
    ['Always Ready', 'BO1', 56, 'https://r2.thesportsdb.com/images/media/team/badge/g7rlnw1618943620.png'],
    ['Blooming', 'BO1', 52, 'https://r2.thesportsdb.com/images/media/team/badge/mslln91766423827.png'],
    ['Oriente Petrolero', 'BO1', 51, 'https://r2.thesportsdb.com/images/media/team/badge/yfvkiz1774884306.png'],

    // ── HNL (Croácia) ─────────────────────────────────────────────────
    ['Dinamo Zagreb', 'HR1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/zcb6f61784988620.png'],
    ['Hajduk Split', 'HR1', 73, 'https://r2.thesportsdb.com/images/media/team/badge/23mvtk1579955412.png'],
    ['Rijeka', 'HR1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/z8bxvi1603310975.png'],
    ['Osijek', 'HR1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/kly3v31578830575.png'],
    ['Lokomotiva', 'HR1', 65, 'https://r2.thesportsdb.com/images/media/team/badge/vzl3cy1578830545.png'],

    // ── Super League (Suíça) ──────────────────────────────────────────
    ['Young Boys', 'CH1', 77, 'https://r2.thesportsdb.com/images/media/team/badge/9mxdoo1534784569.png'],
    ['Basel', 'CH1', 75, 'https://r2.thesportsdb.com/images/media/team/badge/xppxwr1473791183.png'],
    ['Servette', 'CH1', 71, 'https://r2.thesportsdb.com/images/media/team/badge/440wv71692206330.png'],
    ['Zürich', 'CH1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/af50gk1779213314.png'],
    ['Lugano', 'CH1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/2kh2if1567615581.png'],
    ['St. Gallen', 'CH1', 67, 'https://upload.wikimedia.org/wikipedia/commons/e/e3/FC_St._Gallen_logo.svg'],

    // ── Bundesliga Austríaca ──────────────────────────────────────────
    ['Red Bull Salzburg', 'AT1', 79, 'https://r2.thesportsdb.com/images/media/team/badge/nc2cua1781541639.png'],
    ['Sturm Graz', 'AT1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/ppg0j71578585847.png'],
    ['Rapid Viena', 'AT1', 71, 'https://r2.thesportsdb.com/images/media/team/badge/87y8id1779342351.png'],
    ['LASK', 'AT1', 69, 'https://r2.thesportsdb.com/images/media/team/badge/oox26l1683556395.png'],
    ['Austria Viena', 'AT1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/w7j21f1779342211.png'],

    // ── Premier Liha (Ucrânia) ────────────────────────────────────────
    ['Shakhtar Donetsk', 'UA1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/sqrxsr1421791799.png'],
    ['Dínamo de Kiev', 'UA1', 76, 'https://r2.thesportsdb.com/images/media/team/badge/ktbncx1781158762.png'],
    ['Zorya', 'UA1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/pvppfe1785305666.png'],
    ['Dnipro-1', 'UA1', 66, 'https://upload.wikimedia.org/wikipedia/en/2/2c/SC_Dnipro-1_%28Ukraine%29_white_background_CMYK.svg'],
    ['Kryvbas', 'UA1', 63, 'https://r2.thesportsdb.com/images/media/team/badge/2e11ks1681107496.png'],

    // ── Superliga (Dinamarca) ─────────────────────────────────────────
    ['Copenhague', 'DK1', 76, 'https://r2.thesportsdb.com/images/media/team/badge/styqtr1473535513.png'],
    ['Midtjylland', 'DK1', 74, 'https://upload.wikimedia.org/wikipedia/en/d/dd/FC_Midtjylland_logo.svg'],
    ['Brøndby', 'DK1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/usxoux1784825763.png'],
    ['AGF', 'DK1', 67, 'https://upload.wikimedia.org/wikipedia/en/a/ac/AGF_Aarhus_logo.svg'],
    ['Nordsjælland', 'DK1', 66, 'https://upload.wikimedia.org/wikipedia/en/2/23/FC_Nordsj%C3%A6lland_logo.svg'],

    // ── Superliga Sérvia ──────────────────────────────────────────────
    ['Estrela Vermelha', 'RS1', 76, 'https://r2.thesportsdb.com/images/media/team/badge/osgmbz1781157114.png'],
    ['Partizan', 'RS1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/xe41k11781157208.png'],
    ['Vojvodina', 'RS1', 66, 'https://r2.thesportsdb.com/images/media/team/badge/adh59u1703173053.png'],
    ['Čukarički', 'RS1', 64, 'https://r2.thesportsdb.com/images/media/team/badge/sk6owo1512571250.png'],
    ['TSC', 'RS1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/1bnnu81703173169.png'],

    // ── Ekstraklasa (Polônia) ─────────────────────────────────────────
    ['Legia', 'PL1', 73, 'https://r2.thesportsdb.com/images/media/team/badge/c969ez1632775656.png'],
    ['Raków', 'PL1', 71, 'https://r2.thesportsdb.com/images/media/team/badge/vy8paa1579458598.png'],
    ['Lech Poznań', 'PL1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/8zfxyx1685597440.png'],
    ['Jagiellonia', 'PL1', 67, 'https://r2.thesportsdb.com/images/media/team/badge/ucze1y1601404699.png'],
    ['Pogoń', 'PL1', 65, 'https://upload.wikimedia.org/wikipedia/en/5/55/Pogon_Szczecin_logo.svg'],
    ['Górnik Zabrze', 'PL1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/fk1fam1767928408.png'],

    // ── Chance Liga (Chéquia) ─────────────────────────────────────────
    ['Slavia Praga', 'CZ1', 75, 'https://r2.thesportsdb.com/images/media/team/badge/l7kl4n1759252139.png'],
    ['Sparta Praga', 'CZ1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/j00qct1718287150.png'],
    ['Viktoria Plzeň', 'CZ1', 71, 'https://r2.thesportsdb.com/images/media/team/badge/at8i2h1679265942.png'],
    ['Baník Ostrava', 'CZ1', 65, 'https://r2.thesportsdb.com/images/media/team/badge/y1pij41691419087.png'],
    ['Slovan Liberec', 'CZ1', 63, 'https://r2.thesportsdb.com/images/media/team/badge/00k5891718287162.png'],

    // ── Eliteserien (Noruega) ─────────────────────────────────────────
    ['Bodø/Glimt', 'NO1', 73, 'https://r2.thesportsdb.com/images/media/team/badge/uqpwwx1449165943.png'],
    ['Molde', 'NO1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/apziyg1534866527.png'],
    ['Rosenborg', 'NO1', 67, 'https://r2.thesportsdb.com/images/media/team/badge/z483ps1764866361.png'],
    ['Brann', 'NO1', 64, 'https://r2.thesportsdb.com/images/media/team/badge/ovuad71690695412.png'],
    ['Viking', 'NO1', 63, 'https://r2.thesportsdb.com/images/media/team/badge/9mzq961590148192.png'],

    // ── Allsvenskan (Suécia) ──────────────────────────────────────────
    ['Malmö FF', 'SE1', 72, 'https://upload.wikimedia.org/wikipedia/commons/e/ef/Malmo_FF_logo.svg'],
    ['AIK', 'SE1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/rwsrxq1420769503.png'],
    ['Djurgården', 'SE1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/yuuwru1425411493.png'],
    ['Hammarby', 'SE1', 66, 'https://r2.thesportsdb.com/images/media/team/badge/3kgg5b1592144695.png'],
    ['IFK Göteborg', 'SE1', 63, 'https://r2.thesportsdb.com/images/media/team/badge/ywyxtp1429470529.png'],
    ['Elfsborg', 'SE1', 63, 'https://r2.thesportsdb.com/images/media/team/badge/svqvqq1421957626.png'],

    // ── Liga Portugal 2 ───────────────────────────────────────────────
    ['Tondela', 'PT2', 64, 'https://r2.thesportsdb.com/images/media/team/badge/o7nqff1628856081.png'],
    ['Académico de Viseu', 'PT2', 63, 'https://r2.thesportsdb.com/images/media/team/badge/l9okbc1593020122.png'],
    ['Leixões', 'PT2', 62, 'https://r2.thesportsdb.com/images/media/team/badge/6le5bu1580389318.png'],
    ['Feirense', 'PT2', 61, 'https://r2.thesportsdb.com/images/media/team/badge/4b27rw1628851130.png'],
    ['Penafiel', 'PT2', 59, 'https://r2.thesportsdb.com/images/media/team/badge/91hl741593020134.png'],
    ['Mafra', 'PT2', 58, 'https://r2.thesportsdb.com/images/media/team/badge/mbze9t1580389164.png'],

    // ── Eerste Divisie (Holanda) ──────────────────────────────────────
    ['Roda JC', 'NL2', 64, 'https://upload.wikimedia.org/wikipedia/en/0/06/Roda_JC_Kerkrade_logo.svg'],
    ['De Graafschap', 'NL2', 63, 'https://upload.wikimedia.org/wikipedia/commons/f/ff/Logo_Betaald_Voetbal_De_Graafschap.svg'],
    ['Willem II', 'NL2', 63, 'https://upload.wikimedia.org/wikipedia/en/7/77/Willem_II_logo.svg'],
    ['Cambuur', 'NL2', 62, 'https://upload.wikimedia.org/wikipedia/en/c/cb/SC_Cambuur_logo.svg'],
    ['VVV-Venlo', 'NL2', 60, 'https://upload.wikimedia.org/wikipedia/commons/9/9c/VVV_Venlo.svg'],
    ['Den Bosch', 'NL2', 58, 'https://upload.wikimedia.org/wikipedia/en/b/ba/FC_Den_Bosch_logo.svg'],

    // ── Chinese Super League ──────────────────────────────────────────
    ['Shanghai Port', 'CN1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/d9evs91776534441.png'],
    ['Shanghai Shenhua', 'CN1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/p0r1bj1654266974.png'],
    ['Beijing Guoan', 'CN1', 67, 'https://r2.thesportsdb.com/images/media/team/badge/cdkguq1652626854.png'],
    ['Shandong Taishan', 'CN1', 67, 'https://r2.thesportsdb.com/images/media/team/badge/vheyu21654266995.png'],
    ['Chengdu Rongcheng', 'CN1', 64, 'https://r2.thesportsdb.com/images/media/team/badge/r20v6x1713897058.png'],
    ['Zhejiang', 'CN1', 62, 'https://upload.wikimedia.org/wikipedia/en/e/e8/Zhejiang_Professional_F.C.svg'],

    // ── Superliga Uzbeque ─────────────────────────────────────────────
    ['Pakhtakor', 'UZ1', 64, 'https://r2.thesportsdb.com/images/media/team/badge/k0d31l1782687509.png'],
    ['Nasaf', 'UZ1', 60, 'https://r2.thesportsdb.com/images/media/team/badge/ezlsi91724091113.png'],
    ['Bunyodkor', 'UZ1', 57, 'https://r2.thesportsdb.com/images/media/team/badge/crm3u81583080177.png'],
    ['Navbahor', 'UZ1', 55, 'https://r2.thesportsdb.com/images/media/team/badge/p4dsce1656015182.png'],
    ['AGMK', 'UZ1', 53, 'https://r2.thesportsdb.com/images/media/team/badge/35f4321693024889.png'],

    // ── Stars League (Iraque) ─────────────────────────────────────────
    ['Al-Quwa Al-Jawiya', 'IQ1', 63, 'https://r2.thesportsdb.com/images/media/team/badge/oyq9801617889653.png'],
    ['Al-Shorta', 'IQ1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/357uy81782794401.png'],
    ['Al-Zawraa', 'IQ1', 60, 'https://r2.thesportsdb.com/images/media/team/badge/1hkbtn1693029092.png'],
    ['Erbil', 'IQ1', 55, 'https://r2.thesportsdb.com/images/media/team/badge/b41q2g1624721445.png'],
    ['Al-Talaba', 'IQ1', 53, 'https://r2.thesportsdb.com/images/media/team/badge/m1hs731624721375.png'],

    // ── Premier League Ganesa ─────────────────────────────────────────
    ['Asante Kotoko', 'GH1', 63, 'https://r2.thesportsdb.com/images/media/team/badge/u1mppc1578401554.png'],
    ['Hearts of Oak', 'GH1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/v3eyvw1617287212.png'],
    ['Medeama', 'GH1', 58, 'https://r2.thesportsdb.com/images/media/team/badge/p8p3jr1617287252.png'],
    ['Aduana', 'GH1', 56, 'https://r2.thesportsdb.com/images/media/team/badge/5qeyq71617287049.png'],
    ['Bechem United', 'GH1', 53, 'https://r2.thesportsdb.com/images/media/team/badge/y3uo7z1720155733.png'],

    // ── Ligue 1 Senegalesa ────────────────────────────────────────────
    ['Jaraaf', 'SN1', 61, 'https://r2.thesportsdb.com/images/media/team/badge/p25tdp1720157205.png'],
    ['Casa Sports', 'SN1', 59, 'https://r2.thesportsdb.com/images/media/team/badge/0cqkoc1673938919.png'],
    ['Génération Foot', 'SN1', 57, 'https://r2.thesportsdb.com/images/media/team/badge/i5glvd1720156796.png'],
    ['Teungueth', 'SN1', 55, 'https://r2.thesportsdb.com/images/media/team/badge/i6s10n1720157496.png'],
    ['Diambars', 'SN1', 52, 'https://r2.thesportsdb.com/images/media/team/badge/3ci0k01720156622.png'],

    // ── Ligue 1 Marfinense ────────────────────────────────────────────
    ['ASEC Mimosas', 'CI1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/b9e1cr1589312301.png'],
    ['Africa Sports', 'CI1', 58, 'https://upload.wikimedia.org/wikipedia/en/4/4e/Africa_Sports_Logo.png'],
    ["Stade d'Abidjan", 'CI1', 55, 'https://r2.thesportsdb.com/images/media/team/badge/zr19ao1776292889.png'],
    ['San Pédro', 'CI1', 54, 'https://r2.thesportsdb.com/images/media/team/badge/3hjar61708390704.png'],
    ['RC Abidjan', 'CI1', 51, 'https://r2.thesportsdb.com/images/media/team/badge/ou01i81784654220.png'],

    // ── Elite One (Camarões) ──────────────────────────────────────────
    ['Coton Sport', 'CM1', 60, 'https://upload.wikimedia.org/wikipedia/en/3/3c/Cotonsport.png'],
    ['Canon Yaoundé', 'CM1', 55, 'https://upload.wikimedia.org/wikipedia/en/6/69/Canon_Yaound%C3%A9_logo.png'],
    ['Union Douala', 'CM1', 53, 'https://upload.wikimedia.org/wikipedia/en/c/c1/US_Douala_%28logo%29.png'],
    ['Victoria United', 'CM1', 51, 'https://upload.wikimedia.org/wikipedia/en/8/81/Victoria_United_FC_%28Cameroon%29_logo.png'],
    ['Bamboutos', 'CM1', 49, 'https://upload.wikimedia.org/wikipedia/en/8/86/Bamboutos_FC.png'],

    // ── Première Division (Mali) ──────────────────────────────────────
    ['Stade Malien', 'ML1', 60, 'https://r2.thesportsdb.com/images/media/team/badge/fepvj11589484866.png'],
    ['Djoliba', 'ML1', 58, 'https://r2.thesportsdb.com/images/media/team/badge/fjw4cg1764470742.png'],
    ['Real Bamako', 'ML1', 53, 'https://r2.thesportsdb.com/images/media/team/badge/fmu4on1660439086.png'],
    ['Binga', 'ML1', 50, 'https://r2.thesportsdb.com/images/media/team/badge/zsbmwy1629163057.png'],

    // ── Liga Promerica (Costa Rica) ───────────────────────────────────
    ['Saprissa', 'CR1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/tvj33z1707630611.png'],
    ['Alajuelense', 'CR1', 67, 'https://r2.thesportsdb.com/images/media/team/badge/zi9ft21707630526.png'],
    ['Herediano', 'CR1', 64, 'https://r2.thesportsdb.com/images/media/team/badge/20qq911582143301.png'],
    ['Cartaginés', 'CR1', 60, 'https://r2.thesportsdb.com/images/media/team/badge/0mo64x1589122311.png'],
    ['Puntarenas', 'CR1', 56, 'https://r2.thesportsdb.com/images/media/team/badge/ljzov51657724106.png'],

    // ── Canadian Premier ──────────────────────────────────────────────
    ['Forge FC', 'CA1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/48dk0h1582572865.png'],
    ['Cavalry FC', 'CA1', 60, 'https://r2.thesportsdb.com/images/media/team/badge/gpi5qj1583351269.png'],
    ['Pacific FC', 'CA1', 58, 'https://r2.thesportsdb.com/images/media/team/badge/6qzhpj1583351283.png'],
    ['Atlético Ottawa', 'CA1', 56, 'https://r2.thesportsdb.com/images/media/team/badge/k5gzuw1583351260.png'],
    ['Vancouver FC', 'CA1', 53, 'https://r2.thesportsdb.com/images/media/team/badge/map6vh1770132710.png'],

    // ── Liga Nacional (Honduras) ──────────────────────────────────────
    ['CD Olimpia', 'HN1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/a8229b1580931621.png'],
    ['Motagua', 'HN1', 59, 'https://r2.thesportsdb.com/images/media/team/badge/zi6mw31581857175.png'],
    ['Marathón', 'HN1', 56, 'https://r2.thesportsdb.com/images/media/team/badge/i9saoy1582143482.png'],
    ['Real España', 'HN1', 54, 'https://r2.thesportsdb.com/images/media/team/badge/i28zh01589120247.png'],
    ['CD Victoria', 'HN1', 50, 'https://r2.thesportsdb.com/images/media/team/badge/sbkqly1642710637.png'],

    // ── LPF Panamá ────────────────────────────────────────────────────
    ['Tauro', 'PA1', 59, 'https://r2.thesportsdb.com/images/media/team/badge/paw2ia1768358478.png'],
    ['San Francisco FC', 'PA1', 56, 'https://r2.thesportsdb.com/images/media/team/badge/16pi2m1582143972.png'],
    ['Plaza Amador', 'PA1', 54, 'https://r2.thesportsdb.com/images/media/team/badge/78pior1589297968.png'],
    ['Sporting San Miguelito', 'PA1', 52, 'https://r2.thesportsdb.com/images/media/team/badge/3y4hof1768358419.png'],
    ['Independiente de La Chorrera', 'PA1', 49, 'https://r2.thesportsdb.com/images/media/team/badge/dra6xs1768358312.png'],

    // ── Jamaica Premier ───────────────────────────────────────────────
    ['Cavalier', 'JM1', 57, 'https://r2.thesportsdb.com/images/media/team/badge/ij6fwe1624889066.png'],
    ['Mount Pleasant', 'JM1', 55, 'https://r2.thesportsdb.com/images/media/team/badge/jdypva1624889083.png'],
    ['Arnett Gardens', 'JM1', 52, 'https://r2.thesportsdb.com/images/media/team/badge/3dnp6t1624889061.png'],
    ['Portmore United', 'JM1', 51, 'https://r2.thesportsdb.com/images/media/team/badge/8rgg2z1580932547.png'],
    ['Harbour View', 'JM1', 48, 'https://r2.thesportsdb.com/images/media/team/badge/x1aenf1594324197.png'],

    /* ── E o que faltava nas ligas que já existiam ─────────────────────
     * Um país com três clubes não é um país: a lista de ofertas se esgota,
     * a rede de segurança abre e o jogador é jogado pra fora do próprio
     * campeonato. Uruguai, Chile e Colômbia tinham três cada. */

    // Brasileirão Série A
    ['Juventude', 'BR1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/1ntter1766506778.png'],
    ['Mirassol', 'BR1', 75, 'https://r2.thesportsdb.com/images/media/team/badge/pw8uo11765900737.png'],
    // Brasileirão Série B
    ['América-MG', 'BR2', 66, 'https://r2.thesportsdb.com/images/media/team/badge/rtpp171752177342.png'],
    ['Criciúma', 'BR2', 65, 'https://r2.thesportsdb.com/images/media/team/badge/r11mld1766506200.png'],
    ['Novorizontino', 'BR2', 64, 'https://r2.thesportsdb.com/images/media/team/badge/2qwxw51617198963.png'],
    ['Vila Nova', 'BR2', 62, 'https://r2.thesportsdb.com/images/media/team/badge/nwd4ns1740851638.png'],
    ['Chapecoense', 'BR2', 63, 'https://r2.thesportsdb.com/images/media/team/badge/wy0e1i1765900601.png'],
    // Brasileirão Série C
    ['Londrina', 'BR3', 54, 'https://r2.thesportsdb.com/images/media/team/badge/xp2z9j1740846583.png'],
    ['Figueirense', 'BR3', 53, 'https://r2.thesportsdb.com/images/media/team/badge/yvvyps1473538067.png'],
    ['ABC', 'BR3', 51, 'https://r2.thesportsdb.com/images/media/team/badge/m1ampq1766507477.png'],
    ['Botafogo-PB', 'BR3', 50, 'https://upload.wikimedia.org/wikipedia/en/b/b3/Botafogo_Futebol_Clube_%28Paraiba%29_logo.svg'],
    // Primera Nacional (Argentina)
    ['All Boys', 'AR2', 60, 'https://r2.thesportsdb.com/images/media/team/badge/zo4vm01578824142.png'],
    ['San Martín de Tucumán', 'AR2', 61, 'https://r2.thesportsdb.com/images/media/team/badge/xymq001532856832.png'],
    ['Chacarita', 'AR2', 56, 'https://r2.thesportsdb.com/images/media/team/badge/pjam301511624231.png'],
    // Primera División (Uruguai)
    ['Danubio', 'UY1', 63, 'https://r2.thesportsdb.com/images/media/team/badge/vtryqv1473541135.png'],
    ['Liverpool Montevideo', 'UY1', 64, 'https://r2.thesportsdb.com/images/media/team/badge/0xat6f1678717839.png'],
    ['Montevideo City', 'UY1', 61, 'https://r2.thesportsdb.com/images/media/team/badge/v7urjn1580234584.png'],
    ['Cerro Largo', 'UY1', 59, 'https://r2.thesportsdb.com/images/media/team/badge/f2lnua1580234598.png'],
    // Primera División (Chile)
    ['Universidad Católica', 'CL1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/h2pcuc1602188028.png'],
    ['Huachipato', 'CL1', 63, 'https://r2.thesportsdb.com/images/media/team/badge/uhzow91736214236.png'],
    ['Palestino', 'CL1', 61, 'https://r2.thesportsdb.com/images/media/team/badge/62site1756091114.png'],
    ['Unión Española', 'CL1', 59, 'https://r2.thesportsdb.com/images/media/team/badge/3ixbl71756141900.png'],
    // Categoría Primera A (Colômbia)
    ['América de Cali', 'CO1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/lwxcn71576156105.png'],
    ['Independiente Medellín', 'CO1', 67, 'https://r2.thesportsdb.com/images/media/team/badge/mzezh31783226830.png'],
    ['Deportivo Cali', 'CO1', 65, 'https://r2.thesportsdb.com/images/media/team/badge/isc2yu1580833286.png'],
    ['Santa Fe', 'CO1', 66, 'https://r2.thesportsdb.com/images/media/team/badge/16vs771629122765.png'],
    // Premier League
    ['Nottingham Forest', 'EN1', 83, 'https://upload.wikimedia.org/wikipedia/en/e/e5/Nottingham_Forest_F.C._logo.svg'],
    ['Bournemouth', 'EN1', 81, 'https://r2.thesportsdb.com/images/media/team/badge/y08nak1534071116.png'],
    ['Brentford', 'EN1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/grv1aw1546453779.png'],
    // Championship
    ['West Bromwich', 'EN2', 70, 'https://r2.thesportsdb.com/images/media/team/badge/rsvuxw1448813527.png'],
    ['Middlesbrough', 'EN2', 70, 'https://r2.thesportsdb.com/images/media/team/badge/advjg71780068902.png'],
    ['Coventry City', 'EN2', 69, 'https://r2.thesportsdb.com/images/media/team/badge/uxyqys1424033798.png'],
    // League One
    ['Blackpool', 'EN3', 54, 'https://r2.thesportsdb.com/images/media/team/badge/utywru1448754934.png'],
    ['Peterborough', 'EN3', 53, 'https://r2.thesportsdb.com/images/media/team/badge/p14ltc1779778820.png'],
    ['Stockport', 'EN3', 52, 'https://upload.wikimedia.org/wikipedia/en/4/43/Stockport_County_FC_logo_2020.svg'],
    // LaLiga
    ['Rayo Vallecano', 'ES1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/nzhu941655595465.png'],
    ['Mallorca', 'ES1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/ssptsx1473503730.png'],
    ['Girona', 'ES1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/kfu7zu1659897499.png'],
    // LaLiga 2
    ['Deportivo La Coruña', 'ES2', 66, 'https://upload.wikimedia.org/wikipedia/en/5/56/RC_Deportivo_A_Coru%C3%B1a_logo_2026.svg'],
    ['Levante', 'ES2', 65, 'https://r2.thesportsdb.com/images/media/team/badge/xwtxsx1473503739.png'],
    ['Málaga', 'ES2', 63, 'https://r2.thesportsdb.com/images/media/team/badge/upqyvr1473502952.png'],
    // Serie A
    ['Genoa', 'IT1', 77, 'https://r2.thesportsdb.com/images/media/team/badge/52s8dn1655553600.png'],
    ['Cagliari', 'IT1', 76, 'https://r2.thesportsdb.com/images/media/team/badge/wvsvxt1447534471.png'],
    ['Como', 'IT1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/02x81t1627405841.png'],
    // Serie B
    ['Cremonese', 'IT2', 63, 'https://r2.thesportsdb.com/images/media/team/badge/6ng2vy1579708291.png'],
    ['Spezia', 'IT2', 63, 'https://r2.thesportsdb.com/images/media/team/badge/3wgebp1749146364.png'],
    ['Catanzaro', 'IT2', 60, 'https://r2.thesportsdb.com/images/media/team/badge/byrc5e1691995858.png'],
    // Bundesliga
    ['Freiburg', 'DE1', 80, 'https://r2.thesportsdb.com/images/media/team/badge/urwtup1473453288.png'],
    ['Hoffenheim', 'DE1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/9hwvb21621593919.png'],
    ['Mainz 05', 'DE1', 78, 'https://upload.wikimedia.org/wikipedia/commons/1/1b/1._FSV_Mainz_05_logo.svg'],
    ['Augsburg', 'DE1', 76, 'https://r2.thesportsdb.com/images/media/team/badge/xqyyvq1473453233.png'],
    // 2. Bundesliga
    ['Colônia', 'DE2', 68, 'https://r2.thesportsdb.com/images/media/team/badge/2j1sc91566049407.png'],
    ['Fortuna Düsseldorf', 'DE2', 64, 'https://r2.thesportsdb.com/images/media/team/badge/rsruww1473454140.png'],
    ['Nürnberg', 'DE2', 62, 'https://r2.thesportsdb.com/images/media/team/badge/wtj8rd1659904028.png'],
    // Ligue 1
    ['Toulouse', 'FR1', 76, 'https://r2.thesportsdb.com/images/media/team/badge/17eqox1688449282.png'],
    ['Montpellier', 'FR1', 75, 'https://r2.thesportsdb.com/images/media/team/badge/8wn9x31750879448.png'],
    ['Reims', 'FR1', 75, 'https://r2.thesportsdb.com/images/media/team/badge/xcrw1b1592925946.png'],
    ['Brest', 'FR1', 77, 'https://r2.thesportsdb.com/images/media/team/badge/z69be41598797026.png'],
    // Ligue 2
    ['Auxerre', 'FR2', 64, 'https://r2.thesportsdb.com/images/media/team/badge/lzdtbf1658753355.png'],
    ['Guingamp', 'FR2', 60, 'https://r2.thesportsdb.com/images/media/team/badge/5iihrp1590259631.png'],
    ['Troyes', 'FR2', 59, 'https://r2.thesportsdb.com/images/media/team/badge/sl5kzg1766617559.png'],
    // Primeira Liga
    ['Vitória de Setúbal', 'PT1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/ursyxy1471877478.png'],
    ['Rio Ave', 'PT1', 71, 'https://r2.thesportsdb.com/images/media/team/badge/ngbklq1628851239.png'],
    ['Boavista', 'PT1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/usi98v1628853974.png'],
    ['Famalicão', 'PT1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/a3f4er1563653256.png'],
    // Eredivisie
    ['Utrecht', 'NL1', 74, 'https://upload.wikimedia.org/wikipedia/commons/5/5d/Logo_FC_Utrecht.svg'],
    ['Vitesse', 'NL1', 72, 'https://upload.wikimedia.org/wikipedia/en/9/93/Vitesse_logo.svg'],
    ['Heerenveen', 'NL1', 71, 'https://upload.wikimedia.org/wikipedia/en/e/e1/SC_Heerenveen_logo.svg'],
    ['Sparta Rotterdam', 'NL1', 70, 'https://upload.wikimedia.org/wikipedia/en/9/9f/Sparta_Rotterdam_logo.svg'],
    // Pro League (Bélgica)
    ['Antuérpia', 'BE1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/gawwcf1691182178.png'],
    ['Gent', 'BE1', 73, 'https://r2.thesportsdb.com/images/media/team/badge/48e27o1750703124.png'],
    ['Union Saint-Gilloise', 'BE1', 75, 'https://r2.thesportsdb.com/images/media/team/badge/ljszp41654601742.png'],
    // Süper Lig
    ['Başakşehir', 'TR1', 75, 'https://r2.thesportsdb.com/images/media/team/badge/895mqt1685993958.png'],
    ['Samsunspor', 'TR1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/vz05y71679456608.png'],
    ['Konyaspor', 'TR1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/rxwptr1448203413.png'],
    // Premier Liga (Rússia)
    ['Lokomotiv Moscou', 'RU1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/tuyrur1473452310.png'],
    ['Dínamo Moscou', 'RU1', 74, 'https://upload.wikimedia.org/wikipedia/en/e/e7/Dynamo_Moscow_logo.svg'],
    ['Krasnodar', 'RU1', 76, 'https://r2.thesportsdb.com/images/media/team/badge/srxryu1473452272.png'],
    // Premiership (Escócia)
    ['Aberdeen', 'SC1', 65, 'https://r2.thesportsdb.com/images/media/team/badge/f9s6vg1781155578.png'],
    ['Hibernian', 'SC1', 64, 'https://r2.thesportsdb.com/images/media/team/badge/qjys3z1684928969.png'],
    ['Dundee United', 'SC1', 61, 'https://r2.thesportsdb.com/images/media/team/badge/orfh821655722356.png'],
    // Super League (Grécia)
    ['Aris', 'GR1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/2d1zih1602773641.png'],
    ['Panserraikos', 'GR1', 65, 'https://r2.thesportsdb.com/images/media/team/badge/uz7slk1689689598.png'],
    ['Volos', 'GR1', 64, 'https://r2.thesportsdb.com/images/media/team/badge/d73m0n1602773666.png'],
    // MLS
    ['Columbus Crew', 'US1', 76, 'https://r2.thesportsdb.com/images/media/team/badge/dzs8cp1629059854.png'],
    ['Philadelphia Union', 'US1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/gyznyo1602103682.png'],
    ['New York City FC', 'US1', 73, 'https://r2.thesportsdb.com/images/media/team/badge/m9vis71735140655.png'],
    ['Sporting KC', 'US1', 71, 'https://r2.thesportsdb.com/images/media/team/badge/tqupxw1473536504.png'],
    // Liga MX
    ['Toluca', 'MX1', 78, 'https://r2.thesportsdb.com/images/media/team/badge/y64wy91523913186.png'],
    ['Pumas', 'MX1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/o01nvl1695734937.png'],
    ['León', 'MX1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/pc9gro1752393439.png'],
    // Saudi Pro League
    ['Al Qadsiah', 'SA1', 76, 'https://r2.thesportsdb.com/images/media/team/badge/ok63wb1719134839.png'],
    ['Al Ettifaq', 'SA1', 74, 'https://r2.thesportsdb.com/images/media/team/badge/m272h51694761970.png'],
    ['Al Taawoun', 'SA1', 73, 'https://r2.thesportsdb.com/images/media/team/badge/rlsmp91646835052.png'],
    // J1 League
    ['Sanfrecce Hiroshima', 'JP1', 73, 'https://r2.thesportsdb.com/images/media/team/badge/gsgkxj1590068965.png'],
    ['Kashima Antlers', 'JP1', 72, 'https://r2.thesportsdb.com/images/media/team/badge/2s8ady1578238881.png'],
    ['Gamba Osaka', 'JP1', 70, 'https://r2.thesportsdb.com/images/media/team/badge/tq9edk1638813311.png'],
    // K League 1
    ['Gangwon FC', 'KR1', 69, 'https://r2.thesportsdb.com/images/media/team/badge/c4igx71579729617.png'],
    ['Suwon FC', 'KR1', 67, 'https://r2.thesportsdb.com/images/media/team/badge/x39pm41589559443.png'],
    ['Gwangju FC', 'KR1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/uuzr4x1579473084.png'],
    // Persian Gulf Pro League
    ['Foolad', 'IR1', 66, 'https://r2.thesportsdb.com/images/media/team/badge/ijxpe21582291802.png'],
    ['Gol Gohar', 'IR1', 64, 'https://r2.thesportsdb.com/images/media/team/badge/chkgy61582375294.png'],
    // Qatar Stars League
    ['Al Gharafa', 'QA1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/yan4hx1617909550.png'],
    ['Al Arabi', 'QA1', 65, 'https://upload.wikimedia.org/wikipedia/en/2/22/Al-Arabi_SC_Qatar_logo.svg'],
    ['Umm Salal', 'QA1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/0sw0601635871111.png'],
    // Premier League (Egito)
    ['Ismaily', 'EG1', 66, 'https://r2.thesportsdb.com/images/media/team/badge/1g46qo1589807617.png'],
    ['Al Masry', 'EG1', 65, 'https://r2.thesportsdb.com/images/media/team/badge/3aw86h1589807260.png'],
    // Botola Pro
    ['FAR Rabat', 'MA1', 68, 'https://r2.thesportsdb.com/images/media/team/badge/jkjp961777421509.png'],
    ['Maghreb de Fès', 'MA1', 64, 'https://r2.thesportsdb.com/images/media/team/badge/l8ma1e1653314001.png'],
    // Premiership (África do Sul)
    ['SuperSport United', 'ZA1', 64, 'https://r2.thesportsdb.com/images/media/team/badge/abtgn31754525186.png'],
    ['Stellenbosch', 'ZA1', 63, 'https://r2.thesportsdb.com/images/media/team/badge/4j7uc01583616345.png'],
    // Ligue 1 (Argélia)
    ['ES Sétif', 'DZ1', 66, 'https://r2.thesportsdb.com/images/media/team/badge/7tjuon1785948034.png'],
    // Ligue 1 (Tunísia)
    ['US Monastir', 'TN1', 62, 'https://r2.thesportsdb.com/images/media/team/badge/v1mr1z1777247628.png'],
    // NPFL
    ['Remo Stars', 'NG1', 59, 'https://r2.thesportsdb.com/images/media/team/badge/lktj1y1720155062.png'],
    ['Plateau United', 'NG1', 58, 'https://r2.thesportsdb.com/images/media/team/badge/6otzjg1786856754.png'],
    // Linafoot
    ['DC Motema Pembe', 'CD1', 59, 'https://r2.thesportsdb.com/images/media/team/badge/m9g4s11724570584.png'],
    ['Lupopo', 'CD1', 58, 'https://r2.thesportsdb.com/images/media/team/badge/1sc6lt1724604738.png'],
    // A-League
    ['Adelaide United', 'AU1', 66, 'https://r2.thesportsdb.com/images/media/team/badge/wpyuwv1473454602.png'],
    ['Western Sydney', 'AU1', 65, 'https://r2.thesportsdb.com/images/media/team/badge/yotugj1759632879.png'],
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
