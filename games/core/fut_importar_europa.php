<?php
/**
 * IMPORTA AS SEIS LIGAS EUROPEIAS a partir da base de jogadores.
 *
 * O jogo nasceu só com o Brasil. Trazer a Europa é trazer 114 clubes e uns
 * 2.600 jogadores, e isso não se cadastra à mão — mas também não se importa
 * cru.
 *
 * ── A ESCALA NÃO PRECISOU SER MEXIDA ─────────────────────────────────
 *
 * Era o risco óbvio: importar de outra base e ter um Real Madrid dez pontos
 * acima do Flamengo, com o Brasil inteiro virando segunda divisão do mundo. Só
 * que a medição desmentiu o medo — pela mesma conta que o jogo usa
 * (@see futForcaDoElenco), a base dá 68 a 87, e o Brasileirão do jogo vai de
 * 67 a 85. É a mesma régua.
 *
 * Então NADA É RECALIBRADO aqui: o overall entra como está e passa pelos
 * mesmos tetos que todo elenco real do jogo já respeita — 89 pra qualquer um
 * (@see FUT_OVR_TETO_JOGADOR) e o teto por idade do veterano
 * (@see futOvrTetoDaIdade). O Real Madrid fica 87, dois acima do Flamengo, e a
 * Liga Portugal fica abaixo do Brasileirão. Comprimir isso seria inventar uma
 * escala pra corrigir um problema que não existe.
 *
 * ── O QUE PRECISOU DE TRADUÇÃO ───────────────────────────────────────
 *
 * AS POSIÇÕES. A base usa ST, CAM, CDM, RB, LB, CB...; o jogo usa sete (GOL,
 * LAT, ZAG, VOL, MEI, PON, ATA). É o mapa FUT_EUROPA_POSICOES, e é ele que
 * decide se o elenco importado consegue escalar sem improviso.
 *
 * OS NOMES DOS CLUBES. A base escreve "Man Utd", "Paris SG", "OL" e, nos
 * clubes sem licença, um nome inventado: o Inter aparece como "Lombardia FC" e
 * a Lazio como "Latium". Sem FUT_EUROPA_NOMES, o jogo teria um "Lombardia FC"
 * na tabela — e, pior, não acharia o escudo nem a força que o catálogo do
 * Copero já guarda pra esses clubes sob o nome de verdade.
 */

require_once __DIR__ . '/fut_elencos.php';
require_once __DIR__ . '/fut_importar_ovr.php';

/**
 * As seis ligas, com o nome que elas têm na base de origem.
 *
 * `div` é o código que o jogo usa pra divisão, e é de propósito o MESMO do
 * catálogo do Copero: assim o clube europeu cai na liga certa sem um segundo
 * mapa dizendo que ENG1 e EN1 são a mesma coisa.
 */
const FUT_EUROPA_LIGAS = [
    'Premier League'     => ['div' => 'EN1', 'nome' => 'Premier League', 'pais' => 'ENG', 'paisNome' => 'Inglaterra'],
    'LALIGA EA SPORTS'   => ['div' => 'ES1', 'nome' => 'La Liga',        'pais' => 'ESP', 'paisNome' => 'Espanha'],
    'Serie A Enilive'    => ['div' => 'IT1', 'nome' => 'Serie A',        'pais' => 'ITA', 'paisNome' => 'Itália'],
    'Bundesliga'         => ['div' => 'DE1', 'nome' => 'Bundesliga',     'pais' => 'GER', 'paisNome' => 'Alemanha'],
    "Ligue 1 McDonald's" => ['div' => 'FR1', 'nome' => 'Ligue 1',        'pais' => 'FRA', 'paisNome' => 'França'],
    'Liga Portugal'      => ['div' => 'PT1', 'nome' => 'Liga Portugal',  'pais' => 'POR', 'paisNome' => 'Portugal'],
];

/**
 * O NOME DA BASE → O NOME NA TELA.
 *
 * Três motivos pra esta lista existir, e nenhum é estético:
 *
 * 1. ABREVIAÇÃO. "Man Utd", "Newcastle Utd", "Nott'm Forest", "Spurs", "OL",
 *    "OM", "Paris SG" — ninguém chama assim numa tabela.
 * 2. CLUBE SEM LICENÇA. A base troca o nome de quem ela não pode usar:
 *    Inter vira "Lombardia FC", Milan vira "Milano FC", Atalanta vira
 *    "Bergamo Calcio", Lazio vira "Latium". Conferido pelo elenco, não pelo
 *    palpite — "Lombardia FC" é onde estão Lautaro, Bastoni e Barella.
 * 3. CASAR COM O CATÁLOGO. O Copero já tem 84 destes clubes com escudo e
 *    força. Quem entrar com outro nome perde as duas coisas e aparece como
 *    monograma cinza.
 *
 * Quem não está aqui entra com o nome da base, que já é o nome certo.
 */
const FUT_EUROPA_NOMES = [
    // ── Inglaterra ───────────────────────────────────────────────
    'AFC Bournemouth' => 'Bournemouth',
    'Man Utd'         => 'Manchester United',
    'Newcastle Utd'   => 'Newcastle',
    "Nott'm Forest"   => 'Nottingham Forest',
    'Spurs'           => 'Tottenham',
    'Ipswich'         => 'Ipswich Town',
    'Leicester City'  => 'Leicester',

    // ── Espanha ──────────────────────────────────────────────────
    'CA Osasuna'      => 'Osasuna',
    'CD Leganés'      => 'Leganés',
    'D. Alavés'       => 'Alavés',
    'FC Barcelona'    => 'Barcelona',
    'Getafe CF'       => 'Getafe',
    'Girona FC'       => 'Girona',
    'R. Valladolid CF'=> 'Valladolid',
    'RC Celta'        => 'Celta de Vigo',
    'RCD Espanyol'    => 'Espanyol',
    'RCD Mallorca'    => 'Mallorca',
    'Sevilla FC'      => 'Sevilla',
    'UD Las Palmas'   => 'Las Palmas',
    'Valencia CF'     => 'Valencia',
    'Villarreal CF'   => 'Villarreal',

    // ── Itália ───────────────────────────────────────────────────
    'AS Roma'         => 'Roma',
    'Bergamo Calcio'  => 'Atalanta',
    'Latium'          => 'Lazio',
    'Lombardia FC'    => 'Inter de Milão',
    'Milano FC'       => 'AC Milan',
    'SSC Napoli'      => 'Napoli',

    // ── Alemanha ─────────────────────────────────────────────────
    '1. FSV Mainz 05'  => 'Mainz 05',
    'FC Augsburg'      => 'Augsburg',
    'FC Bayern München'=> 'Bayern de Munique',
    'FC St. Pauli'     => 'St. Pauli',
    'Frankfurt'        => 'Eintracht Frankfurt',
    'Leverkusen'       => 'Bayer Leverkusen',
    "M'gladbach"       => "Borussia M'gladbach",
    'SC Freiburg'      => 'Freiburg',
    'SV Werder Bremen' => 'Werder Bremen',
    'TSG Hoffenheim'   => 'Hoffenheim',
    'VfB Stuttgart'    => 'Stuttgart',
    'VfL Bochum 1848'  => 'Bochum',
    'VfL Wolfsburg'    => 'Wolfsburg',

    // ── França ───────────────────────────────────────────────────
    'AJ Auxerre'        => 'Auxerre',
    'AS Monaco'         => 'Monaco',
    'AS Saint-Étienne'  => 'Saint-Étienne',
    'Angers SCO'        => 'Angers',
    'FC Nantes'         => 'Nantes',
    'Havre AC'          => 'Le Havre',
    'LOSC Lille'        => 'Lille',
    'OGC Nice'          => 'Nice',
    'OL'                => 'Lyon',
    'OM'                => 'Marseille',
    'Paris SG'          => 'PSG',
    'RC Lens'           => 'Lens',
    'Stade Brestois 29' => 'Brest',
    'Stade Rennais FC'  => 'Rennes',
    'Stade de Reims'    => 'Reims',
    'Toulouse FC'       => 'Toulouse',

    // ── Portugal ─────────────────────────────────────────────────
    'AVS Futebol SAD' => 'AVS',
    'Boavista FC'     => 'Boavista',
    'Casa Pia AC'     => 'Casa Pia',
    'Estoril Praia'   => 'Estoril',
    'Estrela Amadora' => 'Estrela da Amadora',
    'FC Famalicão'    => 'Famalicão',
    'FC Porto'        => 'Porto',
    'Moreirense FC'   => 'Moreirense',
    'Rio Ave FC'      => 'Rio Ave',
    'SC Braga'        => 'Braga',
    'SL Benfica'      => 'Benfica',
    'Vitória SC'      => 'Vitória de Guimarães',

    /* O Nacional da Madeira e o Nacional-AM são dois clubes e um nome só. O
       catálogo do jogo é indexado POR NOME, e o elenco real é achado pelo slug
       dele: deixar os dois como "Nacional" faria um jogar com o elenco do
       outro, em silêncio. O português ganha o sobrenome porque é ele que está
       chegando — o brasileiro já está no jogo e em telas antigas. */
    'Nacional'        => 'Nacional da Madeira',
];

/**
 * DE ONDE A BASE JOGA PARA ONDE O JOGO JOGA.
 *
 * O meia-atacante (CAM) vira MEI e não ATA: no esquema do jogo o ATA é homem
 * de área, e mandar todo CAM pra lá deixaria o meio vazio e o ataque lotado.
 * Pela mesma razão o CDM vira VOL e o CM vira MEI — são funções, não alturas.
 */
const FUT_EUROPA_POSICOES = [
    'GK'  => 'GOL',
    'CB'  => 'ZAG', 'SW' => 'ZAG',
    'RB'  => 'LAT', 'LB' => 'LAT', 'RWB' => 'LAT', 'LWB' => 'LAT',
    'CDM' => 'VOL',
    'CM'  => 'MEI', 'CAM' => 'MEI',
    'RM'  => 'PON', 'LM' => 'PON', 'RW' => 'PON', 'LW' => 'PON',
    'ST'  => 'ATA', 'CF' => 'ATA',
];

/** Quantos jogadores cada clube leva pro jogo. */
const FUT_EUROPA_ELENCO = 25;

/** O nome do clube como o jogo vai mostrar. */
function futEuropaNomeDoClube(string $daBase): string
{
    return FUT_EUROPA_NOMES[$daBase] ?? $daBase;
}

/** A posição do jogo para a posição da base, ou null se não reconhecida. */
function futEuropaPosicao(string $pos): ?string
{
    return FUT_EUROPA_POSICOES[strtoupper(trim($pos))] ?? null;
}

/**
 * LÊ A BASE E DEVOLVE OS CLUBES DAS SEIS LIGAS, já com o nome da tela.
 *
 * @return array [codigo da liga => [clube => lista de jogadores]]
 */
function futEuropaLerCsv(string $caminho): array
{
    $h = fopen($caminho, 'r');
    if (!$h) throw new RuntimeException('Não abriu o arquivo: ' . $caminho);

    $cab = fgetcsv($h);
    if (!$cab) throw new RuntimeException('Arquivo sem cabeçalho.');
    $idx = array_flip(array_map('trim', $cab));
    foreach (['Name', 'OVR', 'Position', 'Age', 'League', 'Team'] as $col) {
        if (!isset($idx[$col])) throw new RuntimeException('Falta a coluna ' . $col);
    }

    $out = [];
    while (($l = fgetcsv($h)) !== false) {
        $liga = trim((string)($l[$idx['League']] ?? ''));
        if (!isset(FUT_EUROPA_LIGAS[$liga])) continue;

        $pos = futEuropaPosicao((string)($l[$idx['Position']] ?? ''));
        if ($pos === null) continue;

        $clube = trim((string)($l[$idx['Team']] ?? ''));
        if ($clube === '') continue;

        $out[FUT_EUROPA_LIGAS[$liga]['div']][futEuropaNomeDoClube($clube)][] = [
            'nome'  => trim((string)$l[$idx['Name']]),
            'pos'   => $pos,
            'ovr'   => (int)$l[$idx['OVR']],
            'idade' => (int)$l[$idx['Age']],
        ];
    }
    fclose($h);
    return $out;
}

/**
 * ESCOLHE OS QUE VÃO PRO JOGO, cobrindo as posições.
 *
 * Pegar os 25 melhores por overall parece certo e não é: clube grande tem seis
 * atacantes de 80 e um goleiro reserva de 65, e o corte por overall deixaria o
 * elenco com um goleiro só — uma lesão e o jogo põe um zagueiro no gol. A cota
 * de FUT_POSICOES (a mesma do elenco gerado) entra primeiro; o resto das vagas
 * vai pros melhores que sobraram.
 */
function futEuropaEscolherElenco(array $jogadores): array
{
    usort($jogadores, fn($a, $b) => $b['ovr'] <=> $a['ovr']);

    $escolhidos = [];
    $usados = [];
    foreach (FUT_POSICOES as $pos => $cota) {
        $n = 0;
        foreach ($jogadores as $i => $j) {
            if ($n >= $cota['total']) break;
            if (isset($usados[$i]) || $j['pos'] !== $pos) continue;
            $escolhidos[] = $j;
            $usados[$i] = true;
            $n++;
        }
    }

    /* A cota soma 25, então a sobra só entra quando o clube da base tem menos
       de quatro zagueiros (acontece: elenco de 14 na Liga Portugal). */
    foreach ($jogadores as $i => $j) {
        if (count($escolhidos) >= FUT_EUROPA_ELENCO) break;
        if (isset($usados[$i])) continue;
        $escolhidos[] = $j;
        $usados[$i] = true;
    }

    usort($escolhidos, fn($a, $b) => $b['ovr'] <=> $a['ovr']);
    return $escolhidos;
}

/**
 * O ELENCO MÍNIMO PRA JOGAR, e o que fazer quando a base não dá.
 *
 * Quatro clubes da Liga Portugal chegam curtos: o Santa Clara tem catorze
 * jogadores e UM GOLEIRO SÓ. Não é erro de leitura — é o tamanho que eles têm
 * lá. Mas o jogo tem lesão e suspensão, e um clube com um goleiro é um clube
 * que mais cedo ou mais tarde escala um zagueiro no gol; com catorze, uma
 * rodada de desfalques deixa o time sem onze.
 *
 * A COMPLETAÇÃO É EXPLÍCITA E SUBORDINADA. Os que entram vêm do mesmo gerador
 * que já abastece os clubes sem lista (@see futElencoGenerico), semeado pelo
 * nome do clube — então são sempre os mesmos —, e entram SÓ nas posições que
 * faltam e SEMPRE abaixo do pior jogador real daquela posição. O que a base
 * traz continua mandando no elenco: ninguém inventado joga na frente de
 * ninguém de verdade.
 */
const FUT_EUROPA_ELENCO_MINIMO = 18;

/**
 * Completa um elenco curto com jogadores do gerador, só onde falta.
 *
 * @param int $forca a força medida do elenco real, que é o nível dos que entram
 */
function futEuropaCompletarElenco(array $elenco, string $clube, int $forca): array
{
    /* Dois goleiros é o piso inegociável: a posição é a única que não tem
       substituto improvisado que preste. Nas outras, a cota de titular do
       esquema mais um de folga é o bastante pra sobreviver a um desfalque. */
    $minimoPorPosicao = [];
    foreach (FUT_POSICOES as $pos => $cota) {
        $minimoPorPosicao[$pos] = $pos === 'GOL' ? 2 : $cota['titulares'] + 1;
    }

    $tem = array_count_values(array_column($elenco, 'pos'));
    $piorReal = [];
    foreach ($elenco as $j) {
        $pos = $j['pos'];
        $piorReal[$pos] = min($piorReal[$pos] ?? 99, (int)$j['ovr']);
    }

    $banco = futElencoGenerico($clube, $forca);
    usort($banco, fn($a, $b) => $a['ovr'] <=> $b['ovr']);   // os piores primeiro

    foreach ($banco as $g) {
        $pos = $g['pos'];
        $faltam = ($minimoPorPosicao[$pos] ?? 0) - ($tem[$pos] ?? 0);
        $curto = count($elenco) < FUT_EUROPA_ELENCO_MINIMO;
        if ($faltam <= 0 && !$curto) continue;
        if ($faltam <= 0 && ($tem[$pos] ?? 0) >= (FUT_POSICOES[$pos]['total'] ?? 3)) continue;

        // Abaixo do pior real da posição — e nunca acima do nível do clube.
        $teto = min($piorReal[$pos] ?? $forca, $forca) - 1;
        $g['ovr'] = (int)max(25, min($g['ovr'], $teto));

        $elenco[] = $g;
        $tem[$pos] = ($tem[$pos] ?? 0) + 1;
        if (count($elenco) >= FUT_EUROPA_ELENCO_MINIMO) {
            // Já deu o tamanho; só continua se ainda faltar goleiro.
            $faltaAlgo = false;
            foreach ($minimoPorPosicao as $q => $n) if (($tem[$q] ?? 0) < $n) $faltaAlgo = true;
            if (!$faltaAlgo) break;
        }
    }

    usort($elenco, fn($a, $b) => $b['ovr'] <=> $a['ovr']);
    return $elenco;
}

/**
 * OS TETOS DO JOGO, aplicados ao elenco que veio de fora.
 *
 * Não desloca nada: só corta. O 91 do Mbappé vira 89, porque ninguém no jogo
 * passa disso; o veterano de 39 cai pro teto da idade dele, pela mesma razão
 * que já existe no elenco brasileiro — craque velho barato transforma o jogo
 * inteiro em garimpar quarentão (@see FUT_OVR_TETO_IDADE).
 */
function futEuropaAplicarTetos(array $lista): array
{
    foreach ($lista as &$j) {
        $j['ovr'] = (int)min($j['ovr'], futOvrTetoDaIdade((int)$j['idade']));
    }
    unset($j);
    return $lista;
}
