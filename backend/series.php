<?php
/**
 * SÉRIES — o diário de quem assiste, no estilo Serializd.
 *
 * Cada pessoa marca o que já viu, o que está vendo e o que quer ver, dá nota
 * do que assistiu e escolhe as cinco favoritas. Três notas convivem em cada
 * ficha e são três coisas diferentes:
 *
 *   IMDb   — o mundo inteiro. Vem do arquivo público de notas do IMDb.
 *   FBA    — só a liga. É a média das notas de quem está aqui, e é a que
 *            torna o jogo um jogo: saber que a galera odiou o que você amou.
 *   a sua  — de 1 a 10, e só depois de ter assistido.
 *
 * SÓ SÉRIE. Filme não entra, e isso está na origem dos dados: o importador
 * lê o catálogo de TV do TMDB e nada mais.
 *
 * ── DUAS FONTES, E POR QUÊ ───────────────────────────────────────────
 *
 * O TMDB dá catálogo, pôster em alta e título em português, mas a nota dele é
 * a dele. O IMDb dá a nota que as pessoas conhecem, mas não dá imagem nem
 * catálogo navegável. Então: catálogo e imagem do TMDB, nota do arquivo
 * público do IMDb, cruzados pelo `imdb_id` que o próprio TMDB entrega.
 * @see games/core/series_importar_cli.php
 */

require_once __DIR__ . '/db.php';

/** De onde vêm os pôsteres. O TMDB serve a imagem; a gente guarda o caminho. */
const SERIES_IMG_BASE = 'https://image.tmdb.org/t/p/';
const SERIES_POSTER    = 'w342';    // o tamanho da grade
const SERIES_POSTER_G  = 'w500';    // o da ficha aberta

/**
 * QUANTAS CABEM NO TOP DO PERFIL.
 *
 * Nasceu 5 e virou 10 em 30/09/2026. Cinco é pouco pra quem assiste muito:
 * o top virava a lista das cinco intocáveis e nunca mudava, e uma lista que
 * não muda ninguém volta pra ver.
 *
 * Todo lugar que fala do top lê daqui — o rótulo do botão, a recusa quando
 * enche, o título no perfil. Mudar de novo é mudar este número.
 */
const SERIES_TOP = 10;

/** O mínimo de votos no IMDb pra entrar na vitrine. @see seriesBuscar */
const SERIES_VITRINE_VOTOS = 1000;

/** Os três estados, na ordem em que uma série anda na vida da pessoa. */
const SERIES_ESTADOS = [
    'quero'      => 'Quero ver',
    'assistindo' => 'Estou assistindo',
    'assistida'  => 'Assistida',
];

/**
 * As tabelas. Migração preguiçosa, como o resto dos jogos: nasce na primeira
 * vez que alguém abre a página, sem passo de deploy pra esquecer.
 */
function seriesGarantirTabelas(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS series (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        tmdb_id        INT NOT NULL,
        imdb_id        VARCHAR(20) NULL,
        titulo         VARCHAR(200) NOT NULL,
        titulo_original VARCHAR(200) NULL,
        ano_inicio     SMALLINT NULL,
        ano_fim        SMALLINT NULL,
        temporadas     SMALLINT NULL,
        episodios      SMALLINT NULL,
        generos        VARCHAR(160) NULL,
        sinopse        TEXT NULL,
        poster         VARCHAR(120) NULL,
        em_exibicao    TINYINT(1) NOT NULL DEFAULT 0,
        nota_imdb      DECIMAL(3,1) NULL,
        votos_imdb     INT NULL,
        popularidade   DECIMAL(12,3) NULL,
        /* A média da liga fica GRAVADA, e não somada a cada listagem: a grade
           mostra sessenta séries por vez, e sessenta subconsultas de média por
           tela é o tipo de conta que só dói quando a liga cresce. É recalculada
           quando alguém avalia — @see seriesRecalcularMedia. */
        nota_fba       DECIMAL(3,1) NULL,
        votos_fba      INT NOT NULL DEFAULT 0,
        atualizado_em  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_tmdb (tmdb_id),
        KEY idx_titulo (titulo),
        KEY idx_pop (popularidade),
        KEY idx_imdb (nota_imdb)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS series_usuario (
        user_id       INT NOT NULL,
        serie_id      INT NOT NULL,
        estado        ENUM('quero','assistindo','assistida') NOT NULL,
        nota          TINYINT NULL,
        /* A posição no top 5, de 1 a 5. O índice único é o que impede duas
           séries na mesma posição — em MySQL, vários NULL convivem num índice
           único, então quem não é favorita não atrapalha. */
        favorita      TINYINT NULL,
        criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, serie_id),
        UNIQUE KEY uk_favorita (user_id, favorita),
        KEY idx_user_estado (user_id, estado),
        KEY idx_serie (serie_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    /* O RECADO CHEGOU DEPOIS. A tabela já existia em produção com gente
       marcando série, então ele entra por ALTER e não no CREATE — quem já tem
       o banco montado não perde nada, e quem monta agora recebe igual. */
    try {
        if ($pdo->query("SHOW COLUMNS FROM series_usuario LIKE 'comentario'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE series_usuario ADD COLUMN comentario VARCHAR(280) NULL AFTER nota");
        }
    } catch (Throwable $e) {
        error_log('[series] coluna comentario: ' . $e->getMessage());
    }

    $ok = true;
}

/** A URL do pôster, ou null pra série sem imagem. */
function seriesPoster(?string $caminho, string $tamanho = SERIES_POSTER): ?string
{
    $caminho = trim((string)$caminho);
    return $caminho === '' ? null : SERIES_IMG_BASE . $tamanho . $caminho;
}

/** "2008–2013", "2008–" pra quem ainda está no ar, "2008" pra minissérie. */
function seriesAnos(?int $ini, ?int $fim, bool $emExibicao): string
{
    if (!$ini) return '';
    if ($emExibicao) return $ini . '–';
    if (!$fim || $fim === $ini) return (string)$ini;
    return $ini . '–' . $fim;
}

/* ─── catálogo ──────────────────────────────────────────────────────────── */

/**
 * A BUSCA É LOCAL, e é por isso que o catálogo é importado em vez de
 * consultado ao vivo: com as duas mil no banco, digitar o nome responde na
 * hora, sem ida ao TMDB a cada tecla.
 *
 * Procura no título em português E no original — muita gente digita "The
 * Office" pra achar "The Office", e muita gente digita "Round 6" pra achar
 * "Squid Game".
 *
 * @return array lista de séries, com o que o usuário marcou junto
 */
function seriesBuscar(PDO $pdo, string $termo, int $userId, int $limite = 60): array
{
    seriesGarantirTabelas($pdo);
    $termo = trim($termo);

    $sql = "SELECT s.*, u.estado, u.nota AS minha_nota, u.comentario AS meu_recado, u.favorita
              FROM series s
         LEFT JOIN series_usuario u ON u.serie_id = s.id AND u.user_id = ?
             WHERE 1 = 1";
    $par = [$userId];

    if ($termo !== '') {
        $sql .= " AND (s.titulo LIKE ? OR s.titulo_original LIKE ?)";
        $par[] = '%' . $termo . '%';
        $par[] = '%' . $termo . '%';
        /* QUEM COMEÇA COM O TERMO VEM PRIMEIRO. Buscar "the" com a ordem de
           popularidade devolve trinta séries com "the" no meio do nome antes
           de "The Office". */
        $sql .= " ORDER BY (s.titulo LIKE ?) DESC, s.popularidade DESC";
        $par[] = $termo . '%';
    } else {
        /* A VITRINE NÃO É A LISTA CRUA DE POPULARES.
           "Popularidade" no TMDB é atividade, não qualidade: quem abria o jogo
           via Tonight Show, Tagesschau e Late Show antes de qualquer série —
           programa diário de dez mil episódios ganha de Breaking Bad todo dia.
           Fora disso sobra o que a pessoa veio procurar.

           Talk e jornal saem da PRIMEIRA TELA, não do catálogo: quem quiser o
           Daily Show acha buscando pelo nome. E o mínimo de votos no IMDb tira
           a série que ninguém viu — não é censura de nota, é sinal de que
           existe público. */
        $sql .= " AND s.generos NOT LIKE '%Talk%' AND s.generos NOT LIKE '%News%'
                  AND COALESCE(s.votos_imdb, 0) >= " . SERIES_VITRINE_VOTOS . "
                  ORDER BY s.popularidade DESC";
    }
    $sql .= " LIMIT " . max(1, min(200, $limite));

    $st = $pdo->prepare($sql);
    $st->execute($par);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Uma série pelo id, já com o que este usuário marcou nela. */
function seriesUma(PDO $pdo, int $serieId, int $userId): ?array
{
    seriesGarantirTabelas($pdo);
    $st = $pdo->prepare("SELECT s.*, u.estado, u.nota AS minha_nota, u.comentario AS meu_recado,
                                u.favorita
                           FROM series s
                      LEFT JOIN series_usuario u ON u.serie_id = s.id AND u.user_id = ?
                          WHERE s.id = ?");
    $st->execute([$userId, $serieId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ─── o que cada um marcou ──────────────────────────────────────────────── */

/**
 * MARCA O ESTADO de uma série pra uma pessoa.
 *
 * Marcar de novo o mesmo estado DESMARCA. É o gesto que a pessoa espera de um
 * botão que fica aceso: clicar de novo apaga. Sem isso, quem clicasse em
 * "Quero ver" por engano não teria como tirar da lista.
 *
 * TIRAR DA LISTA LEVA A NOTA JUNTO, e a favorita também: nota de série que
 * você não marcou mais não quer dizer nada, e deixá-la ali faria a média da
 * liga contar quem desistiu.
 */
function seriesMarcar(PDO $pdo, int $userId, int $serieId, string $estado): array
{
    seriesGarantirTabelas($pdo);
    if (!isset(SERIES_ESTADOS[$estado])) return ['ok' => false, 'erro' => 'Estado desconhecido.'];

    $st = $pdo->prepare("SELECT estado FROM series_usuario WHERE user_id = ? AND serie_id = ?");
    $st->execute([$userId, $serieId]);
    $atual = $st->fetchColumn();

    if ($atual === $estado) {
        $pdo->prepare("DELETE FROM series_usuario WHERE user_id = ? AND serie_id = ?")
            ->execute([$userId, $serieId]);
        seriesRecalcularMedia($pdo, $serieId);
        return ['ok' => true, 'estado' => null];
    }

    /* VOLTAR PRA "QUERO VER" APAGA A NOTA. Quem tira do "assistida" está
       dizendo que não assistiu — a nota que sobrasse seria nota de quem não
       viu, e ela entra na média da liga. */
    $limpa = $estado === 'quero';

    $pdo->prepare("INSERT INTO series_usuario (user_id, serie_id, estado)
                   VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE estado = VALUES(estado)"
                   . ($limpa ? ", nota = NULL, comentario = NULL, favorita = NULL" : ""))
        ->execute([$userId, $serieId, $estado]);

    if ($limpa) seriesRecalcularMedia($pdo, $serieId);
    return ['ok' => true, 'estado' => $estado];
}

/**
 * A NOTA DA PESSOA, de 1 a 10.
 *
 * SÓ QUEM ASSISTIU AVALIA. Nota de quem marcou "quero ver" é palpite, e
 * palpite na média da liga estraga justamente o número que dá graça ao jogo.
 * Quem está assistindo pode dar nota: a opinião dele já se formou, e é assim
 * que o Serializd faz.
 *
 * Dar a mesma nota de novo TIRA a nota — o mesmo gesto do botão de estado.
 */
function seriesAvaliar(PDO $pdo, int $userId, int $serieId, ?int $nota): array
{
    seriesGarantirTabelas($pdo);
    if ($nota !== null && ($nota < 1 || $nota > 10)) {
        return ['ok' => false, 'erro' => 'A nota vai de 1 a 10.'];
    }

    $st = $pdo->prepare("SELECT estado, nota FROM series_usuario WHERE user_id = ? AND serie_id = ?");
    $st->execute([$userId, $serieId]);
    $linha = $st->fetch(PDO::FETCH_ASSOC);

    if (!$linha || $linha['estado'] === 'quero') {
        return ['ok' => false, 'erro' => 'Marque como assistida (ou assistindo) pra poder avaliar.'];
    }
    if ($nota !== null && (int)$linha['nota'] === $nota) $nota = null;   // clicou de novo: tira

    $pdo->prepare("UPDATE series_usuario SET nota = ? WHERE user_id = ? AND serie_id = ?")
        ->execute([$nota, $userId, $serieId]);

    $media = seriesRecalcularMedia($pdo, $serieId);
    return ['ok' => true, 'nota' => $nota, 'media' => $media['nota'], 'votos' => $media['votos']];
}

/**
 * Refaz a média da liga de uma série.
 *
 * Conta só nota de quem marcou — a de quem desmarcou some junto com a linha.
 *
 * @return array{nota:?float, votos:int}
 */
function seriesRecalcularMedia(PDO $pdo, int $serieId): array
{
    $st = $pdo->prepare("SELECT AVG(nota) AS m, COUNT(nota) AS n
                           FROM series_usuario WHERE serie_id = ? AND nota IS NOT NULL");
    $st->execute([$serieId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['m' => null, 'n' => 0];

    $nota  = $r['m'] === null ? null : round((float)$r['m'], 1);
    $votos = (int)$r['n'];

    $pdo->prepare("UPDATE series SET nota_fba = ?, votos_fba = ? WHERE id = ?")
        ->execute([$nota, $votos, $serieId]);

    return ['nota' => $nota, 'votos' => $votos];
}

/**
 * PÕE OU TIRA DO TOP 5.
 *
 * Só série assistida entra: o top 5 é o que a pessoa defende, e defender algo
 * que não viu não é defesa.
 *
 * A posição é a primeira vaga livre — ninguém precisa escolher "qual é a
 * terceira". Quem quiser reordenar tira e põe de novo, que é o mesmo trabalho
 * de arrastar e não precisa de tela nova.
 */
function seriesFavoritar(PDO $pdo, int $userId, int $serieId): array
{
    seriesGarantirTabelas($pdo);

    $st = $pdo->prepare("SELECT estado, favorita FROM series_usuario WHERE user_id = ? AND serie_id = ?");
    $st->execute([$userId, $serieId]);
    $linha = $st->fetch(PDO::FETCH_ASSOC);
    if (!$linha) return ['ok' => false, 'erro' => 'Marque a série antes de favoritar.'];
    if ($linha['estado'] !== 'assistida') {
        return ['ok' => false, 'erro' => 'Só série assistida entra no seu top ' . SERIES_TOP . '.'];
    }

    // Já é favorita: sai, e as de baixo sobem pra não deixar buraco.
    if ($linha['favorita'] !== null) {
        $pdo->prepare("UPDATE series_usuario SET favorita = NULL WHERE user_id = ? AND serie_id = ?")
            ->execute([$userId, $serieId]);
        seriesArrumarTop($pdo, $userId);
        return ['ok' => true, 'favorita' => null];
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM series_usuario WHERE user_id = ? AND favorita IS NOT NULL");
    $st->execute([$userId]);
    if ((int)$st->fetchColumn() >= SERIES_TOP) {
        return ['ok' => false, 'erro' => 'Seu top ' . SERIES_TOP . ' está cheio — tire uma antes.'];
    }

    seriesArrumarTop($pdo, $userId);
    $st = $pdo->prepare("SELECT COALESCE(MAX(favorita), 0) + 1 FROM series_usuario WHERE user_id = ?");
    $st->execute([$userId]);
    $pos = (int)$st->fetchColumn();

    $pdo->prepare("UPDATE series_usuario SET favorita = ? WHERE user_id = ? AND serie_id = ?")
        ->execute([$pos, $userId, $serieId]);
    return ['ok' => true, 'favorita' => $pos];
}

/**
 * Fecha os buracos do top: tirar a 2ª de cinco deixaria 1, 3, 4, 5, e o
 * "primeiro lugar livre" viraria o 6, que não existe.
 */
function seriesArrumarTop(PDO $pdo, int $userId): void
{
    $st = $pdo->prepare("SELECT serie_id FROM series_usuario
                          WHERE user_id = ? AND favorita IS NOT NULL ORDER BY favorita");
    $st->execute([$userId]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);

    /* Solta todas antes de renumerar: o índice único (user_id, favorita) recusa
       um UPDATE que passe por uma posição já ocupada no meio do caminho. */
    $pdo->prepare("UPDATE series_usuario SET favorita = NULL WHERE user_id = ?")->execute([$userId]);
    $up = $pdo->prepare("UPDATE series_usuario SET favorita = ? WHERE user_id = ? AND serie_id = ?");
    foreach ($ids as $i => $sid) $up->execute([$i + 1, $userId, (int)$sid]);
}

/* ─── o perfil ──────────────────────────────────────────────────────────── */

/**
 * O perfil de quem assiste: quantas em cada estado, a nota média que ele dá e
 * o top 5.
 *
 * A NOTA MÉDIA DA PESSOA é o número que diz se ela é generosa ou dura, e é a
 * graça de comparar dois perfis — quem dá 9 pra tudo e quem só dá 10 pra três.
 */
function seriesPerfil(PDO $pdo, int $userId): array
{
    seriesGarantirTabelas($pdo);

    $st = $pdo->prepare("SELECT estado, COUNT(*) n FROM series_usuario WHERE user_id = ? GROUP BY estado");
    $st->execute([$userId]);
    $contagem = array_fill_keys(array_keys(SERIES_ESTADOS), 0);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $contagem[$r['estado']] = (int)$r['n'];

    $st = $pdo->prepare("SELECT AVG(nota) m, COUNT(nota) n FROM series_usuario
                          WHERE user_id = ? AND nota IS NOT NULL");
    $st->execute([$userId]);
    $nota = $st->fetch(PDO::FETCH_ASSOC) ?: ['m' => null, 'n' => 0];

    $st = $pdo->prepare("SELECT s.*, u.nota AS minha_nota, u.favorita, u.estado
                           FROM series_usuario u JOIN series s ON s.id = u.serie_id
                          WHERE u.user_id = ? AND u.favorita IS NOT NULL
                       ORDER BY u.favorita");
    $st->execute([$userId]);

    return [
        'assistidas'  => $contagem['assistida'],
        'assistindo'  => $contagem['assistindo'],
        'quero'       => $contagem['quero'],
        'nota_media'  => $nota['m'] === null ? null : round((float)$nota['m'], 1),
        'avaliadas'   => (int)$nota['n'],
        'top'         => $st->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/** A lista de um estado, pra aba "minhas séries". */
function seriesMinhas(PDO $pdo, int $userId, string $estado): array
{
    seriesGarantirTabelas($pdo);
    if (!isset(SERIES_ESTADOS[$estado])) return [];

    $st = $pdo->prepare("SELECT s.*, u.estado, u.nota AS minha_nota, u.favorita
                           FROM series_usuario u JOIN series s ON s.id = u.serie_id
                          WHERE u.user_id = ? AND u.estado = ?
                       ORDER BY u.atualizado_em DESC");
    $st->execute([$userId, $estado]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * O QUE A LIGA ANDOU VENDO — a última página do jogo a fazer sentido sozinha.
 *
 * Sem isto o jogo é um caderno particular: cada um marca o seu e nunca vê o
 * do vizinho, e aí a média da FBA é um número sem rosto.
 */
function seriesMovimentoDaLiga(PDO $pdo, int $quantos = 20): array
{
    seriesGarantirTabelas($pdo);
    $st = $pdo->prepare("SELECT u.estado, u.nota, u.comentario, u.atualizado_em,
                                s.id, s.titulo, s.poster, s.ano_inicio,
                                u.user_id, us.photo_url, COALESCE(us.name, 'Alguém') AS quem
                           FROM series_usuario u
                           JOIN series s ON s.id = u.serie_id
                      LEFT JOIN users us ON us.id = u.user_id
                       ORDER BY u.atualizado_em DESC
                          LIMIT " . max(1, min(60, $quantos)));
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * TIRAR A SÉRIE DO MEU ACERVO — o X da lista.
 *
 * Diferente de trocar de categoria: isto apaga a linha, e com ela a nota, o
 * recado e a vaga no top. Existe porque marcar é um clique e errar o clique
 * também: sem o X, a série que entrou por engano ficava pra sempre na conta de
 * "quero ver" e estragava o número do perfil.
 *
 * NÃO MEXE NA SÉRIE, só na minha linha — o catálogo é de todo mundo.
 *
 * A média da liga é recalculada porque a minha nota saiu do bolo.
 */
function seriesTirar(PDO $pdo, int $userId, int $serieId): array
{
    seriesGarantirTabelas($pdo);

    $st = $pdo->prepare("DELETE FROM series_usuario WHERE user_id = ? AND serie_id = ?");
    $st->execute([$userId, $serieId]);
    if ($st->rowCount() === 0) return ['ok' => false, 'erro' => 'Essa série não estava no seu perfil.'];

    /* O top não pode ficar com buraco no meio depois que a 3ª saiu. */
    seriesArrumarTop($pdo, $userId);
    seriesRecalcularMedia($pdo, $serieId);

    return ['ok' => true, 'tirada' => $serieId];
}

/**
 * O MEU ACERVO, do jeito que eu quiser olhar.
 *
 * É a tela de gerenciar: filtra por categoria, procura pelo nome e ordena.
 * Sem isto, quem marcou duzentas séries só tinha três grades gigantes em
 * ordem de "mexi por último" — dava pra ver, não dava pra achar.
 *
 * ORDENAR POR NOTA É O PEDIDO MAIS ÓBVIO e o mais chato de fazer na mão: a
 * pergunta "qual eu dei 10?" não tem resposta numa grade de pôster.
 *
 * @param string $estado '' = todas as categorias
 * @param string $ordem  recentes | nota | notaasc | titulo | imdb
 */
function seriesMeuAcervo(PDO $pdo, int $userId, string $estado = '', string $termo = '',
                         string $ordem = 'recentes'): array
{
    seriesGarantirTabelas($pdo);

    $sql = "SELECT s.*, u.estado, u.nota AS minha_nota, u.comentario AS meu_recado,
                   u.favorita, u.atualizado_em AS mexi_em
              FROM series_usuario u JOIN series s ON s.id = u.serie_id
             WHERE u.user_id = ?";
    $par = [$userId];

    if (isset(SERIES_ESTADOS[$estado])) { $sql .= " AND u.estado = ?"; $par[] = $estado; }

    $termo = trim($termo);
    if ($termo !== '') {
        $sql .= " AND (s.titulo LIKE ? OR s.titulo_original LIKE ?)";
        $par[] = '%' . $termo . '%';
        $par[] = '%' . $termo . '%';
    }

    /* SEM NOTA VAI PRO FIM nas duas ordens de nota, inclusive na crescente:
       quem pede "por nota" quer ver notas, e trinta traços no topo seriam o
       contrário do que ele pediu. */
    $por = [
        'recentes' => 'u.atualizado_em DESC',
        'nota'     => 'u.nota IS NULL, u.nota DESC, s.titulo',
        'notaasc'  => 'u.nota IS NULL, u.nota ASC, s.titulo',
        'titulo'   => 's.titulo ASC',
        'imdb'     => 's.nota_imdb IS NULL, s.nota_imdb DESC',
    ][$ordem] ?? 'u.atualizado_em DESC';

    $st = $pdo->prepare($sql . " ORDER BY " . $por);
    $st->execute($par);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * O RECADO DE UMA LINHA.
 *
 * Nota é um número e número não conta por que. "8" pode ser decepção de quem
 * esperava 10 e euforia de quem esperava 5 — e é essa frase que faz a lista da
 * liga valer a leitura.
 *
 * CURTO DE PROPÓSITO (280): o lugar disso é embaixo do pôster, ao lado de
 * outros vinte; resenha de três parágrafos empurraria o resto da página pra
 * fora e ninguém leria nenhuma.
 *
 * Mesma regra da nota: só quem assistiu (ou está assistindo) fala.
 */
function seriesComentar(PDO $pdo, int $userId, int $serieId, string $texto): array
{
    seriesGarantirTabelas($pdo);

    $st = $pdo->prepare("SELECT estado FROM series_usuario WHERE user_id = ? AND serie_id = ?");
    $st->execute([$userId, $serieId]);
    $estado = $st->fetchColumn();

    if (!$estado || $estado === 'quero') {
        return ['ok' => false, 'erro' => 'Marque como assistida (ou assistindo) pra poder comentar.'];
    }

    $texto = trim(preg_replace('/\s+/u', ' ', $texto));
    if (mb_strlen($texto) > 280) $texto = mb_substr($texto, 0, 280);

    $pdo->prepare("UPDATE series_usuario SET comentario = ? WHERE user_id = ? AND serie_id = ?")
        ->execute([$texto !== '' ? $texto : null, $userId, $serieId]);

    return ['ok' => true, 'comentario' => $texto !== '' ? $texto : null];
}

/** Quantas notas uma série precisa pra entrar no ranking da liga. */
const SERIES_RANKING_MIN = 2;

/**
 * OS RANKINGS DA LIGA.
 *
 * 'nota'   — as mais bem avaliadas AQUI, não no IMDb. É a lista que só existe
 *            por causa da liga, e a razão de o jogo não ser um caderno.
 * 'vistas' — as que mais gente assistiu. Diz o que é assunto.
 * 'querem' — as que mais gente quer ver. Diz o que vai ser assunto.
 *
 * O MÍNIMO DE DUAS NOTAS no ranking de nota existe porque uma nota só não é
 * média de ninguém: a série que um GM deu 10 lideraria pra sempre, e o topo
 * viraria a lista de quem avaliou primeiro.
 */
function seriesRankingDaLiga(PDO $pdo, string $tipo, int $quantos = 10): array
{
    seriesGarantirTabelas($pdo);

    if ($tipo === 'nota') {
        $sql = "SELECT s.*, s.nota_fba AS valor, s.votos_fba AS quantos
                  FROM series s
                 WHERE s.votos_fba >= " . SERIES_RANKING_MIN . "
              ORDER BY s.nota_fba DESC, s.votos_fba DESC";
    } elseif (isset(['vistas' => 'assistida', 'querem' => 'quero',
                     'vendo' => 'assistindo'][$tipo])) {
        /* 'vendo' entrou pelo bot (/seriesmomento): "o que a liga está
           assistindo AGORA" é outra pergunta que "o que a liga já viu" — uma
           diz o assunto da semana, a outra o acervo. */
        $estado = ['vistas' => 'assistida', 'querem' => 'quero',
                   'vendo' => 'assistindo'][$tipo];
        $sql = "SELECT s.*, COUNT(*) AS valor, COUNT(*) AS quantos
                  FROM series_usuario u JOIN series s ON s.id = u.serie_id
                 WHERE u.estado = " . $pdo->quote($estado) . "
              GROUP BY s.id
              ORDER BY valor DESC, s.nota_fba DESC";
    } else {
        return [];
    }

    return $pdo->query($sql . " LIMIT " . max(1, min(50, $quantos)))->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * QUEM DA LIGA JÁ MEXEU NUMA SÉRIE — com a nota e o recado.
 *
 * É o que transforma a ficha de uma página de catálogo numa conversa. Quem
 * avaliou vem primeiro: nota com frase é o que se quer ler, e quem só marcou
 * "quero ver" não tem nada a dizer ainda.
 */
function seriesQuemMarcou(PDO $pdo, int $serieId): array
{
    seriesGarantirTabelas($pdo);
    $st = $pdo->prepare("SELECT u.user_id, u.estado, u.nota, u.comentario, u.atualizado_em,
                                COALESCE(us.name, 'Alguém') AS nome, us.photo_url, us.league
                           FROM series_usuario u
                      LEFT JOIN users us ON us.id = u.user_id
                          WHERE u.serie_id = ?
                       ORDER BY (u.comentario IS NOT NULL) DESC, (u.nota IS NOT NULL) DESC,
                                u.atualizado_em DESC");
    $st->execute([$serieId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * AS PESSOAS DO CLUBE, pra procurar pelo nome.
 *
 * Só quem já marcou alguma coisa: a lista de todos os GMs da FBA seria em
 * quase toda linha um perfil vazio, e um perfil vazio não é um destino.
 */
function seriesPessoas(PDO $pdo, string $termo = '', int $limite = 40): array
{
    seriesGarantirTabelas($pdo);

    $sql = "SELECT us.id, COALESCE(us.name, 'Alguém') AS nome, us.photo_url, us.league,
                   SUM(u.estado = 'assistida') AS assistidas,
                   SUM(u.estado = 'assistindo') AS assistindo,
                   SUM(u.estado = 'quero') AS quero,
                   ROUND(AVG(u.nota), 1) AS nota_media
              FROM series_usuario u
              JOIN users us ON us.id = u.user_id";
    $par = [];
    if (trim($termo) !== '') { $sql .= " WHERE us.name LIKE ?"; $par[] = '%' . trim($termo) . '%'; }
    $sql .= " GROUP BY us.id, us.name, us.photo_url, us.league
              ORDER BY assistidas DESC, us.name
              LIMIT " . max(1, min(100, $limite));

    $st = $pdo->prepare($sql);
    $st->execute($par);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** O nome e a foto de alguém, pro cabeçalho do perfil dele. */
function seriesQuemE(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare("SELECT id, COALESCE(name, 'Alguém') AS nome, photo_url, league
                           FROM users WHERE id = ?");
    $st->execute([$userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * TODAS AS SÉRIES QUE A LIGA TOCOU, com quantas pessoas e a média.
 *
 * O catálogo tem mil e seiscentas e a liga marcou algumas dezenas; listar as
 * mil e seiscentas aqui seria repetir o catálogo com outro nome. O que esta
 * página tem de próprio é justamente o recorte: o que ALGUÉM daqui viu.
 */
function seriesQueALigaTocou(PDO $pdo, string $ordem = 'movimento', int $limite = 60): array
{
    seriesGarantirTabelas($pdo);

    $por = [
        'movimento' => 'ultimo DESC',
        'nota'      => 's.nota_fba DESC, pessoas DESC',
        'pessoas'   => 'pessoas DESC, s.nota_fba DESC',
        'titulo'    => 's.titulo ASC',
    ][$ordem] ?? 'ultimo DESC';

    return $pdo->query(
        "SELECT s.*, COUNT(*) AS pessoas,
                SUM(u.nota IS NOT NULL) AS avaliacoes,
                SUM(u.comentario IS NOT NULL) AS recados,
                MAX(u.atualizado_em) AS ultimo
           FROM series_usuario u JOIN series s ON s.id = u.serie_id
       GROUP BY s.id
       ORDER BY {$por}
          LIMIT " . max(1, min(200, $limite)))->fetchAll(PDO::FETCH_ASSOC);
}

/** Quantas séries o catálogo tem — a página usa pra saber se já importou. */
function seriesQuantasNoCatalogo(PDO $pdo): int
{
    seriesGarantirTabelas($pdo);
    return (int)$pdo->query("SELECT COUNT(*) FROM series")->fetchColumn();
}
