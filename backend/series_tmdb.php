<?php
/**
 * A CONVERSA COM O TMDB E COM O ARQUIVO DO IMDB.
 *
 * Isto morava dentro do importador de linha de comando. Saiu de lá quando a
 * BUSCA SOB DEMANDA passou a precisar das mesmas quatro coisas — falar com o
 * TMDB, ler as notas do IMDb, montar a linha e gravar. Duas cópias do mesmo
 * código dariam duas séries diferentes pro mesmo id, dependendo de quem
 * gravou primeiro.
 *
 * @see games/core/series_importar_cli.php   o catálogo inteiro, na mão
 * @see backend/series.php                    a busca que cai aqui quando não acha
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/series.php';

/** O idioma que o catálogo fala. */
const SERIES_IDIOMA = 'pt-BR';

/**
 * A chave, na ordem em que faz sentido procurar: ambiente e depois o config
 * local — que é onde ela deve viver, porque config.local.php está no
 * .gitignore e não aparece em histórico de shell nem em lista de processos.
 */
function seriesTmdbChave(?string $usarEsta = null): string
{
    static $chave = null;
    /* UMA CHAVE PASSADA À MÃO MANDA, e fica. É o que deixa o importador
       testar outra chave com --chave= sem mexer em arquivo nenhum: ele avisa
       aqui uma vez, e todas as chamadas seguintes já saem com ela. */
    if ($usarEsta !== null && trim($usarEsta) !== '') return $chave = trim($usarEsta);
    if ($chave !== null) return $chave;

    $chave = trim((string)getenv('TMDB_API_KEY'));
    if ($chave === '') {
        try {
            $cfg = loadConfig();
            $chave = trim((string)($cfg['tmdb']['api_key'] ?? ''));
        } catch (Throwable $e) {
            $chave = '';
        }
    }
    return $chave;
}

/**
 * Uma chamada ao TMDB, com paciência.
 *
 * O TMDB derruba quem aperta demais (429). Em vez de morrer, espera e insiste
 * — uma importação de mil séries que cai na página 80 e perde tudo seria pior
 * do que uma que demora mais.
 *
 * NA WEB A PACIÊNCIA É CURTA. Quem está esperando a busca carregar não pode
 * ficar dezesseis segundos numa tela parada; ali vale falhar rápido e mostrar
 * o que o catálogo local tem.
 */
function seriesTmdb(string $caminho, array $query, int $tentativas = 5): ?array
{
    $chave = seriesTmdbChave();
    if ($chave === '') return null;

    $query['api_key'] = $chave;
    $url = 'https://api.themoviedb.org/3' . $caminho . '?' . http_build_query($query);

    for ($t = 1; $t <= $tentativas; $t++) {
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
        if ($http === 401 || $http === 404) return null;   // chave ruim, ou série que sumiu
        if ($t < $tentativas) sleep($t);
    }
    return null;
}

/**
 * AS NOTAS DO IMDB, do arquivo público — só das séries que interessam.
 *
 * O ARQUIVO NÃO CABE NA MEMÓRIA. São 1,7 milhão de títulos (filme, episódio,
 * curta, tudo) e guardar todos num array do PHP estoura meio giga antes do
 * fim. A linha que não é de nenhuma das séries pedidas é lida e jogada fora na
 * hora: o que sobra é o punhado pedido, e o pico fica em 2 MB.
 *
 * A VARREDURA INTEIRA LEVA 0,3s (medido), e é por isso que a busca sob demanda
 * pode trazer a nota do IMDb na hora em vez de deixar a ficha com um traço.
 *
 * Baixa uma vez e guarda no temporário por um dia — o arquivo muda uma vez por
 * dia, e rebaixar 7 MB a cada busca seria desperdício e lentidão.
 *
 * @param array $queridos imdb_id => true
 * @return array imdb_id => ['nota' => float, 'votos' => int]
 */
function seriesNotasImdb(array $queridos): array
{
    if (!$queridos) return [];
    $cache = sys_get_temp_dir() . '/imdb_title_ratings.tsv.gz';

    if (!is_file($cache) || (time() - filemtime($cache)) > 86400) {
        $ch = curl_init('https://datasets.imdbws.com/title.ratings.tsv.gz');
        $fp = fopen($cache, 'wb');
        curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_TIMEOUT => 180, CURLOPT_FOLLOWLOCATION => true]);
        $ok = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $http !== 200) { @unlink($cache); return []; }
    }

    $fh = gzopen($cache, 'rb');
    if (!$fh) return [];
    gzgets($fh);                                   // cabeçalho
    $out = [];
    $faltam = count($queridos);
    while ($faltam > 0 && ($linha = gzgets($fh)) !== false) {
        $t = strpos($linha, "\t");
        if ($t === false) continue;
        $id = substr($linha, 0, $t);
        if (!isset($queridos[$id])) continue;      // 99,9% das linhas morrem aqui
        [, $nota, $votos] = explode("\t", rtrim($linha, "\n")) + [null, null, null];
        $out[$id] = ['nota' => (float)$nota, 'votos' => (int)$votos];
        $faltam--;
    }
    gzclose($fh);
    return $out;
}

/** A linha do banco a partir da ficha que o TMDB devolveu. */
function seriesLinhaDoTmdb(array $d): ?array
{
    if (empty($d['id']) || empty($d['name'])) return null;

    $ini  = !empty($d['first_air_date']) ? (int)substr($d['first_air_date'], 0, 4) : null;
    $fim  = !empty($d['last_air_date'])  ? (int)substr($d['last_air_date'], 0, 4)  : null;
    $noAr = in_array((string)($d['status'] ?? ''), ['Returning Series', 'In Production', 'Planned'], true);

    return [
        ':tmdb'  => (int)$d['id'],
        ':imdb'  => trim((string)($d['external_ids']['imdb_id'] ?? '')) ?: null,
        ':titulo'=> mb_substr((string)$d['name'], 0, 200),
        ':orig'  => mb_substr((string)($d['original_name'] ?? ''), 0, 200) ?: null,
        ':ini'   => $ini,
        ':fim'   => $noAr ? null : $fim,
        ':temps' => isset($d['number_of_seasons'])  ? (int)$d['number_of_seasons']  : null,
        ':eps'   => isset($d['number_of_episodes']) ? (int)$d['number_of_episodes'] : null,
        ':gen'   => mb_substr(implode(', ', array_column($d['genres'] ?? [], 'name')), 0, 160) ?: null,
        ':sin'   => trim((string)($d['overview'] ?? '')) ?: null,
        ':post'  => trim((string)($d['poster_path'] ?? '')) ?: null,
        ':ar'    => $noAr ? 1 : 0,
        ':nota'  => null,
        ':votos' => null,
        ':pop'   => isset($d['popularity']) ? (float)$d['popularity'] : null,
    ];
}

/** O INSERT com atualização, num lugar só: o importador e a busca gravam igual. */
function seriesPreparaGravacao(PDO $pdo): PDOStatement
{
    return $pdo->prepare(
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
            popularidade = VALUES(popularidade)");
}

/**
 * TRAZ ESTAS SÉRIES DO TMDB pro catálogo, com nota do IMDb.
 *
 * NÃO SOBRESCREVE A NOTA DO IMDB no INSERT: ela vem do arquivo, num UPDATE
 * separado logo abaixo. Se viesse no INSERT com null, uma série que já tinha
 * nota a perderia toda vez que alguém a buscasse de novo.
 *
 * @param array $tmdbIds ids do TMDB
 * @return int quantas entraram ou foram atualizadas
 */
function seriesImportarDoTmdb(PDO $pdo, array $tmdbIds): int
{
    $tmdbIds = array_values(array_unique(array_filter(array_map('intval', $tmdbIds))));
    if (!$tmdbIds) return 0;

    seriesGarantirTabelas($pdo);

    /* O QUE JÁ ESTÁ NO CATÁLOGO NÃO SE BUSCA DE NOVO.
     *
     * Cada id destes é uma chamada ao TMDB, e uma busca por "office" devolve
     * oito resultados dos quais sete já estão aqui há semanas — sete viagens
     * à internet pra regravar exatamente o mesmo registro, com a pessoa
     * olhando a tela parada. O que interessa numa busca sob demanda é o que
     * FALTA; o resto a consulta local já mostrou antes desta função rodar.
     *
     * Quem quiser atualizar o catálogo inteiro tem o importador de linha de
     * comando, que é o lugar certo pra isso.
     */
    $temAqui = [];
    $st = $pdo->query("SELECT tmdb_id FROM series WHERE tmdb_id IN ("
                      . implode(',', array_map('intval', $tmdbIds)) . ")");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $temAqui[(int)$id] = true;

    $tmdbIds = array_values(array_filter($tmdbIds, fn($id) => !isset($temAqui[$id])));
    if (!$tmdbIds) return 0;

    $ins = seriesPreparaGravacao($pdo);

    $queridos = [];
    $feitas = 0;
    foreach ($tmdbIds as $id) {
        /* NA BUSCA A PACIÊNCIA É CURTA (2 tentativas): quem está olhando a tela
           prefere o resultado do catálogo local a dezesseis segundos de espera. */
        $d = seriesTmdb("/tv/{$id}",
                        ['language' => SERIES_IDIOMA, 'append_to_response' => 'external_ids'], 2);
        $linha = $d ? seriesLinhaDoTmdb($d) : null;
        if (!$linha) continue;

        /* Sinopse vazia é o TMDB dizendo que não traduziu. Inglês é melhor que
           um espaço em branco, e é o que o próprio TMDB mostra nesse caso. */
        if ($linha[':sin'] === null) {
            $en = seriesTmdb("/tv/{$id}", ['language' => 'en-US'], 2);
            $linha[':sin'] = trim((string)($en['overview'] ?? '')) ?: null;
        }

        $ins->execute($linha);
        if ($linha[':imdb']) $queridos[$linha[':imdb']] = true;
        $feitas++;
    }

    /* SÓ VARRE O ARQUIVO DO IMDB SE ALGUMA FICOU SEM NOTA.
     *
     * A varredura é de 1,7 milhão de linhas. É rápida (0,3s medido), mas é
     * 0,3s que a pessoa espera olhando a tela — e na maioria das buscas as
     * séries que entraram já saíram daqui com nota da vez anterior, ou nem
     * têm imdb_id. Perguntar ao banco quais faltam custa uma consulta.
     */
    if ($queridos) {
        $st = $pdo->query("SELECT imdb_id FROM series
                            WHERE nota_imdb IS NULL AND imdb_id IN ("
                          . implode(',', array_map([$pdo, 'quote'], array_keys($queridos))) . ")");
        $faltam = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);

        if ($faltam) {
            $notas = seriesNotasImdb($faltam);
            $up = $pdo->prepare("UPDATE series SET nota_imdb = ?, votos_imdb = ? WHERE imdb_id = ?");
            foreach ($notas as $imdb => $n) $up->execute([$n['nota'], $n['votos'], $imdb]);
        }
    }

    return $feitas;
}

/**
 * PROCURA NO TMDB o que o catálogo local não tinha, e guarda.
 *
 * É o que faz o jogo não ter fundo: o importador trouxe as mil e seiscentas
 * mais conhecidas, e quem procurar a série obscura que só ele assiste acha
 * assim mesmo — e a partir daí ela existe pra liga toda, com pôster e nota.
 *
 * @return int quantas séries novas entraram
 */
function seriesProcurarNoTmdb(PDO $pdo, string $termo, int $quantos = 5): int
{
    $termo = trim($termo);
    if (mb_strlen($termo) < 3) return 0;

    $r = seriesTmdb('/search/tv', ['language' => SERIES_IDIOMA, 'query' => $termo, 'page' => 1], 2);
    if (!$r || empty($r['results'])) return 0;

    /* SÓ O QUE TEM CARA DE SÉRIE DE VERDADE. A busca do TMDB devolve muito
       registro solto sem pôster e sem data — entrariam no catálogo pra nunca
       serem marcados por ninguém e atrapalhar a próxima busca.

       CINCO E NÃO OITO: cada um é uma chamada à internet com a pessoa
       esperando, e quem procura uma série pelo nome quer AQUELA — a oitava
       resposta de uma busca por título nunca é a certa. */
    $ids = [];
    foreach ($r['results'] as $s) {
        if (empty($s['id']) || empty($s['poster_path'])) continue;
        $ids[] = (int)$s['id'];
        if (count($ids) >= $quantos) break;
    }

    return seriesImportarDoTmdb($pdo, $ids);
}
