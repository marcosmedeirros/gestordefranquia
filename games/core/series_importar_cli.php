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
/* A conversa com o TMDB e a leitura do arquivo do IMDb moram aqui desde que
   a busca sob demanda passou a precisar delas também. @see backend/series_tmdb.php */
require_once __DIR__ . '/../../backend/series_tmdb.php';

/* ─── argumentos ────────────────────────────────────────────────────────── */
$chave   = '';
$paginas = 100;          // 20 séries por página → 100 páginas ≈ 2.000 séries
$gravar  = false;
$soSinopses = false;
$idioma  = 'pt-BR';

foreach ($argv as $a) {
    if (preg_match('/^--chave=(.+)$/', $a, $m))    $chave   = trim($m[1]);
    if (preg_match('/^--paginas=(\d+)$/', $a, $m)) $paginas = max(1, min(500, (int)$m[1]));
    if ($a === '--gravar')                          $gravar  = true;
    /* SÓ AS SINOPSES: repassar o catálogo inteiro pra preencher trezentas
       sinopses custa 1.600 chamadas e sete minutos. Este atalho pula direto
       pra elas, e serve pra rodar de novo quando o TMDB traduzir mais. */
    if ($a === '--so-sinopses')                     $soSinopses = true;
    if (preg_match('/^--idioma=(.+)$/', $a, $m))    $idioma  = trim($m[1]);
}

/* A CHAVE, NA ORDEM EM QUE FAZ SENTIDO PROCURAR: o que veio na linha de
   comando manda, depois o ambiente, e por último o config local — que é
   onde ela deve viver pra valer. config.local.php está no .gitignore e não
   aparece em histórico de shell nem em lista de processos, que é onde um
   --chave= fica exposto pra quem mais estiver na máquina. */
/* O que veio na linha de comando manda e vale pra todas as chamadas daqui
   pra frente; sem isso, seriesTmdb() ia buscar a chave do config e ignorar
   o --chave= que a pessoa acabou de digitar. */
$chave = seriesTmdbChave($chave !== '' ? $chave : null);
if ($chave === '') {
    require_once __DIR__ . '/../../backend/helpers.php';
    $cfg = loadConfig();
    $chave = trim((string)($cfg['tmdb']['api_key'] ?? ''));
}
if ($chave === '') {
    fwrite(STDERR, "Falta a chave do TMDB.\n\n"
        . "  1. crie a conta grátis em https://www.themoviedb.org/signup\n"
        . "  2. pegue a chave em Configurações → API\n"
        . "  3. rode: php games/core/series_importar_cli.php --chave=SUA_CHAVE\n\n"
        . "Ou ponha em backend/config.local.php e rode sem --chave:\n"
        . "  'tmdb' => ['api_key' => 'SUA_CHAVE'],\n");
    exit(1);
}

/* ─── o carteiro ────────────────────────────────────────────────────────── */

/* ─── junta o catálogo ──────────────────────────────────────────────────── */

$pdo = db();
seriesGarantirTabelas($pdo);

/* O atalho pula a leitura do catálogo e cai direto nas sinopses. */
if ($soSinopses) { $gravar = true; $ids = []; $queridos = []; $porImdb = [];
                   $gravadas = $semPoster = 0; goto sinopses; }

echo "TMDB: lendo {$paginas} página(s) de séries populares e bem avaliadas...\n";

/* AS DUAS LISTAS JUNTAS. "Popular" traz o que está no ar agora e "top rated"
   traz o clássico que ninguém está comentando esta semana — sozinha, a
   primeira deixaria Sopranos e The Wire de fora. */
$ids = [];
foreach (['/tv/popular', '/tv/top_rated'] as $lista) {
    for ($p = 1; $p <= $paginas; $p++) {
        $r = seriesTmdb($lista, ['language' => $idioma, 'page' => $p]);
        if (!$r || empty($r['results'])) break;
        foreach ($r['results'] as $s) if (!empty($s['id'])) $ids[(int)$s['id']] = true;
        if ($p >= (int)($r['total_pages'] ?? 1)) break;
        if ($p % 10 === 0) echo "  {$lista} p{$p} — " . count($ids) . " séries\n";
    }
}
$ids = array_keys($ids);
echo "TMDB: " . count($ids) . " séries distintas.\n";

/* ─── a ficha de cada uma ───────────────────────────────────────────────── */

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
    $d = seriesTmdb("/tv/{$tmdbId}", ['language' => $idioma, 'append_to_response' => 'external_ids']);
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

sinopses:
/* ─── a sinopse que o português não tinha ───────────────────────────────── */

/* NEM TODA SÉRIE FOI TRADUZIDA. O TMDB devolve a sinopse vazia quando não há
   versão em português, e uma em cada cinco fichas abria com "sem sinopse no
   catálogo" — inclusive séries grandes. Inglês é melhor que nada, e é o que o
   próprio TMDB mostra nesse caso.

   Só pra quem ficou sem: são ~300 chamadas a mais, e não 1.600. */
if ($gravar) {
    $st = $pdo->query("SELECT tmdb_id FROM series WHERE sinopse IS NULL OR sinopse = ''");
    $orfas = $st->fetchAll(PDO::FETCH_COLUMN);
    if ($orfas) {
        echo "\nsinopse: " . count($orfas) . " sem português, buscando em inglês...\n";
        $upSin = $pdo->prepare("UPDATE series SET sinopse = ? WHERE tmdb_id = ?");
        $achadas = 0;
        foreach ($orfas as $tid) {
            $en = seriesTmdb("/tv/{$tid}", ['language' => 'en-US']);
            $txt = trim((string)($en['overview'] ?? ''));
            if ($txt === '') continue;
            $upSin->execute([$txt, (int)$tid]);
            $achadas++;
        }
        echo "sinopse: " . $achadas . " preenchidas em inglês.\n";
    }
}

/* ─── agora sim, as notas ───────────────────────────────────────────────── */

$notasImdb = $soSinopses ? [] : seriesNotasImdb($queridos);
if (!$soSinopses) {
    echo "\nIMDb: " . count($notasImdb) . " de " . count($queridos) . " séries têm nota.\n";
}

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
