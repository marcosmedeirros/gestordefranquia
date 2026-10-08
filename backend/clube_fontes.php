<?php
/**
 * ── DE ONDE SAEM OS ÁLBUNS, FILMES E LIVROS DO CLUBE ─────────────────
 *
 * Pedido do Marcos (08/10/2026): "se conseguir conectar apis nos album,
 * filmes e livros, pra trazer aleatorio e ter um leque maior".
 *
 * As listas à mão em clube_semana.php (70 álbuns, 70 filmes, 83 livros)
 * davam uma enquete bonita e um problema de calendário: uma rodada por
 * semana sorteando dez, e em pouco mais de um ano a liga já viu tudo. No
 * livro era pior — o sorteio é dentro do gênero, e um gênero tem oito.
 *
 * ── O POOL, E POR QUE NÃO SE PERGUNTA À API NA HORA ──────────────────
 *
 * Nada aqui é buscado no instante em que a enquete nasce. As fontes
 * abastecem uma tabela, e o sorteio sai dela. São três razões, e a
 * terceira é a que importa:
 *
 *   · a enquete nasce no primeiro acesso depois das 9h, e seria um GM
 *     pagando com a espera dele por quatro chamadas de rede;
 *   · repetir pergunta gasta cota alheia à toa;
 *   · API fora do ar não pode significar sexta sem enquete. Com o pool, a
 *     fonte pode estar caída a semana inteira que o sorteio não sente.
 *
 * ── AS TRÊS FONTES, E POR QUE ESTAS ──────────────────────────────────
 *
 *   · FILMES — TMDB, que a liga já usa nas Séries e cuja chave já mora no
 *     servidor. `discover` ordenado por número de votos entrega filme que
 *     as pessoas viram, com título em português e capa.
 *
 *   · LIVROS — Open Library, livre e sem chave. A busca por `subject`
 *     ordenada por nota é o que separa "livro conhecido do gênero" de
 *     "obra em domínio público que ninguém leu": a rota /subjects/ devolve
 *     Thomas Hardy e Stendhal, a /search.json com sort=rating devolve
 *     Sanderson e Douglas Adams.
 *
 *   · ÁLBUNS — Deezer, livre e sem chave, mas com uma volta. Perguntar por
 *     gênero não serve: o endpoint de artistas por gênero devolve o mesmo
 *     chart brasileiro para Rock, Pop e MPB. Então quem dá o rumo é a
 *     CURADORIA QUE JÁ EXISTE — os 70 álbuns escritos à mão viram uma lista
 *     de artistas, e de cada um se pede a discografia. O leque multiplica
 *     por dez sem perder o critério de quem montou a lista.
 *
 * Em qualquer delas, falha é silêncio: a função devolve lista vazia, o pool
 * não cresce naquela rodada e o sorteio segue com o que já tem.
 */

require_once __DIR__ . '/db.php';

/** Quantos candidatos cada abastecida tenta trazer, no máximo. */
const CLUBE_FONTE_LOTE = 60;

/** Abaixo disto o pool é considerado magro e pede reforço. */
const CLUBE_FONTE_MINIMO = 40;

/**
 * Os gêneros do livro traduzidos para o vocabulário do Open Library.
 *
 * As chaves são exatamente as de CLUBE_LIVROS — mexer num nome de gênero lá
 * sem mexer aqui faz o gênero cair no fallback curado, silenciosamente.
 */
const CLUBE_FONTE_SUBJECTS = [
    'Romance'                 => ['romance', 'love_stories'],
    'Fantasia'                => ['fantasy', 'magic'],
    'Ficção científica'      => ['science_fiction', 'dystopia'],
    'Mistério e suspense'    => ['mystery', 'thriller'],
    'Terror'                  => ['horror', 'ghost_stories'],
    'Drama'                   => ['fiction', 'literature'],
    'Clássicos'              => ['classic_literature', 'classics'],
    'Biografia e não-ficção' => ['biography', 'autobiography'],
    'Quadrinhos e mangás'    => ['comics', 'graphic_novels'],
    /* `personal_development` tem quase nada com nota no Open Library — a
       estante inteira vinha com um livro. Estes três rendem. */
    'Desenvolvimento pessoal' => ['self-help', 'success', 'conduct_of_life'],
];

/* ═══════════════════════════ O POOL ═══════════════════════════════════ */

function clubeFonteTabela(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS clube_semana_pool (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(12) NOT NULL,
        genero VARCHAR(40) NOT NULL DEFAULT '',
        titulo VARCHAR(190) NOT NULL,
        autor VARCHAR(140) NOT NULL DEFAULT '',
        ano SMALLINT NULL,
        capa VARCHAR(255) NOT NULL DEFAULT '',
        fonte VARCHAR(20) NOT NULL DEFAULT '',
        usado_em DATE NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_obra (tipo, genero, titulo, autor),
        KEY idx_sorteio (tipo, genero, usado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Uma chamada HTTP de leitura. Falha é null, nunca exceção. */
function clubeFonteGet(string $url): ?array
{
    $ctx = stream_context_create(['http' => [
        'timeout' => 12,
        'ignore_errors' => true,
        /* O Open Library pede identificação de quem chama, e é educado
           atender: sem isto o rate limit aperta. */
        'header' => "User-Agent: FBA-Clube/1.0 (fbabrasil.com.br)\r\n",
    ]]);
    $j = @file_get_contents($url, false, $ctx);
    if ($j === false) return null;
    $d = json_decode((string)$j, true);
    return is_array($d) ? $d : null;
}

/**
 * Guarda os candidatos, ignorando o que já está lá.
 *
 * INSERT IGNORE em cima da chave única: a mesma obra voltando de outra
 * busca não vira linha repetida, e duas abastecidas simultâneas não brigam.
 *
 * @param array $itens cada um [titulo, autor, ano, capa]
 * @return int quantos entraram de fato
 */
function clubeFonteGuardar(PDO $pdo, string $tipo, string $genero, array $itens, string $fonte): int
{
    if (!$itens) return 0;
    clubeFonteTabela($pdo);
    $st = $pdo->prepare('INSERT IGNORE INTO clube_semana_pool
                         (tipo, genero, titulo, autor, ano, capa, fonte) VALUES (?,?,?,?,?,?,?)');
    $n = 0;
    foreach ($itens as $i) {
        $titulo = trim((string)($i[0] ?? ''));
        if ($titulo === '') continue;
        try {
            $st->execute([$tipo, $genero, mb_substr($titulo, 0, 190),
                          mb_substr(trim((string)($i[1] ?? '')), 0, 140),
                          $i[2] !== null && $i[2] !== '' ? (int)$i[2] : null,
                          mb_substr((string)($i[3] ?? ''), 0, 255), $fonte]);
            $n += $st->rowCount();
        } catch (Throwable $e) {
            /* Uma linha ruim não derruba o lote. */
            error_log('[clube-fontes] guardar: ' . $e->getMessage());
        }
    }
    return $n;
}

/* ═══════════════════════════ FILMES ═══════════════════════════════════ */

/** A chave do TMDB, de onde a aba de Séries já a lê. */
function clubeFonteChaveTmdb(): string
{
    static $k = null;
    if ($k !== null) return $k;
    $k = '';
    foreach (['TMDB_API_KEY', 'TMDB_KEY'] as $v) {
        if (getenv($v)) { $k = (string)getenv($v); return $k; }
    }
    foreach (['/config.local.php', '/config.php'] as $arq) {
        $p = __DIR__ . $arq;
        if (!is_file($p)) continue;
        $txt = (string)@file_get_contents($p);
        if (preg_match('/TMDB[_A-Z]*\W+[\'"]([A-Za-z0-9]{20,})[\'"]/', $txt, $m)) { $k = $m[1]; return $k; }
    }
    return $k;
}

/**
 * Filmes que muita gente viu, de uma página sorteada.
 *
 * O corte é por NÚMERO DE VOTOS, não por nota: nota alta com cem votos é
 * filme de nicho, e o clube precisa de filme que a liga tenha chance de ter
 * visto. Com `vote_count.gte=1500` sobram umas 170 páginas — folga de sobra
 * pra sortear sem repetir.
 */
function clubeFonteFilmes(): array
{
    $chave = clubeFonteChaveTmdb();
    if ($chave === '') return [];

    $pag = random_int(1, 170);
    $d = clubeFonteGet('https://api.themoviedb.org/3/discover/movie?api_key=' . $chave
        . '&language=pt-BR&sort_by=vote_count.desc&vote_count.gte=1500'
        . '&include_adult=false&page=' . $pag);
    if (!$d || empty($d['results'])) return [];

    $fora = [];
    foreach ($d['results'] as $f) {
        $t = trim((string)($f['title'] ?? ''));
        if ($t === '' || !clubeFonteAlfabetoOk($t)) continue;
        $fora[] = [$t, '', substr((string)($f['release_date'] ?? ''), 0, 4),
                   !empty($f['poster_path']) ? 'https://image.tmdb.org/t/p/w342' . $f['poster_path'] : ''];
    }
    return array_slice($fora, 0, CLUBE_FONTE_LOTE);
}

/* ═══════════════════════════ LIVROS ═══════════════════════════════════ */

/**
 * Livros conhecidos de um gênero.
 *
 * `sort=rating` é o que faz a diferença entre uma estante de clube e uma
 * estante de arquivo: sem ele a resposta vem cheia de obra em domínio
 * público que ninguém leu. `ratings_count` entra como segundo filtro, já
 * que uma nota alta com três votos não diz nada.
 */
function clubeFonteLivros(string $genero): array
{
    $subjects = CLUBE_FONTE_SUBJECTS[$genero] ?? [];
    if (!$subjects) return [];
    $subject = $subjects[array_rand($subjects)];

    $pag = random_int(1, 4);
    $d = clubeFonteGet('https://openlibrary.org/search.json?q=subject:' . rawurlencode($subject)
        . '&sort=rating&limit=' . CLUBE_FONTE_LOTE . '&page=' . $pag
        . '&fields=title,author_name,first_publish_year,cover_i,ratings_count,language');
    if (!$d || empty($d['docs'])) return [];

    $fora = [];
    foreach ($d['docs'] as $w) {
        $t = trim((string)($w['title'] ?? ''));
        $a = trim((string)($w['author_name'][0] ?? ''));
        if ($t === '' || $a === '') continue;              // sem autor não vira carta
        if ((int)($w['ratings_count'] ?? 0) < 5) continue;  // nota de três pessoas não é nota
        if (!clubeFonteAlfabetoOk($t)) continue;            // @see clubeFonteAlfabetoOk

        /* PORTUGUÊS OU INGLÊS, e nada além. O alfabeto latino sozinho não
           basta: "Der Proceß" é O Processo do Kafka, escrito com as nossas
           letras e irreconhecível pra quem vai votar. O Open Library devolve
           a obra no idioma em que ela foi catalogada, então é por aqui que
           se barra o alemão, o francês e o espanhol. Obra sem idioma
           declarado passa — recusar por falta de informação tiraria livro
           bom à toa. */
        $idiomas = (array)($w['language'] ?? []);
        if ($idiomas && !array_intersect($idiomas, ['por', 'eng'])) continue;
        $fora[] = [$t, $a, $w['first_publish_year'] ?? null,
                   !empty($w['cover_i']) ? 'https://covers.openlibrary.org/b/id/' . (int)$w['cover_i'] . '-M.jpg' : ''];
    }
    return $fora;
}

/* ═══════════════════════════ ÁLBUNS ═══════════════════════════════════ */

/**
 * O que não é disco de estúdio e não deve virar "álbum da semana".
 *
 * O `\b` no fim da lista deixava "(Remixes)" passar inteiro — a palavra ali
 * é "remixes", não "remix". Os termos que admitem plural ou sufixo agora
 * param antes da borda; os outros continuam fechados, senão "Live" pegaria
 * "Alive" e "Olivia".
 */
function clubeFonteAlbumRuim(string $titulo, string $tipo): bool
{
    if ($tipo !== '' && $tipo !== 'album') return true;

    /* Termos que valem com qualquer terminação: remix/remixes/remixed. */
    if (preg_match('/\b(remix|remaster|coletâ|instrumental|karaok)/iu', $titulo)) return true;

    /* A MARCA DA REMASTERIZAÇÃO É O ANO ENTRE PARÊNTESES. "Free As A Bird
       (2025 Mix)" é o mesmo disco dos Beatles com outra mixagem, e entrar
       como novidade de 2025 seria dizer à liga que os Beatles lançaram
       álbum ano passado. "Mix" sozinho não serve de regra: barraria
       "Mixtape" e qualquer disco que use a palavra no nome. */
    if (preg_match('/\(\s*\d{4}\s*(mix|version|edit)/iu', $titulo)) return true;

    return (bool)preg_match(
        '/\b(ao vivo|live|deluxe|edition|edição|encore|anniversary|aniversário|greatest|'
        . 'best of|hits|collection|tribute|tributo|trilha sonora|soundtrack|'
        . 'playback|acústico|acoustic|sessions|unplugged|mtv)\b/iu', $titulo);
}

/**
 * O título está num alfabeto que a liga lê?
 *
 * O Open Library devolve a obra no idioma em que ela foi catalogada, e uma
 * busca por Drama trouxe "Братья Карамазовы" — que é Os Irmãos Karamázov, e
 * que ninguém no grupo vai reconhecer, muito menos votar. Inglês já é o
 * limite do aceitável aqui; cirílico, grego, árabe, hebraico, japonês ou
 * chinês não dá. O teste é pela PROPORÇÃO de letras latinas, não pela
 * ausência de outras, pra um acento ou um ideograma solto no subtítulo não
 * derrubar um livro bom.
 */
function clubeFonteAlfabetoOk(string $titulo): bool
{
    $letras = preg_match_all('/\p{L}/u', $titulo);
    /* SEM LETRA NENHUMA NÃO HÁ ALFABETO PRA JULGAR, e o título é válido:
       "1989" da Taylor Swift e "21" da Adele caíam aqui como se fossem
       cirílico. Título vazio já foi barrado antes de chegar. */
    if ($letras === 0) return true;
    $latinas = preg_match_all('/\p{Latin}/u', $titulo);
    return $latinas / $letras >= 0.8;
}

/**
 * A discografia de um artista, pelo Deezer.
 *
 * Quem escolhe o artista é a curadoria que já existe (@see
 * clubeFonteArtistasSemente) — ver o cabeçalho pra entender por que não dá
 * pra pedir por gênero.
 */
function clubeFonteAlbunsDoArtista(string $artista): array
{
    $b = clubeFonteGet('https://api.deezer.com/search/artist?q=' . rawurlencode($artista) . '&limit=1');
    $id = (int)($b['data'][0]['id'] ?? 0);
    $nome = trim((string)($b['data'][0]['name'] ?? $artista));
    if (!$id) return [];

    /* O NOME TEM QUE BATER. "Legião Urbana" achando "Legiao Tribute Band"
       encheria o clube de cover — e a carta diria o nome do artista certo. */
    if (clubeFonteChave($nome) !== clubeFonteChave($artista)) return [];

    $al = clubeFonteGet("https://api.deezer.com/artist/{$id}/albums?limit=50");
    if (!$al || empty($al['data'])) return [];

    $fora = [];
    foreach ($al['data'] as $x) {
        $t = trim((string)($x['title'] ?? ''));
        if ($t === '' || clubeFonteAlbumRuim($t, (string)($x['record_type'] ?? ''))) continue;
        if (!clubeFonteAlfabetoOk($t)) continue;
        $fora[] = [$t, $nome, substr((string)($x['release_date'] ?? ''), 0, 4),
                   (string)($x['cover_medium'] ?? '')];
    }
    return $fora;
}

/** Nome comparável: minúsculo, sem acento e sem pontuação. */
function clubeFonteChave(string $s): string
{
    $t = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','í'=>'i','ì'=>'i',
          'ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o','ú'=>'u','ü'=>'u','ù'=>'u','ç'=>'c','ñ'=>'n'];
    return (string)preg_replace('/[^a-z0-9]/', '', strtr(mb_strtolower($s, 'UTF-8'), $t));
}

/**
 * Os artistas que o clube já considerou bons.
 *
 * Sai da própria CLUBE_ALBUNS: a lista escrita à mão deixa de ser o teto do
 * jogo e passa a ser a semente dele. Setenta álbuns dão cerca de sessenta
 * artistas, e cada um tem dez a quinze discos de estúdio — o leque sai de
 * setenta pra algo perto de setecentos sem ninguém digitar nada.
 */
function clubeFonteArtistasSemente(): array
{
    if (!defined('CLUBE_ALBUNS')) return [];
    $a = [];
    foreach (CLUBE_ALBUNS as $x) {
        $nome = trim((string)($x[1] ?? ''));
        if ($nome !== '') $a[clubeFonteChave($nome)] = $nome;
    }
    return array_values($a);
}

/* ═══════════════════ ABASTECER, E SÓ QUANDO PRECISA ═══════════════════ */

/**
 * Enche o pool de um tipo, se ele estiver magro.
 *
 * @return int quantas obras novas entraram
 */
function clubeFonteAbastecer(PDO $pdo, string $tipo, string $genero = '', bool $forcar = false): int
{
    clubeFonteTabela($pdo);
    try {
        if (!$forcar) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM clube_semana_pool
                                  WHERE tipo = ? AND genero = ? AND usado_em IS NULL');
            $st->execute([$tipo, $genero]);
            if ((int)$st->fetchColumn() >= CLUBE_FONTE_MINIMO) return 0;
        }

        if ($tipo === 'filme') {
            return clubeFonteGuardar($pdo, 'filme', '', clubeFonteFilmes(), 'tmdb');
        }
        if ($tipo === 'livro') {
            return clubeFonteGuardar($pdo, 'livro', $genero, clubeFonteLivros($genero), 'openlibrary');
        }
        if ($tipo === 'album') {
            /* Três artistas por abastecida: é o bastante pra encher o pool e
               pouco o suficiente pra não segurar quem abriu a página. */
            $sementes = clubeFonteArtistasSemente();
            if (!$sementes) return 0;
            shuffle($sementes);
            $n = 0;
            foreach (array_slice($sementes, 0, 3) as $art) {
                $n += clubeFonteGuardar($pdo, 'album', '', clubeFonteAlbunsDoArtista($art), 'deezer');
            }
            return $n;
        }
    } catch (Throwable $e) {
        error_log('[clube-fontes] abastecer ' . $tipo . ': ' . $e->getMessage());
    }
    return 0;
}

/**
 * Tira do pool as obras pra uma enquete, e marca como usadas.
 *
 * Marcar é o que impede a mesma lista de voltar semana sim, semana não. O
 * pool é grande o bastante pra isso não esgotar, e quando esgota o
 * abastecimento devolve coisa nova antes do sorteio.
 *
 * @return array cada um [titulo, autor, ano] — o formato das listas à mão
 */
function clubeFonteSortear(PDO $pdo, string $tipo, string $genero, int $quantos): array
{
    clubeFonteTabela($pdo);
    try {
        clubeFonteAbastecer($pdo, $tipo, $genero);

        $st = $pdo->prepare('SELECT id, titulo, autor, ano FROM clube_semana_pool
                              WHERE tipo = ? AND genero = ? AND usado_em IS NULL
                           ORDER BY RAND() LIMIT ' . (int)$quantos);
        $st->execute([$tipo, $genero]);
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC);

        /* Pool sem nada novo: libera o que já rodou e tenta de novo, que é
           melhor que devolver meia enquete. */
        if (count($linhas) < $quantos) {
            $pdo->prepare('UPDATE clube_semana_pool SET usado_em = NULL
                            WHERE tipo = ? AND genero = ?')->execute([$tipo, $genero]);
            $st->execute([$tipo, $genero]);
            $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        if (count($linhas) < $quantos) return [];     // deixa o curado assumir

        $ids = array_column($linhas, 'id');
        $pdo->prepare('UPDATE clube_semana_pool SET usado_em = CURDATE()
                        WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->execute();

        return array_map(
            fn($l) => [(string)$l['titulo'], (string)$l['autor'], $l['ano'] !== null ? (int)$l['ano'] : null],
            $linhas
        );
    } catch (Throwable $e) {
        error_log('[clube-fontes] sortear ' . $tipo . ': ' . $e->getMessage());
        return [];
    }
}

/** A capa de uma obra, se o pool tiver. Vazio quando não tem. */
function clubeFonteCapa(PDO $pdo, string $tipo, string $titulo, string $autor): string
{
    try {
        clubeFonteTabela($pdo);
        $st = $pdo->prepare('SELECT capa FROM clube_semana_pool
                              WHERE tipo = ? AND titulo = ? AND autor = ? AND capa <> "" LIMIT 1');
        $st->execute([$tipo, $titulo, $autor]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}
