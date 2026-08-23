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
    ['Independiente del Valle', 'EC1', 72, ''],
    ['Barcelona SC', 'EC1', 69, ''],
    ['LDU Quito', 'EC1', 68, ''],
    ['Emelec', 'EC1', 65, ''],
    ['Aucas', 'EC1', 62, ''],
    ['Delfín', 'EC1', 60, ''],

    // ── Primera División (Paraguai) ───────────────────────────────────
    ['Olimpia', 'PY1', 69, ''],
    ['Cerro Porteño', 'PY1', 68, ''],
    ['Libertad', 'PY1', 67, ''],
    ['Guaraní', 'PY1', 62, ''],
    ['Club Nacional', 'PY1', 60, ''],

    // ── Liga 1 (Peru) ─────────────────────────────────────────────────
    ['Universitario', 'PE1', 68, ''],
    ['Alianza Lima', 'PE1', 67, ''],
    ['Sporting Cristal', 'PE1', 66, ''],
    ['Melgar', 'PE1', 62, ''],
    ['Cienciano', 'PE1', 59, ''],

    // ── Liga FUTVE (Venezuela) ────────────────────────────────────────
    ['Caracas', 'VE1', 62, ''],
    ['Deportivo Táchira', 'VE1', 60, ''],
    ['Carabobo', 'VE1', 57, ''],
    ['Monagas', 'VE1', 55, ''],
    ['Estudiantes de Mérida', 'VE1', 53, ''],

    // ── División Profesional (Bolívia) ────────────────────────────────
    ['Bolívar', 'BO1', 62, ''],
    ['The Strongest', 'BO1', 60, ''],
    ['Always Ready', 'BO1', 56, ''],
    ['Blooming', 'BO1', 52, ''],
    ['Oriente Petrolero', 'BO1', 51, ''],

    // ── HNL (Croácia) ─────────────────────────────────────────────────
    ['Dinamo Zagreb', 'HR1', 78, ''],
    ['Hajduk Split', 'HR1', 73, ''],
    ['Rijeka', 'HR1', 72, ''],
    ['Osijek', 'HR1', 68, ''],
    ['Lokomotiva', 'HR1', 65, ''],

    // ── Super League (Suíça) ──────────────────────────────────────────
    ['Young Boys', 'CH1', 77, ''],
    ['Basel', 'CH1', 75, ''],
    ['Servette', 'CH1', 71, ''],
    ['Zürich', 'CH1', 70, ''],
    ['Lugano', 'CH1', 68, ''],
    ['St. Gallen', 'CH1', 67, ''],

    // ── Bundesliga Austríaca ──────────────────────────────────────────
    ['Red Bull Salzburg', 'AT1', 79, ''],
    ['Sturm Graz', 'AT1', 74, ''],
    ['Rapid Viena', 'AT1', 71, ''],
    ['LASK', 'AT1', 69, ''],
    ['Austria Viena', 'AT1', 68, ''],

    // ── Premier Liha (Ucrânia) ────────────────────────────────────────
    ['Shakhtar Donetsk', 'UA1', 78, ''],
    ['Dínamo de Kiev', 'UA1', 76, ''],
    ['Zorya', 'UA1', 68, ''],
    ['Dnipro-1', 'UA1', 66, ''],
    ['Kryvbas', 'UA1', 63, ''],

    // ── Superliga (Dinamarca) ─────────────────────────────────────────
    ['Copenhague', 'DK1', 76, ''],
    ['Midtjylland', 'DK1', 74, ''],
    ['Brøndby', 'DK1', 70, ''],
    ['AGF', 'DK1', 67, ''],
    ['Nordsjælland', 'DK1', 66, ''],

    // ── Superliga Sérvia ──────────────────────────────────────────────
    ['Estrela Vermelha', 'RS1', 76, ''],
    ['Partizan', 'RS1', 72, ''],
    ['Vojvodina', 'RS1', 66, ''],
    ['Čukarički', 'RS1', 64, ''],
    ['TSC', 'RS1', 62, ''],

    // ── Ekstraklasa (Polônia) ─────────────────────────────────────────
    ['Legia', 'PL1', 73, ''],
    ['Raków', 'PL1', 71, ''],
    ['Lech Poznań', 'PL1', 70, ''],
    ['Jagiellonia', 'PL1', 67, ''],
    ['Pogoń', 'PL1', 65, ''],
    ['Górnik Zabrze', 'PL1', 62, ''],

    // ── Chance Liga (Chéquia) ─────────────────────────────────────────
    ['Slavia Praga', 'CZ1', 75, ''],
    ['Sparta Praga', 'CZ1', 74, ''],
    ['Viktoria Plzeň', 'CZ1', 71, ''],
    ['Baník Ostrava', 'CZ1', 65, ''],
    ['Slovan Liberec', 'CZ1', 63, ''],

    // ── Eliteserien (Noruega) ─────────────────────────────────────────
    ['Bodø/Glimt', 'NO1', 73, ''],
    ['Molde', 'NO1', 70, ''],
    ['Rosenborg', 'NO1', 67, ''],
    ['Brann', 'NO1', 64, ''],
    ['Viking', 'NO1', 63, ''],

    // ── Allsvenskan (Suécia) ──────────────────────────────────────────
    ['Malmö FF', 'SE1', 72, ''],
    ['AIK', 'SE1', 68, ''],
    ['Djurgården', 'SE1', 68, ''],
    ['Hammarby', 'SE1', 66, ''],
    ['IFK Göteborg', 'SE1', 63, ''],
    ['Elfsborg', 'SE1', 63, ''],

    // ── Liga Portugal 2 ───────────────────────────────────────────────
    ['Tondela', 'PT2', 64, ''],
    ['Académico de Viseu', 'PT2', 63, ''],
    ['Leixões', 'PT2', 62, ''],
    ['Feirense', 'PT2', 61, ''],
    ['Penafiel', 'PT2', 59, ''],
    ['Mafra', 'PT2', 58, ''],

    // ── Eerste Divisie (Holanda) ──────────────────────────────────────
    ['Roda JC', 'NL2', 64, ''],
    ['De Graafschap', 'NL2', 63, ''],
    ['Willem II', 'NL2', 63, ''],
    ['Cambuur', 'NL2', 62, ''],
    ['VVV-Venlo', 'NL2', 60, ''],
    ['Den Bosch', 'NL2', 58, ''],

    // ── Chinese Super League ──────────────────────────────────────────
    ['Shanghai Port', 'CN1', 72, ''],
    ['Shanghai Shenhua', 'CN1', 70, ''],
    ['Beijing Guoan', 'CN1', 67, ''],
    ['Shandong Taishan', 'CN1', 67, ''],
    ['Chengdu Rongcheng', 'CN1', 64, ''],
    ['Zhejiang', 'CN1', 62, ''],

    // ── Superliga Uzbeque ─────────────────────────────────────────────
    ['Pakhtakor', 'UZ1', 64, ''],
    ['Nasaf', 'UZ1', 60, ''],
    ['Bunyodkor', 'UZ1', 57, ''],
    ['Navbahor', 'UZ1', 55, ''],
    ['AGMK', 'UZ1', 53, ''],

    // ── Stars League (Iraque) ─────────────────────────────────────────
    ['Al-Quwa Al-Jawiya', 'IQ1', 63, ''],
    ['Al-Shorta', 'IQ1', 62, ''],
    ['Al-Zawraa', 'IQ1', 60, ''],
    ['Erbil', 'IQ1', 55, ''],
    ['Al-Talaba', 'IQ1', 53, ''],

    // ── Premier League Ganesa ─────────────────────────────────────────
    ['Asante Kotoko', 'GH1', 63, ''],
    ['Hearts of Oak', 'GH1', 62, ''],
    ['Medeama', 'GH1', 58, ''],
    ['Aduana', 'GH1', 56, ''],
    ['Bechem United', 'GH1', 53, ''],

    // ── Ligue 1 Senegalesa ────────────────────────────────────────────
    ['Jaraaf', 'SN1', 61, ''],
    ['Casa Sports', 'SN1', 59, ''],
    ['Génération Foot', 'SN1', 57, ''],
    ['Teungueth', 'SN1', 55, ''],
    ['Diambars', 'SN1', 52, ''],

    // ── Ligue 1 Marfinense ────────────────────────────────────────────
    ['ASEC Mimosas', 'CI1', 62, ''],
    ['Africa Sports', 'CI1', 58, ''],
    ["Stade d'Abidjan", 'CI1', 55, ''],
    ['San Pédro', 'CI1', 54, ''],
    ['Racing Abidjan', 'CI1', 51, ''],

    // ── Elite One (Camarões) ──────────────────────────────────────────
    ['Coton Sport', 'CM1', 60, ''],
    ['Canon Yaoundé', 'CM1', 55, ''],
    ['Union Douala', 'CM1', 53, ''],
    ['Victoria United', 'CM1', 51, ''],
    ['Bamboutos', 'CM1', 49, ''],

    // ── Première Division (Mali) ──────────────────────────────────────
    ['Stade Malien', 'ML1', 60, ''],
    ['Djoliba', 'ML1', 58, ''],
    ['Real Bamako', 'ML1', 53, ''],
    ['AS Bakaridjan', 'ML1', 50, ''],
    ['Onze Créateurs', 'ML1', 48, ''],

    // ── Liga Promerica (Costa Rica) ───────────────────────────────────
    ['Saprissa', 'CR1', 68, ''],
    ['Alajuelense', 'CR1', 67, ''],
    ['Herediano', 'CR1', 64, ''],
    ['Cartaginés', 'CR1', 60, ''],
    ['Puntarenas', 'CR1', 56, ''],

    // ── Canadian Premier ──────────────────────────────────────────────
    ['Forge FC', 'CA1', 62, ''],
    ['Cavalry FC', 'CA1', 60, ''],
    ['Pacific FC', 'CA1', 58, ''],
    ['Atlético Ottawa', 'CA1', 56, ''],
    ['Vancouver FC', 'CA1', 53, ''],

    // ── Liga Nacional (Honduras) ──────────────────────────────────────
    ['CD Olimpia', 'HN1', 62, ''],
    ['Motagua', 'HN1', 59, ''],
    ['Marathón', 'HN1', 56, ''],
    ['Real España', 'HN1', 54, ''],
    ['CD Victoria', 'HN1', 50, ''],

    // ── LPF Panamá ────────────────────────────────────────────────────
    ['Tauro', 'PA1', 59, ''],
    ['Árabe Unido', 'PA1', 56, ''],
    ['Plaza Amador', 'PA1', 54, ''],
    ['Sporting San Miguelito', 'PA1', 52, ''],
    ['Alianza FC', 'PA1', 49, ''],

    // ── Jamaica Premier ───────────────────────────────────────────────
    ['Cavalier', 'JM1', 57, ''],
    ['Mount Pleasant', 'JM1', 55, ''],
    ['Arnett Gardens', 'JM1', 52, ''],
    ['Portmore United', 'JM1', 51, ''],
    ['Harbour View', 'JM1', 48, ''],

    /* ── E o que faltava nas ligas que já existiam ─────────────────────
     * Um país com três clubes não é um país: a lista de ofertas se esgota,
     * a rede de segurança abre e o jogador é jogado pra fora do próprio
     * campeonato. Uruguai, Chile e Colômbia tinham três cada. */

    // Brasileirão Série A
    ['Juventude', 'BR1', 74, ''],
    ['Mirassol', 'BR1', 75, ''],
    // Brasileirão Série B
    ['América-MG', 'BR2', 66, ''],
    ['Criciúma', 'BR2', 65, ''],
    ['Novorizontino', 'BR2', 64, ''],
    ['Vila Nova', 'BR2', 62, ''],
    ['Chapecoense', 'BR2', 63, ''],
    // Brasileirão Série C
    ['Londrina', 'BR3', 54, ''],
    ['Figueirense', 'BR3', 53, ''],
    ['ABC', 'BR3', 51, ''],
    ['Botafogo-PB', 'BR3', 50, ''],
    // Primera Nacional (Argentina)
    ['All Boys', 'AR2', 60, ''],
    ['San Martín de Tucumán', 'AR2', 61, ''],
    ['Chacarita', 'AR2', 56, ''],
    // Primera División (Uruguai)
    ['Danubio', 'UY1', 63, ''],
    ['Liverpool Montevideo', 'UY1', 64, ''],
    ['Montevideo City', 'UY1', 61, ''],
    ['Cerro Largo', 'UY1', 59, ''],
    // Primera División (Chile)
    ['Universidad Católica', 'CL1', 68, ''],
    ['Huachipato', 'CL1', 63, ''],
    ['Palestino', 'CL1', 61, ''],
    ['Unión Española', 'CL1', 59, ''],
    // Categoría Primera A (Colômbia)
    ['América de Cali', 'CO1', 68, ''],
    ['Independiente Medellín', 'CO1', 67, ''],
    ['Deportivo Cali', 'CO1', 65, ''],
    ['Santa Fe', 'CO1', 66, ''],
    // Premier League
    ['Nottingham Forest', 'EN1', 83, ''],
    ['Bournemouth', 'EN1', 81, ''],
    ['Brentford', 'EN1', 80, ''],
    // Championship
    ['West Bromwich', 'EN2', 70, ''],
    ['Middlesbrough', 'EN2', 70, ''],
    ['Coventry City', 'EN2', 69, ''],
    // League One
    ['Blackpool', 'EN3', 54, ''],
    ['Peterborough', 'EN3', 53, ''],
    ['Stockport', 'EN3', 52, ''],
    // LaLiga
    ['Rayo Vallecano', 'ES1', 78, ''],
    ['Mallorca', 'ES1', 78, ''],
    ['Girona', 'ES1', 80, ''],
    // LaLiga 2
    ['Deportivo La Coruña', 'ES2', 66, ''],
    ['Levante', 'ES2', 65, ''],
    ['Málaga', 'ES2', 63, ''],
    // Serie A
    ['Genoa', 'IT1', 77, ''],
    ['Cagliari', 'IT1', 76, ''],
    ['Como', 'IT1', 78, ''],
    // Serie B
    ['Cremonese', 'IT2', 63, ''],
    ['Spezia', 'IT2', 63, ''],
    ['Catanzaro', 'IT2', 60, ''],
    // Bundesliga
    ['Freiburg', 'DE1', 80, ''],
    ['Hoffenheim', 'DE1', 78, ''],
    ['Mainz 05', 'DE1', 78, ''],
    ['Augsburg', 'DE1', 76, ''],
    // 2. Bundesliga
    ['Colônia', 'DE2', 68, ''],
    ['Fortuna Düsseldorf', 'DE2', 64, ''],
    ['Nürnberg', 'DE2', 62, ''],
    // Ligue 1
    ['Toulouse', 'FR1', 76, ''],
    ['Montpellier', 'FR1', 75, ''],
    ['Reims', 'FR1', 75, ''],
    ['Brest', 'FR1', 77, ''],
    // Ligue 2
    ['Auxerre', 'FR2', 64, ''],
    ['Guingamp', 'FR2', 60, ''],
    ['Troyes', 'FR2', 59, ''],
    // Primeira Liga
    ['Vitória de Setúbal', 'PT1', 70, ''],
    ['Rio Ave', 'PT1', 71, ''],
    ['Boavista', 'PT1', 70, ''],
    ['Famalicão', 'PT1', 72, ''],
    // Eredivisie
    ['Utrecht', 'NL1', 74, ''],
    ['Vitesse', 'NL1', 72, ''],
    ['Heerenveen', 'NL1', 71, ''],
    ['Sparta Rotterdam', 'NL1', 70, ''],
    // Pro League (Bélgica)
    ['Antuérpia', 'BE1', 74, ''],
    ['Gent', 'BE1', 73, ''],
    ['Union Saint-Gilloise', 'BE1', 75, ''],
    // Süper Lig
    ['Başakşehir', 'TR1', 75, ''],
    ['Samsunspor', 'TR1', 72, ''],
    ['Konyaspor', 'TR1', 70, ''],
    // Premier Liga (Rússia)
    ['Lokomotiv Moscou', 'RU1', 74, ''],
    ['Dínamo Moscou', 'RU1', 74, ''],
    ['Krasnodar', 'RU1', 76, ''],
    // Premiership (Escócia)
    ['Aberdeen', 'SC1', 65, ''],
    ['Hibernian', 'SC1', 64, ''],
    ['Dundee United', 'SC1', 61, ''],
    // Super League (Grécia)
    ['Aris', 'GR1', 70, ''],
    ['Panserraikos', 'GR1', 65, ''],
    ['Volos', 'GR1', 64, ''],
    // MLS
    ['Columbus Crew', 'US1', 76, ''],
    ['Philadelphia Union', 'US1', 74, ''],
    ['New York City FC', 'US1', 73, ''],
    ['Sporting KC', 'US1', 71, ''],
    // Liga MX
    ['Toluca', 'MX1', 78, ''],
    ['Pumas', 'MX1', 74, ''],
    ['León', 'MX1', 74, ''],
    // Saudi Pro League
    ['Al Qadsiah', 'SA1', 76, ''],
    ['Al Ettifaq', 'SA1', 74, ''],
    ['Al Taawoun', 'SA1', 73, ''],
    // J1 League
    ['Sanfrecce Hiroshima', 'JP1', 73, ''],
    ['Kashima Antlers', 'JP1', 72, ''],
    ['Gamba Osaka', 'JP1', 70, ''],
    // K League 1
    ['Gangwon FC', 'KR1', 69, ''],
    ['Suwon FC', 'KR1', 67, ''],
    ['Gwangju FC', 'KR1', 68, ''],
    // Persian Gulf Pro League
    ['Foolad', 'IR1', 66, ''],
    ['Gol Gohar', 'IR1', 64, ''],
    // Qatar Stars League
    ['Al Gharafa', 'QA1', 68, ''],
    ['Al Arabi', 'QA1', 65, ''],
    ['Umm Salal', 'QA1', 62, ''],
    // Premier League (Egito)
    ['Ismaily', 'EG1', 66, ''],
    ['Al Masry', 'EG1', 65, ''],
    // Botola Pro
    ['FAR Rabat', 'MA1', 68, ''],
    ['Maghreb de Fès', 'MA1', 64, ''],
    // Premiership (África do Sul)
    ['SuperSport United', 'ZA1', 64, ''],
    ['Stellenbosch', 'ZA1', 63, ''],
    // Ligue 1 (Argélia)
    ['ES Sétif', 'DZ1', 66, ''],
    // Ligue 1 (Tunísia)
    ['US Monastir', 'TN1', 62, ''],
    // NPFL
    ['Remo Stars', 'NG1', 59, ''],
    ['Plateau United', 'NG1', 58, ''],
    // Linafoot
    ['DC Motema Pembe', 'CD1', 59, ''],
    ['Lupopo', 'CD1', 58, ''],
    // A-League
    ['Adelaide United', 'AU1', 66, ''],
    ['Western Sydney', 'AU1', 65, ''],
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
