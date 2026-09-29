<?php
/**
 * PROCURA OS ESCUDOS QUE FALTAM, e recusa os duvidosos.
 *
 *   php games/core/fut_buscar_escudos_cli.php [--gravar]
 *
 * Sem --gravar só mostra o que achou. Com --gravar, escreve o bloco pronto
 * pra colar em FUT_ESCUDOS (@see fut_clubes_br.php) em
 * games/data/escudos_achados.txt.
 *
 * ── A REGRA É DESCONFIAR ─────────────────────────────────────────────
 *
 * O dono do jogo já disse: escudo errado é pior do que escudo nenhum, porque
 * escudo errado parece uma afirmação e o monograma cinza só parece uma falta.
 * Uma busca por nome erra fácil — "Nacional" existe na Madeira, no Amazonas e
 * no Uruguai; "Rapid" existe em Viena e em Bucareste.
 *
 * Por isso o CASAMENTO É POR PAÍS, e não por nome: o jogo sabe de que país é
 * cada clube, a fonte devolve o país de cada resultado, e o que não bater é
 * descartado sem dó. Sobra clube sem escudo, e tudo bem — é o que já
 * acontecia.
 *
 * O nome também é conferido depois de tirar acento e sigla de sociedade, pra
 * "FC Porto" casar com "Porto" mas "Porto" não casar com "Portimonense".
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/fut_europa.php';

$gravar = in_array('--gravar', $argv, true);

/** O país do jogo => o país como a fonte escreve. */
const ESCUDO_PAISES = [
    'BRA' => 'Brazil',      'ENG' => 'England',     'ESP' => 'Spain',
    'ITA' => 'Italy',       'GER' => 'Germany',     'FRA' => 'France',
    'POR' => 'Portugal',    'NED' => 'Netherlands', 'TUR' => 'Turkey',
    'BEL' => 'Belgium',     'AUT' => 'Austria',     'SCO' => 'Scotland',
    'GRE' => 'Greece',      'DEN' => 'Denmark',     'NOR' => 'Norway',
    'SWE' => 'Sweden',      'POL' => 'Poland',      'ROU' => 'Romania',
    'CZE' => 'Czech Republic', 'UKR' => 'Ukraine',  'CRO' => 'Croatia',
    'HUN' => 'Hungary',     'CYP' => 'Cyprus',      'AZE' => 'Azerbaijan',
    'FIN' => 'Finland',
];

/**
 * OS NOMES QUE A FONTE USA e o jogo não.
 *
 * Só entra aqui o que a busca por nome não acha sozinha. A maioria é sigla de
 * sociedade que o jogo tirou ("Lech Poznan" contra "Lech Poznań") ou nome
 * traduzido ("Copenhague", "Dínamo Kiev").
 */
const ESCUDO_BUSCA = [
    'Bochum'                => 'VfL Bochum',
    'Heidenheim'            => '1. FC Heidenheim',
    'Holstein Kiel'         => 'Holstein Kiel',
    'St. Pauli'             => 'St Pauli',
    'Mainz 05'              => '1. FSV Mainz 05',
    'Nottingham Forest'     => 'Nottingham Forest',
    'Ipswich Town'          => 'Ipswich Town',
    'Las Palmas'            => 'UD Las Palmas',
    'Leganés'               => 'CD Leganes',
    'Alavés'                => 'Deportivo Alaves',
    'Espanyol'              => 'RCD Espanyol',
    'Valladolid'            => 'Real Valladolid',
    'Le Havre'              => 'Le Havre',
    'Hellas Verona'         => 'Hellas Verona',
    'Casa Pia'              => 'Casa Pia',
    'Estrela da Amadora'    => 'Estrela Amadora',
    'Nacional da Madeira'   => 'CD Nacional',
    'RB Salzburgo'          => 'Red Bull Salzburg',
    'Shakhtar'              => 'Shakhtar Donetsk',
    'Dínamo Kiev'           => 'Dynamo Kyiv',
    'Dínamo Zagreb'         => 'Dinamo Zagreb',
    'Royal Antwerp'         => 'Royal Antwerp',
    'Viktoria Plzen'        => 'Viktoria Plzen',
    'Leicester'             => 'Leicester City FC',
    'Malmö'                 => 'Malmo',
    'CFR Cluj'              => 'CFR 1907 Cluj',
    'Rapid Bucareste'       => 'Rapid Bucuresti',
    'Le Havre'              => 'Le Havre AC',
    'AZ'                    => 'AZ Alkmaar',
    'Ferencváros'           => 'Ferencvaros',
    'AZ'                    => 'AZ Alkmaar',
    'Malmö'                 => 'Malmo FF',
    'APOEL'                 => 'APOEL Nicosia',
    'Lech Poznan'           => 'Lech Poznan',
    'FCSB'                  => 'FCSB',
    'Qarabag'               => 'Qarabag',
    'Pogon Szczecin'        => 'Pogon Szczecin',
    'Universitatea Craiova' => 'Universitatea Craiova',
    'Rapid Bucareste'       => 'Rapid Bucuresti',
    'CFR Cluj'              => 'CFR Cluj',
    'Lillestrøm'            => 'Lillestrom',
    'HJK'                   => 'HJK Helsinki',
    'Midtjylland'           => 'FC Midtjylland',
    'AGF'                   => 'AGF Aarhus',
    'Botafogo-PB'           => 'Botafogo PB',
];

/**
 * Tira acento, pontuação e sigla de sociedade pra comparar dois nomes.
 *
 * SEM ICONV. O //TRANSLIT do Windows nao transcreve: ele avisa 'illegal
 * character' e devolve a string picada, entao "Ferencvaros" nunca batia com
 * "Ferencváros" e seis clubes certos eram recusados como se fossem outro
 * time. O mapa e o mesmo que futSlugDoClube ja usa.
 */
function escudoChave(string $n): string
{
    $n = strtr($n, [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
        'é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o','ø'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n','ý'=>'y','ż'=>'z','ź'=>'z','ž'=>'z',
        'ł'=>'l','ń'=>'n','ś'=>'s','š'=>'s','ć'=>'c','č'=>'c',
        'ř'=>'r','ě'=>'e','ğ'=>'g','ı'=>'i','ş'=>'s','ă'=>'a',
        'â'=>'a','ț'=>'t','ș'=>'s','đ'=>'d','æ'=>'ae','ß'=>'ss',
    ]);
    $n = mb_strtolower($n, 'UTF-8');
    $n = strtolower(preg_replace('/[^a-zA-Z0-9 ]/', ' ', $n));
    $siglas = ['fc', 'cf', 'sc', 'ac', 'afc', 'rc', 'rcd', 'cd', 'ud', 'sk', 'fk', 'bk',
               'if', 'aik', 'vfl', 'vfb', 'tsg', 'sv', 'kv', 'kaa', 'krc', 'rsc', 'as',
               'ss', 'ssc', 'us', 'ogc', 'losc', 'aj', 'sad', 'club', 'clube', 'de', 'do', 'da'];
    $p = array_filter(preg_split('/\s+/', $n), fn($x) => $x !== '' && !in_array($x, $siglas, true));
    return implode(' ', $p);
}

/**
 * BUSCA COM CACHE E TEIMOSIA.
 *
 * A fonte e gratuita e corta a torneira depois de algumas dezenas de pedidos
 * seguidos — rodando os 47 de uma vez, metade voltava vazia e a lista de
 * "sem escudo" mudava a cada execucao, o que e pior do que demorar: da a
 * impressao de que o clube nao existe la quando ele existe.
 *
 * O cache em disco resolve o resto: refazer a busca depois de ajustar um nome
 * so pede o que ainda nao foi pedido.
 */
function escudoBuscar(string $termo): array
{
    static $ctx = null;
    if ($ctx === null) $ctx = stream_context_create(['http' => ['timeout' => 25, 'user_agent' => 'Mozilla/5.0']]);

    $cache = sys_get_temp_dir() . '/fut_busca_' . md5($termo) . '.json';
    if (is_file($cache)) {
        $j = json_decode((string)file_get_contents($cache), true);
        if (is_array($j)) return $j;
    }

    $u = 'https://www.thesportsdb.com/api/v1/json/3/searchteams.php?t=' . rawurlencode($termo);
    for ($tentativa = 1; $tentativa <= 4; $tentativa++) {
        $d = @file_get_contents($u, false, $ctx);
        if ($d !== false) {
            $j = json_decode($d, true);
            $times = is_array($j['teams'] ?? null) ? $j['teams'] : [];
            /* RESPOSTA VAZIA NAO VIRA CACHE. Quando a fonte esta limitando ela
               devolve 200 com "teams": null — que e indistinguivel de "esse
               clube nao existe". Guardar isso congelava o clube como perdido
               pra sempre, e foi por isso que Lillestrom e HJK, achados numa
               volta, sumiram na seguinte. */
            if ($times) file_put_contents($cache, json_encode($times));
            else { sleep($tentativa * 2); continue; }
            return $times;
        }
        sleep($tentativa * 2);   // a torneira volta sozinha; e so esperar
    }
    fwrite(STDERR, "  (a fonte nao respondeu para: $termo)
");
    return [];
}

// ═════════════════════════════════════════════════════════════════════
$clubes = futClubesDoJogo();
$faltam = array_filter($clubes, fn($c) => ($c['escudo'] ?? '') === '');

$achados = [];
$recusados = [];
$semNada = [];

foreach ($faltam as $nome => $c) {
    $pais = ESCUDO_PAISES[$c['pais'] ?? 'BRA'] ?? 'Brazil';
    $termo = ESCUDO_BUSCA[$nome] ?? $nome;
    $alvo = escudoChave($termo);

    $achou = null;
    foreach (escudoBuscar($termo) as $t) {
        // O PAÍS É A TRAVA. Nome bate fácil demais entre continentes.
        if (($t['strCountry'] ?? '') !== $pais) continue;
        if (($t['strSport'] ?? 'Soccer') !== 'Soccer') continue;

        /* NEM TODO TIME DO CLUBE E O TIME DO CLUBE. A busca devolve sub-21,
           feminino e time B com o mesmo nome e o mesmo pais — o Leicester
           veio como "Leicester U21", que tem escudo diferente. */
        if (preg_match('/\b(U ?\d{2}|Under|Youth|Women|Ladies|Feminino|Reserves|II|B)\b/i',
                       (string)($t['strTeam'] ?? ''))) { $recusados[] = "$nome — time de base ou feminino"; continue; }

        $badge = (string)($t['strBadge'] ?? $t['strTeamBadge'] ?? '');
        if ($badge === '') continue;

        $nomeFonte = escudoChave((string)($t['strTeam'] ?? ''));
        $alt = escudoChave((string)($t['strTeamAlternate'] ?? ''));
        $casa = $nomeFonte === $alvo || $alt === $alvo
             || str_contains($nomeFonte, $alvo) || str_contains($alvo, $nomeFonte);
        if (!$casa) { $recusados[] = "$nome ≠ " . ($t['strTeam'] ?? '?') . " ($pais)"; continue; }

        $achou = ['url' => $badge, 'fonte' => (string)$t['strTeam'],
                  'liga' => (string)($t['strLeague'] ?? '?')];
        break;
    }

    if ($achou) $achados[$nome] = $achou;
    else $semNada[] = $nome . ' [' . $pais . ']';
    usleep(900000);   // a fonte é gratuita; não vale martelar
}

// ── O relatório ──────────────────────────────────────────────────────
echo "ACHADOS (" . count($achados) . " de " . count($faltam) . "):\n";
foreach ($achados as $nome => $a) {
    printf("  %-24s -> %-26s %s\n", $nome, $a['fonte'], $a['liga']);
}
if ($recusados) {
    echo "\nRECUSADOS pelo nome (" . count($recusados) . "):\n  " . implode("\n  ", $recusados) . "\n";
}
if ($semNada) echo "\nSEM ESCUDO ainda (" . count($semNada) . "):\n  " . implode(', ', $semNada) . "\n";

if ($gravar) {
    $linhas = [];
    foreach ($achados as $nome => $a) {
        $linhas[] = sprintf("    %-26s => '%s',", var_export($nome, true), $a['url']);
    }
    $txt = "// Achados por fut_buscar_escudos_cli.php, conferidos pelo país.\n"
         . implode("\n", $linhas) . "\n";
    file_put_contents(__DIR__ . '/../data/escudos_achados.txt', $txt);
    echo "\ngravado em games/data/escudos_achados.txt\n";
}
