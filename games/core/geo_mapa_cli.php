<?php
/**
 * ── O MAPA-MÚNDI DE PAÍSES DO FBA GEO ────────────────────────────────
 *
 * O modo "que país é esse?" deixa a pessoa clicar no país no mapa, e pra isso
 * a tela precisa do contorno de cada país. Este CLI pega o contorno na fonte
 * e enxuga até caber numa página de jogo.
 *
 * ── DE ONDE VEM ──────────────────────────────────────────────────────
 *
 * NATURAL EARTH, escala 1:110m, pelo repositório do próprio mantenedor
 * (nvkelso/natural-earth-vector). É DOMÍNIO PÚBLICO: "no permission needed"
 * — sem atribuição obrigatória, sem licença a respeitar, sem conta.
 *
 * O primeiro arquivo que eu peguei era de outro repositório, mais leve e mais
 * usado; o README dele dizia, em letras do próprio autor, "legal status of
 * this dataset: dubious?". Num jogo que a liga inteira abre, contorno de país
 * com procedência duvidosa não entra.
 *
 * ── POR QUE ENXUGAR ──────────────────────────────────────────────────
 *
 * O original tem 838 KB, e quase tudo é texto que o jogo não usa: o nome do
 * país em 26 idiomas, códigos de uma dúzia de pontos de vista geopolíticos,
 * população, PIB. Fica a geometria e o código ISO de três letras — que é como
 * o baralho de lugares identifica país (@see geo_lugares_cli.php).
 *
 * As coordenadas também perdem casas decimais: três casas são 110 metros no
 * equador, e num mapa onde o mundo inteiro cabe em 900 pixels ninguém vê a
 * diferença — cada ponto da costa da Noruega com 15 casas é peso puro.
 *
 * ── COMO RODAR ───────────────────────────────────────────────────────
 *
 *   curl -sL https://raw.githubusercontent.com/nvkelso/natural-earth-vector/\
 *master/geojson/ne_110m_admin_0_countries.geojson -o ne110.json
 *   php games/core/geo_mapa_cli.php --de=ne110.json
 *
 * Grava games/data/paises.geo.json. É derivado: rodar de novo reescreve.
 */

const GEO_MAPA_CASAS = 3;     // casas decimais que sobram em cada coordenada

$opts = getopt('', ['de:']);
$de = (string)($opts['de'] ?? '');
if ($de === '' || !is_file($de)) {
    fwrite(STDERR, "uso: php geo_mapa_cli.php --de=ne110.json\n");
    exit(1);
}

$j = json_decode((string)file_get_contents($de), true);
if (empty($j['features'])) { fwrite(STDERR, "nao parece um geojson\n"); exit(1); }
echo count($j['features']), " feições na fonte\n";

/** Arredonda toda coordenada, não importa quão fundo ela esteja aninhada. */
function geoMapaCortar($c)
{
    if (is_array($c) && isset($c[0]) && is_numeric($c[0]) && is_numeric($c[1] ?? null)) {
        return [round((float)$c[0], GEO_MAPA_CASAS), round((float)$c[1], GEO_MAPA_CASAS)];
    }
    return is_array($c) ? array_map('geoMapaCortar', $c) : $c;
}

$saida = []; $semIso = 0;
foreach ($j['features'] as $f) {
    $p = $f['properties'] ?? [];
    /* O ISO_A3 vem "-99" em alguns casos (França, Noruega, Kosovo e outros,
       por disputa de código); o ADM0_A3 do Natural Earth é o mesmo código
       nesses casos e resolve sem inventar nada. */
    $iso = (string)($p['ISO_A3'] ?? '');
    if ($iso === '' || $iso === '-99') $iso = (string)($p['ISO_A3_EH'] ?? '');
    if ($iso === '' || $iso === '-99') $iso = (string)($p['ADM0_A3'] ?? '');
    if ($iso === '' || $iso === '-99') { $semIso++; continue; }

    $saida[] = [
        'type' => 'Feature',
        'id'   => $iso,
        'properties' => new stdClass(),      // o jogo não lê nada daqui
        'geometry'   => [
            'type'        => $f['geometry']['type'],
            'coordinates' => geoMapaCortar($f['geometry']['coordinates']),
        ],
    ];
}

$destino = __DIR__ . '/../data/paises.geo.json';
file_put_contents($destino, json_encode(['type' => 'FeatureCollection', 'features' => $saida]));
echo 'sem código ISO (fora): ', $semIso, "\n";
echo 'gravado: ', realpath($destino), ' — ', count($saida), ' países, ',
     round(filesize($destino) / 1024), " KB\n";
