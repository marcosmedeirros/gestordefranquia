<?php
/**
 * OS CLUBES EUROPEUS DO JOGO DE CARREIRA — seis ligas, 114 clubes.
 *
 * GERADO por games/core/fut_importar_europa_cli.php. NÃO EDITE À MÃO: a
 * próxima importação apaga a mudança. Escudo novo vai em FUT_ESCUDOS
 * (@see fut_clubes_br.php), que é lido depois deste arquivo e ganha dele.
 *
 * ── Os campos ─────────────────────────────────────────────────────────
 *
 *   nome    como aparece na tela
 *   div     a liga: EN1, ES1, IT1, DE1, FR1, PT1 — o mesmo código do Copero
 *   pais    ENG, ESP, ITA, GER, FRA, POR
 *   forca   1 a 100, a MESMA régua do Brasil. Ela SAI DO ELENCO, não foi
 *           escrita: é a média dos onze melhores (@see futForcaDoElenco)
 *   escudo  a URL do catálogo do Copero, ou vazio (aí vira monograma)
 *
 * Nome de clube é referência factual. O jogo não é afiliado nem endossado por
 * nenhum deles e não hospeda escudo.
 */

const FUT_CLUBES_EU = [
    // ── Bundesliga ─────────────────────────────
    ['Bayern de Munique', 'DE1', 'GER', 84, 'https://r2.thesportsdb.com/images/media/team/badge/01ogkh1716960412.png'],
    ['Bayer Leverkusen', 'DE1', 'GER', 83, 'https://r2.thesportsdb.com/images/media/team/badge/3x9k851726760113.png'],
    ['Borussia Dortmund', 'DE1', 'GER', 83, 'https://r2.thesportsdb.com/images/media/team/badge/tqo8ge1716960353.png'],
    ['RB Leipzig', 'DE1', 'GER', 80, 'https://r2.thesportsdb.com/images/media/team/badge/zjgapo1594244951.png'],
    ['Eintracht Frankfurt', 'DE1', 'GER', 78, 'https://r2.thesportsdb.com/images/media/team/badge/rurwpy1473453269.png'],
    ['Freiburg', 'DE1', 'GER', 77, 'https://r2.thesportsdb.com/images/media/team/badge/urwtup1473453288.png'],
    ['Stuttgart', 'DE1', 'GER', 77, 'https://r2.thesportsdb.com/images/media/team/badge/yppyux1473454085.png'],
    ['Wolfsburg', 'DE1', 'GER', 77, 'https://r2.thesportsdb.com/images/media/team/badge/ci9trv1778399557.png'],
    ['Borussia M\'gladbach', 'DE1', 'GER', 77, 'https://r2.thesportsdb.com/images/media/team/badge/rn6vsx1580141896.png'],
    ['Hoffenheim', 'DE1', 'GER', 76, 'https://r2.thesportsdb.com/images/media/team/badge/9hwvb21621593919.png'],
    ['Union Berlin', 'DE1', 'GER', 76, 'https://r2.thesportsdb.com/images/media/team/badge/q0o5001599679795.png'],
    ['Mainz 05', 'DE1', 'GER', 75, 'https://upload.wikimedia.org/wikipedia/commons/1/1b/1._FSV_Mainz_05_logo.svg'],
    ['Werder Bremen', 'DE1', 'GER', 75, 'https://r2.thesportsdb.com/images/media/team/badge/tkvqan1716960454.png'],
    ['Augsburg', 'DE1', 'GER', 74, 'https://r2.thesportsdb.com/images/media/team/badge/xqyyvq1473453233.png'],
    ['Heidenheim', 'DE1', 'GER', 73, ''],
    ['Bochum', 'DE1', 'GER', 73, ''],
    ['St. Pauli', 'DE1', 'GER', 71, ''],
    ['Holstein Kiel', 'DE1', 'GER', 70, ''],

    // ── Premier League ─────────────────────────────
    ['Manchester City', 'EN1', 'ENG', 85, 'https://r2.thesportsdb.com/images/media/team/badge/vwpvry1467462651.png'],
    ['Arsenal', 'EN1', 'ENG', 85, 'https://r2.thesportsdb.com/images/media/team/badge/uyhbfe1612467038.png'],
    ['Liverpool', 'EN1', 'ENG', 84, 'https://r2.thesportsdb.com/images/media/team/badge/kfaher1737969724.png'],
    ['Tottenham', 'EN1', 'ENG', 83, 'https://r2.thesportsdb.com/images/media/team/badge/3dhd0j1605371995.png'],
    ['Manchester United', 'EN1', 'ENG', 82, 'https://r2.thesportsdb.com/images/media/team/badge/xzqdr11517660252.png'],
    ['Aston Villa', 'EN1', 'ENG', 82, 'https://r2.thesportsdb.com/images/media/team/badge/cgvxt61785930389.png'],
    ['Newcastle', 'EN1', 'ENG', 82, '/games/img/escudos/newcastle.png'],
    ['Chelsea', 'EN1', 'ENG', 81, 'https://r2.thesportsdb.com/images/media/team/badge/pbf4ul1782638263.png'],
    ['West Ham', 'EN1', 'ENG', 79, 'https://r2.thesportsdb.com/images/media/team/badge/hfum4l1599931799.png'],
    ['Crystal Palace', 'EN1', 'ENG', 79, 'https://r2.thesportsdb.com/images/media/team/badge/ia6i3m1656014992.png'],
    ['Brighton', 'EN1', 'ENG', 78, 'https://r2.thesportsdb.com/images/media/team/badge/ywypts1448810904.png'],
    ['Nottingham Forest', 'EN1', 'ENG', 78, 'https://upload.wikimedia.org/wikipedia/en/e/e5/Nottingham_Forest_F.C._logo.svg'],
    ['Everton', 'EN1', 'ENG', 77, 'https://r2.thesportsdb.com/images/media/team/badge/eqayrf1523184794.png'],
    ['Bournemouth', 'EN1', 'ENG', 77, 'https://r2.thesportsdb.com/images/media/team/badge/y08nak1534071116.png'],
    ['Wolves', 'EN1', 'ENG', 77, '/games/img/escudos/wolves.png'],
    ['Fulham', 'EN1', 'ENG', 76, 'https://r2.thesportsdb.com/images/media/team/badge/xwwvyt1448811086.png'],
    ['Brentford', 'EN1', 'ENG', 76, 'https://r2.thesportsdb.com/images/media/team/badge/grv1aw1546453779.png'],
    ['Southampton', 'EN1', 'ENG', 75, 'https://r2.thesportsdb.com/images/media/team/badge/ggqtd01621593274.png'],
    ['Leicester', 'EN1', 'ENG', 75, ''],
    ['Ipswich Town', 'EN1', 'ENG', 75, ''],

    // ── La Liga ─────────────────────────────
    ['Real Madrid', 'ES1', 'ESP', 87, 'https://r2.thesportsdb.com/images/media/team/badge/vwvwrw1473502969.png'],
    ['Barcelona', 'ES1', 'ESP', 83, 'https://r2.thesportsdb.com/images/media/team/badge/wq9sir1639406443.png'],
    ['Atlético de Madrid', 'ES1', 'ESP', 82, 'https://r2.thesportsdb.com/images/media/team/badge/0ulh3q1719984315.png'],
    ['Athletic Club', 'ES1', 'ESP', 81, 'https://r2.thesportsdb.com/images/media/team/badge/68w7fe1639408210.png'],
    ['Real Sociedad', 'ES1', 'ESP', 80, 'https://r2.thesportsdb.com/images/media/team/badge/vptvpr1473502986.png'],
    ['Girona', 'ES1', 'ESP', 80, 'https://r2.thesportsdb.com/images/media/team/badge/kfu7zu1659897499.png'],
    ['Villarreal', 'ES1', 'ESP', 79, 'https://r2.thesportsdb.com/images/media/team/badge/vrypqy1473503073.png'],
    ['Real Betis', 'ES1', 'ESP', 79, 'https://r2.thesportsdb.com/images/media/team/badge/2oqulv1663245386.png'],
    ['Valencia', 'ES1', 'ESP', 78, 'https://r2.thesportsdb.com/images/media/team/badge/dm8l6o1655594864.png'],
    ['Sevilla', 'ES1', 'ESP', 78, 'https://r2.thesportsdb.com/images/media/team/badge/vpsqqx1473502977.png'],
    ['Mallorca', 'ES1', 'ESP', 77, 'https://r2.thesportsdb.com/images/media/team/badge/ssptsx1473503730.png'],
    ['Rayo Vallecano', 'ES1', 'ESP', 77, 'https://r2.thesportsdb.com/images/media/team/badge/nzhu941655595465.png'],
    ['Osasuna', 'ES1', 'ESP', 77, 'https://r2.thesportsdb.com/images/media/team/badge/rvspvt1473502960.png'],
    ['Celta de Vigo', 'ES1', 'ESP', 76, 'https://r2.thesportsdb.com/images/media/team/badge/xfjtku1690436219.png'],
    ['Getafe', 'ES1', 'ESP', 76, 'https://r2.thesportsdb.com/images/media/team/badge/eyh2891655594452.png'],
    ['Las Palmas', 'ES1', 'ESP', 75, ''],
    ['Leganés', 'ES1', 'ESP', 74, ''],
    ['Alavés', 'ES1', 'ESP', 74, ''],
    ['Espanyol', 'ES1', 'ESP', 74, ''],
    ['Valladolid', 'ES1', 'ESP', 72, ''],

    // ── Ligue 1 ─────────────────────────────
    ['PSG', 'FR1', 'FRA', 84, '/games/img/escudos/psg.png'],
    ['Lyon', 'FR1', 'FRA', 78, 'https://r2.thesportsdb.com/images/media/team/badge/blk9771656932845.png'],
    ['Lille', 'FR1', 'FRA', 78, 'https://r2.thesportsdb.com/images/media/team/badge/2giize1534005340.png'],
    ['Marseille', 'FR1', 'FRA', 78, 'https://r2.thesportsdb.com/images/media/team/badge/c6bazh1779212287.png'],
    ['Lens', 'FR1', 'FRA', 77, 'https://r2.thesportsdb.com/images/media/team/badge/3pxoum1598797195.png'],
    ['Monaco', 'FR1', 'FRA', 77, 'https://r2.thesportsdb.com/images/media/team/badge/exjf5l1678808044.png'],
    ['Nice', 'FR1', 'FRA', 77, 'https://r2.thesportsdb.com/images/media/team/badge/msy7ly1621593859.png'],
    ['Brest', 'FR1', 'FRA', 76, 'https://r2.thesportsdb.com/images/media/team/badge/z69be41598797026.png'],
    ['Rennes', 'FR1', 'FRA', 76, 'https://r2.thesportsdb.com/images/media/team/badge/ypturx1473504818.png'],
    ['Reims', 'FR1', 'FRA', 75, 'https://r2.thesportsdb.com/images/media/team/badge/xcrw1b1592925946.png'],
    ['Montpellier', 'FR1', 'FRA', 74, 'https://r2.thesportsdb.com/images/media/team/badge/8wn9x31750879448.png'],
    ['Toulouse', 'FR1', 'FRA', 74, 'https://r2.thesportsdb.com/images/media/team/badge/17eqox1688449282.png'],
    ['Nantes', 'FR1', 'FRA', 74, 'https://r2.thesportsdb.com/images/media/team/badge/mla9x61678808018.png'],
    ['Strasbourg', 'FR1', 'FRA', 73, 'https://r2.thesportsdb.com/images/media/team/badge/b8k77w1766625501.png'],
    ['Le Havre', 'FR1', 'FRA', 72, ''],
    ['Saint-Étienne', 'FR1', 'FRA', 71, 'https://r2.thesportsdb.com/images/media/team/badge/m4ej831656423694.png'],
    ['Auxerre', 'FR1', 'FRA', 71, 'https://r2.thesportsdb.com/images/media/team/badge/lzdtbf1658753355.png'],
    ['Angers', 'FR1', 'FRA', 71, ''],

    // ── Serie A ─────────────────────────────
    ['Inter de Milão', 'IT1', 'ITA', 84, 'https://r2.thesportsdb.com/images/media/team/badge/ryhu6d1617113103.png'],
    ['AC Milan', 'IT1', 'ITA', 83, 'https://r2.thesportsdb.com/images/media/team/badge/wvspur1448806617.png'],
    ['Napoli', 'IT1', 'ITA', 81, 'https://r2.thesportsdb.com/images/media/team/badge/l8qyxv1742982541.png'],
    ['Juventus', 'IT1', 'ITA', 81, 'https://r2.thesportsdb.com/images/media/team/badge/uxf0gr1742983727.png'],
    ['Roma', 'IT1', 'ITA', 80, 'https://r2.thesportsdb.com/images/media/team/badge/jwro2s1760820674.png'],
    ['Lazio', 'IT1', 'ITA', 80, 'https://r2.thesportsdb.com/images/media/team/badge/rwqyvs1448806608.png'],
    ['Atalanta', 'IT1', 'ITA', 79, 'https://r2.thesportsdb.com/images/media/team/badge/qix5ku1780561327.png'],
    ['Bologna', 'IT1', 'ITA', 78, 'https://r2.thesportsdb.com/images/media/team/badge/2qi1u31655592366.png'],
    ['Fiorentina', 'IT1', 'ITA', 78, 'https://r2.thesportsdb.com/images/media/team/badge/hc8nhu1656098030.png'],
    ['Torino', 'IT1', 'ITA', 76, 'https://r2.thesportsdb.com/images/media/team/badge/xxprty1448806802.png'],
    ['Como', 'IT1', 'ITA', 75, 'https://r2.thesportsdb.com/images/media/team/badge/02x81t1627405841.png'],
    ['Monza', 'IT1', 'ITA', 75, ''],
    ['Lecce', 'IT1', 'ITA', 74, ''],
    ['Genoa', 'IT1', 'ITA', 74, 'https://r2.thesportsdb.com/images/media/team/badge/52s8dn1655553600.png'],
    ['Parma', 'IT1', 'ITA', 74, ''],
    ['Cagliari', 'IT1', 'ITA', 73, 'https://r2.thesportsdb.com/images/media/team/badge/wvsvxt1447534471.png'],
    ['Hellas Verona', 'IT1', 'ITA', 73, ''],
    ['Empoli', 'IT1', 'ITA', 72, ''],
    ['Udinese', 'IT1', 'ITA', 72, 'https://r2.thesportsdb.com/images/media/team/badge/vwvstr1448806811.png'],
    ['Venezia', 'IT1', 'ITA', 72, 'https://r2.thesportsdb.com/images/media/team/badge/vbiget1781026964.png'],

    // ── Liga Portugal ─────────────────────────────
    ['Benfica', 'PT1', 'POR', 80, 'https://r2.thesportsdb.com/images/media/team/badge/hj4kyc1781152436.png'],
    ['Sporting CP', 'PT1', 'POR', 78, 'https://r2.thesportsdb.com/images/media/team/badge/5hiuk71783137875.png'],
    ['Porto', 'PT1', 'POR', 78, 'https://r2.thesportsdb.com/images/media/team/badge/xu47rb1628855600.png'],
    ['Braga', 'PT1', 'POR', 76, 'https://r2.thesportsdb.com/images/media/team/badge/skbiwo1785775946.png'],
    ['Vitória de Guimarães', 'PT1', 'POR', 72, 'https://r2.thesportsdb.com/images/media/team/badge/af52z61628855707.png'],
    ['Famalicão', 'PT1', 'POR', 71, 'https://r2.thesportsdb.com/images/media/team/badge/a3f4er1563653256.png'],
    ['Casa Pia', 'PT1', 'POR', 71, ''],
    ['Rio Ave', 'PT1', 'POR', 71, 'https://r2.thesportsdb.com/images/media/team/badge/ngbklq1628851239.png'],
    ['Arouca', 'PT1', 'POR', 71, ''],
    ['Gil Vicente', 'PT1', 'POR', 70, ''],
    ['Boavista', 'PT1', 'POR', 70, 'https://r2.thesportsdb.com/images/media/team/badge/usi98v1628853974.png'],
    ['Estoril', 'PT1', 'POR', 69, ''],
    ['Moreirense', 'PT1', 'POR', 69, ''],
    ['Estrela da Amadora', 'PT1', 'POR', 69, ''],
    ['Farense', 'PT1', 'POR', 69, ''],
    ['Santa Clara', 'PT1', 'POR', 68, ''],
    ['AVS', 'PT1', 'POR', 68, ''],
    ['Nacional da Madeira', 'PT1', 'POR', 67, ''],
];
