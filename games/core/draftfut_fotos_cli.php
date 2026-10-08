<?php
/**
 * BUSCA AS FOTOS QUE FALTAM NAS CARTAS DO DRAFT — roda na mão.
 *
 *   php games/core/draftfut_fotos_cli.php            (todas as que faltam)
 *   php games/core/draftfut_fotos_cli.php --n=10     (as 10 melhores)
 *
 * ── POR QUE AS DUAS PASSADAS ANTERIORES NÃO ACHARAM ──────────────────
 *
 * O importador por clube (@see draftfut_nacionalidades_cli.php) pede o elenco,
 * e a chave pública devolve SÓ DEZ JOGADORES POR CLUBE, em ordem alfabética
 * de primeiro nome — o Manchester City vem de "Abdukodir" a "Gerónimo" e
 * acaba. O importador por jogador (@see draftfut_nacoes_cli.php) pede pelo
 * nome, e os que faltam são justamente os de nome curto: buscar "Rodri"
 * devolve Jay Rodriguez, "Ederson" não devolve nada.
 *
 * Aqui a busca usa O NOME COMPLETO, que mora em DFUT_FOTO_BUSCA. "Ederson
 * Moraes", "Gabriel Magalhaes", "Alex Grimaldo" e "Joao Palhinha" a fonte
 * acha na hora — ela tem a foto, só não atende pelo apelido.
 *
 * ── A CONFERÊNCIA É OUTRA, E PRECISA SER ─────────────────────────────
 *
 * As outras passadas conferem o CLUBE, e aqui isso não serve: a fonte está
 * atrasada e devolve o Ederson no Fenerbahçe e o Grimaldo no Atlético. Mas
 * estas cartas JÁ TÊM NACIONALIDADE CERTA — foi o que as duas passadas
 * anteriores e a lista à mão garantiram —, então a nacionalidade vira a
 * tranca, e é uma tranca melhor pra este caso.
 *
 * Nome sozinho não basta: buscar "Ben White" devolve "Benjamin Whiteman", que
 * é inglês como ele. Por isso o nome casa POR PALAVRA INTEIRA, nos dois
 * sentidos — {benjamin, white} não está contido em {benjamin, whiteman},
 * porque "white" não é palavra de lá. Contenção de texto cru deixaria passar.
 *
 * Quem não passar nas duas continua sem foto, e a carta mostra o escudo em
 * marca d'água no lugar. Foto errada numa carta é mentira visível sobre uma
 * pessoa real; faltar foto é só faltar foto.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/copero_clubes.php';
require_once __DIR__ . '/fut_elencos.php';
require_once __DIR__ . '/draftfut.php';

const API_FOTO = 'https://www.thesportsdb.com/api/v1/json/3/';
$DESTINO = __DIR__ . '/../data/draftfut_jogadores.php';

$limite = 0;
foreach ($argv as $a) if (preg_match('/^--n=(\d+)$/', $a, $m)) $limite = (int)$m[1];

/**
 * O nome pelo qual a fonte conhece quem o nosso elenco escreve curto.
 *
 * Só entra quem a busca pelo nome do elenco não acha — e só nome de que eu
 * tenho certeza. Os brasileiros de nome curtíssimo (Pedro, Danilo, Arias)
 * ficam de fora de propósito: chutar o nome completo é chutar a pessoa.
 */
const DFUT_FOTO_BUSCA = [
    'Manchester City|Rodri'              => 'Rodri Hernandez',
    'Manchester City|Ederson'            => 'Ederson Moraes',
    'Manchester City|Savinho'            => 'Savio Moreira',
    'Arsenal|Gabriel'                    => 'Gabriel Magalhaes',
    'Arsenal|Benjamin White'             => 'Ben White',
    'Arsenal|Jorginho'                   => 'Jorginho Frello',
    'Arsenal|Neto'                       => 'Norberto Neto',
    'Real Madrid|Carvajal'               => 'Daniel Carvajal',
    'Real Madrid|Fran García'            => 'Fran Garcia',
    'Barcelona|Pedri'                    => 'Pedri Gonzalez',
    'Barcelona|Gavi'                     => 'Pablo Gavi',
    'AC Milan|Theo Hernández'            => 'Theo Hernandez',
    'AC Milan|Rafael Leão'               => 'Rafael Leao',
    'AC Milan|Morata'                    => 'Alvaro Morata',
    'Liverpool|Luis Díaz'                => 'Luis Diaz',
    'Liverpool|Konstantinos Tsimikas'    => 'Kostas Tsimikas',
    'Newcastle|Bruno Guimarães'          => 'Bruno Guimaraes',
    'Manchester United|Casemiro'         => 'Casemiro',
    'Manchester United|Antony'           => 'Antony',
    'Chelsea|Wesley Fofana'              => 'Wesley Fofana',
    'Bayern de Munique|Palhinha'         => 'Joao Palhinha',
    'Bayer Leverkusen|Grimaldo'          => 'Alex Grimaldo',
    'PSG|Marquinhos'                     => 'Marquinhos Correa',
    'PSG|Vitinha'                        => 'Vitinha Ferreira',
    'PSG|Lee Kang In'                    => 'Lee Kang-In',
    'Juventus|Michele Di Gregorio'       => 'Michele Di Gregorio',
    'Napoli|André-Franck Zambo Anguissa' => 'Zambo Anguissa',
    'Inter de Milão|Yann Aurel Bisseck'  => 'Yann Bisseck',
    'Inter de Milão|Carlos Augusto'      => 'Carlos Augusto',
    'Lazio|Valentin Castellanos'         => 'Valentin Castellanos',
    'Roma|Mathew Ryan'                   => 'Mathew Ryan',
    'Monaco|Alexandr Golovin'            => 'Aleksandr Golovin',
    'Porto|Pepê'                         => 'Pepe Aquino',
    'Sevilla|Suso'                       => 'Jesus Joaquin Fernandez Suso',
    'Real Sociedad|Zubimendi'            => 'Martin Zubimendi',
    'Athletic Club|De Marcos'            => 'Oscar de Marcos',
    'Atlético de Madrid|Reinildo'        => 'Reinildo Mandava',
    'Atlético de Madrid|Azpilicueta'     => 'Cesar Azpilicueta',
    'Atlético de Madrid|Riquelme'        => 'Rodrigo Riquelme',
    'Tottenham|Reguilón'                 => 'Sergio Reguilon',
    'Palmeiras|Flaco López'              => 'Flaco Lopez',
];

function pegaFoto(string $url): ?array
{
    for ($i = 0; $i < 3; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
                                CURLOPT_USERAGENT => 'Mozilla/5.0 (FBA-Draft)']);
        $r = curl_exec($ch);
        $cod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r !== false && $cod === 200) {
            $j = json_decode((string)$r, true);
            if (is_array($j)) return $j;
        }
        sleep(3 + $i * 3);
    }
    return null;
}

/** As palavras de um nome, sem acento. */
function palavras(string $n): array
{
    $k = draftFutChaveNome($n);
    return $k === '' ? [] : explode(' ', $k);
}

/**
 * O nome da fonte é o mesmo que o meu, comparando PALAVRA A PALAVRA?
 *
 * Vale nos dois sentidos — o nosso elenco escreve "Carvajal" e a fonte
 * "Daniel Carvajal"; a fonte escreve "Ederson" e nós também. O que não vale é
 * pedaço de palavra: "white" não casa "whiteman", e é esse o caso que a
 * contenção de texto cru deixaria passar.
 */
function nomeCasa(string $a, string $b): bool
{
    $pa = palavras($a); $pb = palavras($b);
    if (!$pa || !$pb) return false;
    $dentro = fn(array $x, array $y) => !array_diff($x, $y);
    return $dentro($pa, $pb) || $dentro($pb, $pa);
}

/* ── CONFERE AS FOTOS LOCAIS ANTES DE QUALQUER COISA ─────────────────
   Mapa apontando pra arquivo que não existe é bug SILENCIOSO: a carta tem
   `onerror` e esconde a imagem quebrada, então a foto simplesmente não
   aparece e nada reclama. Aconteceu em 08/10/2026 — o script que tirou o
   fundo converteu três JPEG em PNG e o mapa continuou pedindo .jpeg. O
   Marcos viu antes de qualquer teste meu, porque teste meu não havia. */
$locais = draftFutFotosLocais();
$quebrados = [];
foreach ($locais as $chave => $caminho) {
    if (!is_file(__DIR__ . '/../..' . $caminho)) $quebrados[$chave] = $caminho;
}
if ($quebrados) {
    fwrite(STDOUT, count($quebrados) . " FOTO(S) LOCAL(IS) COM CAMINHO QUEBRADO:
");
    foreach ($quebrados as $k => $c) fwrite(STDOUT, "  $k -> $c
");
    fwrite(STDOUT, "Conserte games/data/draftfut_fotos_locais.php antes de seguir.

");
} else {
    fwrite(STDOUT, count($locais) . " fotos locais, todas no disco
");
}

/* As cartas do baralho que ainda não têm foto. */
$dados = is_file($DESTINO) ? (array)require $DESTINO : [];

$faltam = [];
foreach (draftFutBaralho() as $c) {
    if ($c['ovr'] < DFUT_OVR_MIN) continue;
    if (($c['foto'] ?? '') !== '') continue;
    /* Sem nacionalidade não há tranca, e sem tranca não se grava foto. */
    if (($c['nac'] ?? '') === '') continue;
    $faltam[] = $c;
}
usort($faltam, fn($a, $b) => $b['ovr'] <=> $a['ovr']);
if ($limite) $faltam = array_slice($faltam, 0, $limite);

fwrite(STDOUT, count($faltam) . " cartas sem foto (com nação, que é a tranca)\n");

$ok = 0; $nao = 0; $i = 0; $seguidas = 0;
foreach ($faltam as $c) {
    $i++;
    $chave    = $c['clube'] . '|' . $c['nome'];
    $curado   = DFUT_FOTO_BUSCA[$chave] ?? '';
    $termos   = array_values(array_unique(array_filter([$curado, $c['nome']])));
    $achou    = null; $via = '';

    /* NOME DE UMA PALAVRA SÓ EXIGE O CLUBE. "Gabriel" casa com "Gabriel
       Martinelli" palavra a palavra, e os dois são brasileiros do Arsenal na
       fonte — a nacionalidade não separa os dois. Quando o nome é curto e eu
       não tenho o completo, o clube é a única tranca que sobra. */
    $soComClube = $curado === '' && count(palavras($c['nome'])) < 2;

    foreach ($termos as $termo) {
        $r = pegaFoto(API_FOTO . 'searchplayers.php?p=' . rawurlencode(draftFutChaveNome($termo)));
        if ($r === null) {
            $seguidas++;
            fwrite(STDOUT, "[$i/" . count($faltam) . "] {$c['nome']} — API fora ($seguidas seguidas)\n");
            if ($seguidas >= 5) { fwrite(STDOUT, "bloqueado; o resto fica pra outra rodada\n"); break 2; }
            sleep(20);
            continue;
        }
        $seguidas = 0;

        foreach ($r['player'] ?? [] as $x) {
            $img = trim((string)($x['strCutout'] ?? '')) ?: trim((string)($x['strRender'] ?? ''));
            if ($img === '') continue;

            $nomeFonte = (string)($x['strPlayer'] ?? '');
            $nacFonte  = trim((string)($x['strNationality'] ?? ''));
            $clubeF    = (string)($x['strTeam'] ?? '');

            /* O nome tem que casar com O QUE EU PROCUREI. Quando o termo é
               curado, só ele vale: aceitar também o nome curto da carta faria
               a busca por "Gabriel Magalhaes" aceitar o "Gabriel Martinelli"
               que voltasse junto, porque {gabriel} está contido nele. */
            $alvo = $curado !== '' ? $termo : $c['nome'];
            if (!nomeCasa($nomeFonte, $alvo)) continue;

            /* E a tranca: clube igual (melhor) ou nacionalidade igual. A fonte
               está atrasada nos clubes, então a nação é quem salva quase todas
               — e é um dado que eu já tenho conferido. */
            if (draftFutClubeBate($clubeF, [$c['clube'], DFUT_CLUBE_API[$c['clube']] ?? $c['clube']])) {
                $achou = $img; $via = "clube {$clubeF}"; break 2;
            }
            if (!$soComClube && $nacFonte !== '' && $nacFonte === $c['nac']) {
                $achou = $img; $via = "nação {$nacFonte}"; break 2;
            }
        }
        sleep(3);
    }

    if ($achou) {
        /* Grava SÓ a foto: a nacionalidade desta carta já está conferida e
           não se mexe nela aqui. */
        $dados[$c['clube']][$c['nome']] = [
            'nac'  => (string)($dados[$c['clube']][$c['nome']]['nac'] ?? ''),
            'foto' => $achou,
        ];
        $ok++;
        fwrite(STDOUT, sprintf("[%d/%d] %-26s OK por %s\n", $i, count($faltam), $c['nome'], $via));
    } else {
        $nao++;
        fwrite(STDOUT, sprintf("[%d/%d] %-26s sem foto confiável\n", $i, count($faltam), $c['nome']));
    }

    if ($i % 10 === 0) {
        file_put_contents($DESTINO,
            "<?php\n/* Gerado por games/core/draftfut_nacionalidades_cli.php e draftfut_nacoes_cli.php — NÃO EDITE À MÃO. */\nreturn "
            . var_export($dados, true) . ";\n");
    }
    sleep(3);
}

file_put_contents($DESTINO,
    "<?php\n/* Gerado por games/core/draftfut_nacionalidades_cli.php e draftfut_nacoes_cli.php — NÃO EDITE À MÃO. */\nreturn "
    . var_export($dados, true) . ";\n");
fwrite(STDOUT, "pronto: $ok fotos novas, $nao sem foto confiável\n");
