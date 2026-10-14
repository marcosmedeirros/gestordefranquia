<?php
/**
 * ── O BANCO DE LUGARES DO FBA GEO ────────────────────────────────────
 *
 * O jogo é o GeoGuessr da liga: aparece uma foto, você diz onde é. Isso
 * precisa de uma coisa só — uma lista grande de lugares com FOTO, PAÍS e
 * COORDENADA —, e é ela que este CLI monta.
 *
 * ── POR QUE NÃO É O STREET VIEW ──────────────────────────────────────
 *
 * Porque o Street View é pago por imagem desde que o crédito mensal do
 * Google acabou, e a regra do Marcos (14/10/2026) é clara: "quero o grátis,
 * sempre vai ser grátis a escolha". O Mapillary, que seria a alternativa de
 * foto de rua, exige um token de conta — e conta é dele, não minha.
 *
 * Então a fonte é a dupla que não cobra nada e não pede cadastro:
 *   · WIKIDATA diz QUAIS lugares existem, onde ficam e em que país;
 *   · WIKIMEDIA COMMONS tem a foto, o autor e a licença.
 *
 * A foto não é de rua, é a imagem principal do lugar — skyline de cidade,
 * fachada de monumento. Muda o sabor do jogo: em vez de procurar pista em
 * placa e poste, você reconhece o lugar. Pro grupo funciona melhor, e é de
 * graça pra sempre.
 *
 * ── CRÉDITO É OBRIGAÇÃO, NÃO GENTILEZA ───────────────────────────────
 *
 * As fotos são CC BY / CC BY-SA na maioria: dá pra usar à vontade desde que
 * o autor e a licença apareçam. Por isso cada lugar guarda `autor` e
 * `licenca`, e a tela mostra os dois quando a rodada termina.
 *
 * ── COMO RODAR ───────────────────────────────────────────────────────
 *
 *   php games/core/geo_lugares_cli.php            (monta e grava)
 *   php games/core/geo_lugares_cli.php --teto=12  (máximo por país)
 *
 * Grava games/data/geo_lugares.php. É derivado: rodar de novo reescreve.
 *
 * NA MÁQUINA DO MARCOS o PHP não tem o pacote de certificados, e toda
 * chamada HTTPS morre com "HTTP 0". Por isso o CLI aceita as respostas já
 * baixadas (pelo curl do Git Bash, que tem os certificados):
 *
 *   --cidades=arq.json --pontos=arq.json   (pula o download do Wikidata)
 */

const GEO_SPARQL   = 'https://query.wikidata.org/sparql';
const GEO_COMMONS  = 'https://commons.wikimedia.org/w/api.php';
const GEO_UA       = 'FBA Manager (liga amadora; jogo de adivinhar lugares)';
const GEO_TETO_PAIS = 14;   // quantos lugares, no máximo, de cada país

/** Uma consulta ao Wikidata. Devolve as linhas já achatadas. */
function geoSparql(string $query): array
{
    $c = curl_init(GEO_SPARQL . '?query=' . urlencode($query));
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => ['Accept: application/sparql-results+json', 'User-Agent: ' . GEO_UA],
    ]);
    $b = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    if ($code !== 200 || !$b) { fwrite(STDERR, "wikidata: HTTP {$code}\n"); return []; }
    $j = json_decode((string)$b, true);
    $out = [];
    foreach ($j['results']['bindings'] ?? [] as $l) {
        $linha = [];
        foreach ($l as $k => $v) $linha[$k] = (string)($v['value'] ?? '');
        $out[] = $linha;
    }
    return $out;
}

/** "Point(-46.63 -23.55)" → [-23.55, -46.63]. Devolve null se não der. */
function geoPonto(string $wkt): ?array
{
    if (!preg_match('/Point\(\s*([-\d.]+)\s+([-\d.]+)\s*\)/i', $wkt, $m)) return null;
    return [round((float)$m[2], 5), round((float)$m[1], 5)];   // lat, lon
}

/** O nome do arquivo no Commons, a partir da URL do Wikidata. */
function geoArquivo(string $url): string
{
    $p = urldecode(basename(parse_url($url, PHP_URL_PATH) ?: ''));
    return str_replace('_', ' ', $p);
}

/**
 * Autor e licença de até 50 arquivos por chamada.
 *
 * @return array<string,array{autor:string,licenca:string}>
 */
function geoCreditos(array $arquivos): array
{
    $out = [];
    foreach (array_chunk($arquivos, 50) as $lote) {
        $titulos = implode('|', array_map(fn($a) => 'File:' . $a, $lote));
        $url = GEO_COMMONS . '?' . http_build_query([
            'action' => 'query', 'format' => 'json', 'prop' => 'imageinfo',
            'iiprop' => 'extmetadata', 'titles' => $titulos,
        ]);
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => ['User-Agent: ' . GEO_UA]]);
        $b = curl_exec($c);
        curl_close($c);
        $j = json_decode((string)$b, true);
        foreach ($j['query']['pages'] ?? [] as $p) {
            $nome = str_replace('File:', '', (string)($p['title'] ?? ''));
            $m = $p['imageinfo'][0]['extmetadata'] ?? [];
            $autor = trim(strip_tags((string)($m['Artist']['value'] ?? '')));
            $autor = preg_replace('/\s+/', ' ', $autor);
            $out[$nome] = [
                /* Vazio quando o Commons não diz quem fotografou: a tela some
                   com a linha do crédito em vez de escrever um nome que
                   ninguém conferiu. O link pro arquivo original fica. */
                'autor'   => mb_substr($autor, 0, 90),
                'licenca' => mb_substr(trim(strip_tags((string)($m['LicenseShortName']['value'] ?? 'Wikimedia Commons'))), 0, 40),
            ];
        }
        usleep(250000);
    }
    return $out;
}

/* ── O QUE ENTRA NO BARALHO ──────────────────────────────────────────
   Duas listas, e a mistura é de propósito: cidade é a rodada difícil (um
   skyline qualquer pode ser de meio mundo) e monumento é a rodada que todo
   mundo acerta — um jogo só de difícil cansa, um só de fácil não tem graça.
   `nivel` vai junto pra tela poder dosar. */
$opts = getopt('', ['teto:', 'cidades:', 'pontos:', 'creditos:', 'paises:']);
$teto = max(1, (int)($opts['teto'] ?? GEO_TETO_PAIS));

/** Do arquivo salvo quando há um, do Wikidata quando não há. */
$deArquivo2 = static function (?string $caminho, string $consulta) use (&$deArquivo): array {
    return ($caminho !== null && $caminho !== '') ? $deArquivo($caminho) : geoSparql($consulta);
};

/** Lê uma resposta do Wikidata já salva em arquivo. @see o cabeçalho */
$deArquivo = static function (string $caminho): array {
    $j = json_decode((string)@file_get_contents($caminho), true);
    $out = [];
    foreach ($j['results']['bindings'] ?? [] as $l) {
        $linha = [];
        foreach ($l as $k => $v) $linha[$k] = (string)($v['value'] ?? '');
        $out[] = $linha;
    }
    return $out;
};

/* ── SÓ PAÍS QUE EXISTE HOJE ─────────────────────────────────────────
   O P17 do Wikidata guarda TODOS os países que o lugar já teve, e eles vêm
   no mesmo saco: o primeiro teste em produção mostrou "Era Shangqiu, Song"
   (a dinastia), e a múltipla escolha ofereceu "Líbia Italiana" como opção.
   Banguecoque chegou a entrar quatro vezes, uma por reino antigo.

   A trava seria filtrar na própria consulta — mas juntar `?pais é estado
   soberano` com as 3.000 cidades estoura o tempo do WDQS (504). Então a
   lista dos países de hoje vem numa consulta separada, que é pequena e
   instantânea, e o cruzamento é feito aqui, pelo QID. Pelo RÓTULO não dava:
   "Song" e "China" são rótulos, o QID é o que identifica.

   E A MESMA CONSULTA TRAZ O CÓDIGO ISO DE TRÊS LETRAS e a coordenada do
   país, que é o que liga o baralho ao mapa-múndi clicável do modo país: o
   contorno do Natural Earth é identificado por ISO, não por nome em
   português. @see geo_mapa_cli.php */
$CONSULTA_PAISES = 'SELECT ?pais ?paisLabel ?iso ?coord WHERE {
  ?pais wdt:P31 wd:Q3624078 ; wdt:P298 ?iso .
  OPTIONAL { ?pais wdt:P625 ?coord }
  FILTER NOT EXISTS { ?pais wdt:P576 ?fim }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "pt,en". }
}';
$paisesOk = [];
foreach ($deArquivo2($opts['paises'] ?? null, $CONSULTA_PAISES) as $l) {
    $q = (string)($l['pais'] ?? '');
    if ($q === '') continue;
    $xy = geoPonto((string)($l['coord'] ?? ''));
    $paisesOk[$q] = [
        'iso'  => (string)($l['iso'] ?? ''),
        'nome' => (string)($l['paisLabel'] ?? ''),
        'lat'  => $xy[0] ?? null,
        'lon'  => $xy[1] ?? null,
    ];
}
echo '  ', count($paisesOk), " países soberanos de hoje\n";

echo "buscando cidades no Wikidata…\n";
$cidades = isset($opts['cidades']) ? $deArquivo((string)$opts['cidades']) : geoSparql('
SELECT ?item ?itemLabel ?coord ?img ?pais ?paisLabel ?pop WHERE {
  ?item wdt:P31/wdt:P279* wd:Q515 ;
        wdt:P625 ?coord ; wdt:P18 ?img ; wdt:P17 ?pais ; wdt:P1082 ?pop .
  FILTER(?pop > 250000)
  SERVICE wikibase:label { bd:serviceParam wikibase:language "pt,en,es,fr,de,it". }
} ORDER BY DESC(?pop) LIMIT 3000');
echo '  ', count($cidades), " cidades\n";

echo "buscando pontos famosos…\n";
$pontos = isset($opts["pontos"]) ? $deArquivo((string)$opts["pontos"]) : geoSparql('
SELECT ?item ?itemLabel ?coord ?img ?pais ?paisLabel ?visitantes WHERE {
  ?item wdt:P31/wdt:P279* wd:Q570116 ;
        wdt:P625 ?coord ; wdt:P18 ?img ; wdt:P17 ?pais .
  OPTIONAL { ?item wdt:P1174 ?visitantes }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "pt,en,es,fr,de,it". }
} LIMIT 2500');
echo '  ', count($pontos), " pontos turísticos\n";

/* Monta a lista única, com teto por país pra o jogo não virar um passeio
   pela China (499 das 2.000 primeiras cidades eram de lá). */
$porPais = []; $lugares = []; $vistos = []; $exPais = 0;
foreach ([['cidade', $cidades], ['ponto', $pontos]] as [$nivel, $lista]) {
    foreach ($lista as $l) {
        $pais = trim($l['paisLabel'] ?? '');
        $nome = trim($l['itemLabel'] ?? '');
        if ($pais === '' || $nome === '' || preg_match('/^Q\d+$/', $nome)) continue;
        $fichaPais = $paisesOk[trim($l['pais'] ?? '')] ?? null;
        if ($paisesOk && !$fichaPais) { $exPais++; continue; }

        /* ── UM LUGAR, UMA CARTA ─────────────────────────────────────
           A chave era nome+país, e isso deixava passar o MESMO lugar
           várias vezes: o Wikidata guarda os países históricos no mesmo
           P17, então Banguecoque entrava quatro vezes (Ayutthaya, Thonburi,
           Rattanakosin, Konbaung) e Trípoli duas. O filtro de estado
           soberano atual na consulta já derruba a maioria, mas a chave
           certa é o item — é ele que identifica o lugar, não o rótulo. */
        $chave = trim($l['item'] ?? '') !== '' ? $l['item'] : $nome . '|' . $pais;
        if (isset($vistos[$chave])) continue;
        if (($porPais[$pais] ?? 0) >= $teto) continue;
        $xy = geoPonto($l['coord'] ?? '');
        if (!$xy) continue;
        $arq = geoArquivo($l['img'] ?? '');
        if ($arq === '' || !preg_match('/\.(jpe?g|png|webp)$/i', $arq)) continue;

        /* ── FOTO QUE NÃO DÁ PRA ADIVINHAR NÃO ENTRA ─────────────────
           A imagem principal de um lugar no Wikidata às vezes é a PLACA do
           lugar: o primeiro teste sorteou "MAPA ZOO KOŠICE", uma placa de
           madeira com o mapinha do zoológico — não há como adivinhar país
           olhando aquilo, e a rodada vira sorteio. O nome do arquivo entrega
           esses casos antes de eles chegarem ao jogo. */
        if (preg_match('/\b(map|mapa|karte|plan|sign|placa|schild|logo|coat[ _]of[ _]arms|'
                     . 'brasao|bras[aã]o|flag|bandeira|seal|poster|cartaz|diagram|chart)\b/i',
                       str_replace(['_', '-'], ' ', $arq))) continue;

        $vistos[$chave] = true;
        $porPais[$pais] = ($porPais[$pais] ?? 0) + 1;
        $lugares[] = ['nome' => mb_substr($nome, 0, 80), 'pais' => mb_substr($pais, 0, 60),
                      /* O ISO é o que o mapa clicável do modo país entende. */
                      'iso' => (string)($fichaPais['iso'] ?? ''),
                      'lat' => $xy[0], 'lon' => $xy[1], 'img' => $arq, 'nivel' => $nivel];
    }
}
echo '  fora por país que não existe mais: ', $exPais, "\n";
echo 'lugares depois do teto de ', $teto, " por país: ", count($lugares), ' em ', count($porPais), " países\n";

echo "buscando autor e licença no Commons…\n";
if (isset($opts['creditos'])) {
    /* Já baixados por fora (a máquina do Marcos não faz HTTPS no PHP):
       um JSON { "Arquivo.jpg": {"autor": "...", "licenca": "..."} }. */
    $cred = (array)json_decode((string)@file_get_contents((string)$opts['creditos']), true);
} else {
    $cred = geoCreditos(array_values(array_unique(array_column($lugares, 'img'))));
}
$semCredito = 0;
foreach ($lugares as &$l) {
    $c = $cred[$l['img']] ?? null;
    if (!$c) { $semCredito++; $c = ['autor' => '', 'licenca' => '']; }
    $l['autor'] = $c['autor'];
    $l['licenca'] = $c['licenca'];
}
unset($l);
echo '  sem crédito encontrado: ', $semCredito, "\n";

/* ── O QUE JÁ SE SABIA DO STREET VIEW NÃO SE PERDE ───────────────────
   Quem tem panorama é marcado pelo geo_rua_cli.php, que custa uma chamada
   por lugar. Reescrever o baralho do zero apagaria tudo isso em silêncio, e
   o jogo voltaria pras fotos sem ninguém entender por quê. O que sobreviveu
   à nova geração carrega a marca junto — pela coordenada, que é o que
   identifica o lugar no mundo (o nome muda de rótulo, a foto é trocada).
   @see games/core/geo_rua_cli.php */
$antigos = [];
foreach ((array)@include __DIR__ . '/../data/geo_lugares.php' as $a) {
    if (!empty($a['rua'])) $antigos[$a['lat'] . ',' . $a['lon']] = $a;
}
$herdou = 0;
foreach ($lugares as &$l) {
    $a = $antigos[$l['lat'] . ',' . $l['lon']] ?? null;
    if (!$a) continue;
    $l['rua'] = 1;
    if (isset($a['rua_dir'])) $l['rua_dir'] = $a['rua_dir'];
    $herdou++;
}
unset($l);
if ($antigos) echo '  marca de Street View herdada: ', $herdou, ' de ', count($antigos), "\n";

$php = "<?php\n"
     . "/* Os lugares do FBA Geo — GERADO por games/core/geo_lugares_cli.php.\n"
     . "   Wikidata (lugar, país, coordenada) + Wikimedia Commons (foto, autor,\n"
     . "   licença). NÃO EDITE À MÃO: rode o CLI de novo.\n"
     . '   Gerado em ' . date('d/m/Y H:i') . ' — ' . count($lugares) . ' lugares. */' . "\n"
     . 'return ' . var_export($lugares, true) . ";\n";
$destino = __DIR__ . '/../data/geo_lugares.php';
file_put_contents($destino, $php);
echo 'gravado: ', realpath($destino), ' (', round(strlen($php) / 1024), " KB)\n";

/* ── A TABELA DE PAÍSES ──────────────────────────────────────────────
   O mapa clicável precisa saber, de cada país, o código ISO (pra achar o
   contorno), o nome em português (pra escrever no alfinete) e a coordenada
   (pra o alfinete ter onde pousar — San Marino e Singapura não têm contorno
   no mapa de 1:110m, e sem alfinete seriam impossíveis de clicar).

   Só entram os países que o baralho usa: guardar os 196 seria mandar pro
   navegador 58 países que nunca vão aparecer numa rodada. */
$usados = array_flip(array_column($lugares, 'iso'));
$tabela = [];
foreach ($paisesOk as $p) {
    $iso = (string)($p['iso'] ?? '');
    if ($iso === '' || !isset($usados[$iso])) continue;
    $tabela[$iso] = ['nome' => $p['nome'], 'lat' => $p['lat'], 'lon' => $p['lon']];
}
ksort($tabela);
$phpP = "<?php\n"
      . "/* Os países do FBA Geo — GERADO por games/core/geo_lugares_cli.php.\n"
      . "   Código ISO de três letras (o mesmo do contorno em paises.geo.json),\n"
      . "   nome em português e coordenada, tudo do Wikidata. NÃO EDITE À MÃO.\n"
      . '   Gerado em ' . date('d/m/Y H:i') . ' — ' . count($tabela) . ' países. */' . "\n"
      . 'return ' . var_export($tabela, true) . ";\n";
file_put_contents(__DIR__ . '/../data/geo_paises.php', $phpP);
echo 'gravado: geo_paises.php (', count($tabela), " países)\n";
