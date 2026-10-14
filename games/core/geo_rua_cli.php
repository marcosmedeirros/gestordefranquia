<?php
/**
 * ── QUAIS LUGARES DO FBA GEO TÊM STREET VIEW ─────────────────────────
 *
 * O jogo pode mostrar um panorama navegável em vez da foto parada — é o
 * pedido do Marcos de "andar no lugar" —, mas só onde o Google tem imagem.
 * Street View não cobre o mundo: países inteiros ficam de fora, e rodada que
 * abre numa tela cinza escrito "sem imagens aqui" é pior que foto nenhuma.
 *
 * Este CLI marca o campo `rua` de cada lugar em games/data/geo_lugares.php,
 * perguntando ao METADATA do Street View — que é de graça e sem limite de
 * uso, ao contrário da imagem em si. A pergunta é feita UMA VEZ, aqui fora;
 * em partida ninguém consulta nada.
 *
 * ── COMO RODAR ───────────────────────────────────────────────────────
 *
 *   1. php games/core/geo_rua_cli.php --cfg=saida.cfg
 *        escreve um arquivo de configuração do curl, um pedido por lugar.
 *   2. curl -K saida.cfg          (no Git Bash, que tem os certificados;
 *                                  o PHP desta máquina não faz HTTPS)
 *   3. php games/core/geo_rua_cli.php --respostas=pasta/
 *        lê o que voltou e regrava o arquivo de lugares com o `rua`.
 *
 * Em servidor com HTTPS funcionando, `php geo_rua_cli.php` sozinho faz tudo.
 *
 * A CHAVE é a mesma da Maps Embed API: GOOGLE_MAPS_KEY no ambiente ou
 * google.maps_key no config.local.php. @see games/core/geo.php, geoChaveMaps
 */

require_once __DIR__ . '/geo.php';

const GEO_RUA_META = 'https://maps.googleapis.com/maps/api/streetview/metadata';
/* O raio da busca por panorama, em metros. A coordenada do Wikidata é o
   centro do lugar — numa praça, num parque, às vezes no meio de um prédio —,
   e o panorama mais próximo pode estar a uma quadra. Mil metros acha a rua
   sem sair do lugar; mais que isso começaria a mostrar o subúrbio. */
const GEO_RUA_RAIO = 1000;

$opts = getopt('', ['cfg:', 'respostas:']);
$arquivoLugares = __DIR__ . '/../data/geo_lugares.php';
$lugares = (array)require $arquivoLugares;
if (!$lugares) { fwrite(STDERR, "sem lugares em {$arquivoLugares}\n"); exit(1); }
echo count($lugares), " lugares no baralho\n";

$chave = geoChaveMaps();
if ($chave === '') {
    fwrite(STDERR, "SEM CHAVE. Configure GOOGLE_MAPS_KEY ou google.maps_key no "
                 . "config.local.php — a chave da Maps Embed API, criada no "
                 . "console do Google Cloud. Sem ela o jogo segue com as fotos.\n");
    exit(1);
}

/** A URL do metadata de um lugar. */
$url = static function (array $l) use ($chave): string {
    return GEO_RUA_META . '?' . http_build_query([
        'location' => $l['lat'] . ',' . $l['lon'],
        'radius'   => GEO_RUA_RAIO,
        'source'   => 'outdoor',    // panorama de rua, não o interior de uma loja
        'key'      => $chave,
    ]);
};

/* ── PASSO 1: O ARQUIVO PRO CURL ─────────────────────────────────────
   Uma resposta por arquivo, numerada pelo índice do lugar: é o que deixa o
   passo 3 casar resposta com lugar sem depender da ordem de chegada. */
if (isset($opts['cfg'])) {
    $destino = (string)$opts['cfg'];
    $pasta   = rtrim(dirname($destino), '/\\') . '/rua';
    @mkdir($pasta, 0777, true);
    $cfg = '';
    foreach ($lugares as $i => $l) {
        $cfg .= 'url = "' . $url($l) . "\"\n"
              . 'output = "' . $pasta . '/' . $i . ".json\"\n";
    }
    file_put_contents($destino, $cfg);
    echo "gravado {$destino} (", count($lugares), " pedidos)\n",
         "agora rode:  curl -s -K \"{$destino}\"\n",
         "e depois:    php games/core/geo_rua_cli.php --respostas=\"{$pasta}\"\n";
    exit(0);
}

/* ── PASSO 3 (ou tudo de uma vez, onde o HTTPS funciona) ─────────────── */
$com = 0; $sem = 0; $erro = 0;
foreach ($lugares as $i => &$l) {
    if (isset($opts['respostas'])) {
        $arq = rtrim((string)$opts['respostas'], '/\\') . '/' . $i . '.json';
        $corpo = is_file($arq) ? (string)file_get_contents($arq) : '';
    } else {
        $c = curl_init($url($l));
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $corpo = (string)curl_exec($c);
        curl_close($c);
    }
    $j = json_decode($corpo, true);
    $st = (string)($j['status'] ?? '');

    if ($st === 'OK') {
        $l['rua'] = 1;
        /* PARA ONDE A CÂMERA OLHA. O panorama fica na rua e o lugar está ao
           lado dele: abrir virado pro norte mostraria um muro qualquer. O
           rumo do panorama até a coordenada do lugar põe o assunto na frente
           de quem chega — e quem quiser olhar o resto gira, que é a graça. */
        $plat = (float)($j['location']['lat'] ?? $l['lat']);
        $plon = (float)($j['location']['lng'] ?? $l['lon']);
        $l['rua_dir'] = geoRumo($plat, $plon, (float)$l['lat'], (float)$l['lon']);
        /* O ID DO PANORAMA QUE ESTE CLI VIU. Sem ele, em partida quem escolhe
           é o Google — e ele escolhe entre tudo que há ali, foto esférica de
           visitante inclusive: o primeiro teste, na Torre Eiffel, abriu numa
           foto de turista em vez do Street View da rua. @see geoRua */
        if (!empty($j['pano_id'])) $l['rua_pano'] = (string)$j['pano_id'];
        $com++;
    } elseif ($st === 'ZERO_RESULTS') {
        unset($l['rua'], $l['rua_dir'], $l['rua_pano']);
        $sem++;
    } else {
        /* REQUEST_DENIED, OVER_QUERY_LIMIT, arquivo faltando: o lugar fica
           como estava. Marcar como "sem panorama" por causa de uma chave
           recusada apagaria a cobertura do baralho inteiro em silêncio. */
        $erro++;
        if ($erro <= 3) fwrite(STDERR, "  {$l['nome']}: " . ($st ?: 'sem resposta')
            . ' ' . (string)($j['error_message'] ?? '') . "\n");
    }
}
unset($l);

echo "com panorama: {$com}\nsem panorama: {$sem}\nnão respondidos: {$erro}\n";
if ($com === 0) { fwrite(STDERR, "nada marcado — arquivo não foi tocado\n"); exit(1); }

$php = "<?php\n"
     . "/* Os lugares do FBA Geo — GERADO por games/core/geo_lugares_cli.php e\n"
     . "   marcado por games/core/geo_rua_cli.php. NÃO EDITE À MÃO.\n"
     . '   Street View conferido em ' . date('d/m/Y H:i') . " — {$com} de "
     . count($lugares) . " lugares têm panorama. */\n"
     . 'return ' . var_export($lugares, true) . ";\n";
file_put_contents($arquivoLugares, $php);
echo 'gravado: ', realpath($arquivoLugares), "\n";

/** O rumo (0–359°, 0 = norte) de um ponto até o outro. */
function geoRumo(float $lat1, float $lon1, float $lat2, float $lon2): int
{
    $f1 = deg2rad($lat1); $f2 = deg2rad($lat2);
    $dl = deg2rad($lon2 - $lon1);
    $y = sin($dl) * cos($f2);
    $x = cos($f1) * sin($f2) - sin($f1) * cos($f2) * cos($dl);
    return (int)round(fmod(rad2deg(atan2($y, $x)) + 360, 360));
}
