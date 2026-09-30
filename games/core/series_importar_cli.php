<?php
/**
 * IMPORTA O CATÁLOGO DE SÉRIES. Roda na mão, pela linha de comando.
 *
 *   php games/core/series_importar_cli.php --chave=SUA_CHAVE_TMDB
 *   php games/core/series_importar_cli.php --chave=... --paginas=100 --gravar
 *
 * Sem --gravar ele só mostra o que traria. É o mesmo hábito dos outros
 * importadores daqui: ninguém descobre que o arquivo estava errado depois de
 * duas mil linhas no banco.
 *
 * ── POR QUE DUAS FONTES ──────────────────────────────────────────────
 *
 * TMDB: catálogo de TV, pôster em alta, título e sinopse em português. A nota
 * dele, porém, é a dele — quase ninguém reconhece.
 *
 * IMDb: a nota que as pessoas conhecem. Não tem API pública, mas publica um
 * arquivo com a nota de TUDO, atualizado todo dia, sem conta e sem limite:
 * https://datasets.imdbws.com/title.ratings.tsv.gz (~7 MB). O TMDB entrega o
 * `imdb_id` de cada série, e é por ele que os dois se cruzam.
 *
 * O resultado é nota do IMDb de verdade sem gastar uma requisição por série —
 * que pelo caminho do OMDb custaria duas mil chamadas e um limite diário.
 *
 * ── O QUE NÃO É BAIXADO ──────────────────────────────────────────────
 *
 * A IMAGEM. Fica guardado o caminho ("/abc.jpg") e quem serve o arquivo é o
 * CDN do TMDB, que é pra isso que ele existe e é o que os termos deles pedem.
 * Baixar dois mil pôsteres encheria o servidor e envelheceria: série renova
 * pôster a cada temporada.
 */

require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/series.php';

/* ─── argumentos ────────────────────────────────────────────────────────── */
$chave   = '';
$paginas = 100;          // 20 séries por página → 100 páginas ≈ 2.000 séries
$gravar  = false;
$idioma  = 'pt-BR';

foreach ($argv as $a) {
    if (preg_match('/^--chave=(.+)$/', $a, $m))    $chave   = trim($m[1]);
    if (preg_match('/^--paginas=(\d+)$/', $a, $m)) $paginas = max(1, min(500, (int)$m[1]));
    if ($a === '--gravar')                          $gravar  = true;
    if (preg_match('/^--idioma=(.+)$/', $a, $m))    $idioma  = trim($m[1]);
}

if ($chave === '') $chave = trim((string)getenv('TMDB_API_KEY'));
if ($chave === '') {
    fwrite(STDERR, "Falta a chave do TMDB.\n\n"
        . "  1. crie a conta grátis em https://www.themoviedb.org/signup\n"
        . "  2. pegue a chave em Configurações → API\n"
        . "  3. rode: php games/core/series_importar_cli.php --chave=SUA_CHAVE\n\n"
        . "Dá pra guardar em TMDB_API_KEY no ambiente e omitir --chave.\n");
    exit(1);
}

/* ─── o carteiro ────────────────────────────────────────────────────────── */

/**
 * Uma chamada ao TMDB, com paciência.
 *
 * O TMDB derruba quem aperta demais (429). Em vez de morrer, espera e tenta de
 * novo — uma importação de duas mil séries que cai na página 80 e perde tudo
 * seria pior do que uma que demora mais.
 */
function tmdb(string $caminho, array $query, string $chave): ?array
{
    $query['api_key'] = $chave;
    $url = 'https://api.themoviedb.org/3' . $caminho . '?' . http_build_query($query);

    for ($tentativa = 1; $tentativa <= 5; $tentativa++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $corpo = curl_exec($ch);
        $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http === 200) {
            $j = json_decode((string)$corpo, true);
            return is_array($j) ? $j : null;
        }
        if ($http === 401) {
            fwrite(STDERR, "\nChave recusada pelo TMDB (401). Confira em Configurações → API.\n");
            exit(1);
        }
        if ($http === 404) return null;         // série que sumiu do catálogo
        sleep($tentativa);                       // 429 e erro de rede: espera e insiste
    }
    return null;
}

/**
 * AS NOTAS DO IMDB, do arquivo público — só das séries que interessam.
 *
 * O ARQUIVO NÃO CABE NA MEMÓRIA. São 1,6 milhão de títulos (filme, episódio,
 * curta, tudo), e guardar todos num array do PHP estoura meio giga antes de
 * chegar ao fim. Como o catálogo daqui tem duas mil séries, a linha que não é
 * de nenhuma delas é lida e jogada fora na hora: o que sobra na memória são as
 * duas mil, e não as um milhão e seiscentas.
 *
 * É por isso que esta função roda DEPOIS de o catálogo estar montado, e não
 * antes — só aí se sabe quais ids procurar.
 *
 * Baixa uma vez e guarda no temporário por um dia: o arquivo muda uma vez por
 * dia, e rebaixar a cada teste é desperdício.
 *
 * @param array $queridos imdb_id => true, os únicos que serão guardados
 * @return array imdb_id => ['nota' => float, 'votos' => int]
 */
function imdbNotas(array $queridos): array
{
    if (!$queridos) return [];
    $cache = sys_get_temp_dir() . '/imdb_title_ratings.tsv.gz';

    if (!is_file($cache) || (time() - filemtime($cache)) > 86400) {
        echo "baixando as notas do IMDb (~7 MB)...\n";
        $ch = curl_init('https://datasets.imdbws.com/title.ratings.tsv.gz');
        $fp = fopen($cache, 'wb');
        curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_TIMEOUT => 180, CURLOPT_FOLLOWLOCATION => true]);
        $ok = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $http !== 200) {
            @unlink($cache);
            fwrite(STDERR, "não deu pra baixar as notas do IMDb (HTTP {$http}).\n"
                . "A importação segue sem elas — dá pra rodar de novo depois.\n");
            return [];
        }
    }

    $fh = gzopen($cache, 'rb');
    if (!$fh) return [];
    gzgets($fh);                                  // cabeçalho
    $out = [];
    $faltam = count($queridos);
    while ($faltam > 0 && ($linha = gzgets($fh)) !== false) {
        $t = strpos($linha, "\t");
        if ($t === false) continue;
        $id = substr($linha, 0, $t);
        if (!isset($queridos[$id])) continue;   // 99,9% das linhas morrem aqui
        [, $nota, $votos] = explode("\t", rtrim($linha, "\n")) + [null, null, null];
        $out[$id] = ['nota' => (float)$nota, 'votos' => (int)$votos];
        $faltam--;
    }
    gzclose($fh);
    return $out;
}

/* ─── junta o catálogo ──────────────────────────────────────────────────── */

echo "TMDB: lendo {$paginas} página(s) de séries populares e bem avaliadas...\n";

/* AS DUAS LISTAS JUNTAS. "Popular" traz o que está no ar agora e "top rated"
   traz o clássico que ninguém está comentando esta semana — sozinha, a
   primeira deixaria Sopranos e The Wire de fora. */
$ids = [];
foreach (['/tv/popular', '/tv/top_rated'] as $lista) {
    for ($p = 1; $p <= $paginas; $p++) {
        $r = tmdb($lista, ['language' => $idioma, 'page' => $p], $chave);
        if (!$r || empty($r['results'])) break;
        foreach ($r['results'] as $s) if (!empty($s['id'])) $ids[(int)$s['id']] = true;
        if ($p >= (int)($r['total_pages'] ?? 1)) break;
        if ($p % 10 === 0) echo "  {$lista} p{$p} — " . count($ids) . " séries\n";
    }
}
$ids = array_keys($ids);
echo "TMDB: " . count($ids) . " séries distintas.\n";

/* ─── a ficha de cada uma ───────────────────────────────────────────────── */

$pdo = db();
seriesGarantirTabelas($pdo);

$ins = $pdo->prepare(
    "INSERT INTO series (tmdb_id, imdb_id, titulo, titulo_original, ano_inicio, ano_fim,
                         temporadas, episodios, generos, sinopse, poster, em_exibicao,
                         nota_imdb, votos_imdb, popularidade)
     VALUES (:tmdb, :imdb, :titulo, :orig, :ini, :fim, :temps, :eps, :gen, :sin, :post, :ar,
             :nota, :votos, :pop)
     ON DUPLICATE KEY UPDATE
        imdb_id = VALUES(imdb_id), titulo = VALUES(titulo), titulo_original = VALUES(titulo_original),
        ano_inicio = VALUES(ano_inicio), ano_fim = VALUES(ano_fim), temporadas = VALUES(temporadas),
        episodios = VALUES(episodios), generos = VALUES(generos), sinopse = VALUES(sinopse),
        poster = VALUES(poster), em_exibicao = VALUES(em_exibicao),
        nota_imdb = VALUES(nota_imdb), votos_imdb = VALUES(votos_imdb),
        popularidade = VALUES(popularidade)");

$gravadas = $semPoster = 0;
$queridos = [];      // os imdb_id que o arquivo do IMDb precisa devolver
$porImdb  = [];      // imdb_id => título, só pra amostra no fim

foreach ($ids as $i => $tmdbId) {
    $d = tmdb("/tv/{$tmdbId}", ['language' => $idioma, 'append_to_response' => 'external_ids'], $chave);
    if (!$d || empty($d['name'])) continue;

    $imdbId = trim((string)($d['external_ids']['imdb_id'] ?? ''));
    if ($imdbId !== '') $queridos[$imdbId] = true;

    $poster = (string)($d['poster_path'] ?? '');
    if ($poster === '') $semPoster++;

    $ini = !empty($d['first_air_date']) ? (int)substr($d['first_air_date'], 0, 4) : null;
    $fim = !empty($d['last_air_date'])  ? (int)substr($d['last_air_date'], 0, 4)  : null;
    $noAr = in_array((string)($d['status'] ?? ''), ['Returning Series', 'In Production', 'Planned'], true);

    $linha = [
        ':tmdb'  => $tmdbId,
        ':imdb'  => $imdbId !== '' ? $imdbId : null,
        ':titulo'=> mb_substr((string)$d['name'], 0, 200),
        ':orig'  => mb_substr((string)($d['original_name'] ?? ''), 0, 200) ?: null,
        ':ini'   => $ini,
        ':fim'   => $noAr ? null : $fim,
        ':temps' => isset($d['number_of_seasons'])  ? (int)$d['number_of_seasons']  : null,
        ':eps'   => isset($d['number_of_episodes']) ? (int)$d['number_of_episodes'] : null,
        ':gen'   => mb_substr(implode(', ', array_column($d['genres'] ?? [], 'name')), 0, 160) ?: null,
        ':sin'   => trim((string)($d['overview'] ?? '')) ?: null,
        ':post'  => $poster !== '' ? $poster : null,
        ':ar'    => $noAr ? 1 : 0,
        ':nota'  => null,      // vem depois, do arquivo do IMDb
        ':votos' => null,
        ':pop'   => isset($d['popularity']) ? (float)$d['popularity'] : null,
    ];

    if ($gravar) { $ins->execute($linha); $gravadas++; }
    if ($imdbId !== '') $porImdb[$imdbId] = $linha[':titulo'];

    if (($i + 1) % 100 === 0) echo "  " . ($i + 1) . " de " . count($ids) . "...\n";
}

/* ─── agora sim, as notas ───────────────────────────────────────────────── */

$notasImdb = imdbNotas($queridos);
echo "\nIMDb: " . count($notasImdb) . " de " . count($queridos) . " séries têm nota.\n";

if ($gravar && $notasImdb) {
    $up = $pdo->prepare("UPDATE series SET nota_imdb = ?, votos_imdb = ? WHERE imdb_id = ?");
    foreach ($notasImdb as $id => $n) $up->execute([$n['nota'], $n['votos'], $id]);
}

echo "\n=== AMOSTRA ===\n";
$mostradas = 0;
foreach ($porImdb as $id => $titulo) {
    if ($mostradas++ >= 10) break;
    printf("  %-38s IMDb %s\n", mb_substr($titulo, 0, 38),
        isset($notasImdb[$id])
            ? number_format($notasImdb[$id]['nota'], 1, ',', '')
              . ' (' . number_format($notasImdb[$id]['votos'], 0, '', '.') . ' votos)'
            : '—');
}

echo "\n=== RESUMO ===\n";
printf("  séries lidas      %d\n", count($ids));
printf("  sem nota do IMDb  %d\n", count($queridos) - count($notasImdb));
printf("  sem pôster        %d\n", $semPoster);
printf("  gravadas          %d%s\n", $gravadas, $gravar ? '' : '   (seco — use --gravar)');
if ($gravar) {
    printf("  catálogo agora    %d séries\n", seriesQuantasNoCatalogo($pdo));
}
