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

/** Quantas favoritas cabem no perfil. */
const SERIES_TOP = 5;

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

    $sql = "SELECT s.*, u.estado, u.nota AS minha_nota, u.favorita
              FROM series s
         LEFT JOIN series_usuario u ON u.serie_id = s.id AND u.user_id = ?";
    $par = [$userId];

    if ($termo !== '') {
        $sql .= " WHERE s.titulo LIKE ? OR s.titulo_original LIKE ?";
        $par[] = '%' . $termo . '%';
        $par[] = '%' . $termo . '%';
        /* QUEM COMEÇA COM O TERMO VEM PRIMEIRO. Buscar "the" com a ordem de
           popularidade devolve trinta séries com "the" no meio do nome antes
           de "The Office". */
        $sql .= " ORDER BY (s.titulo LIKE ?) DESC, s.popularidade DESC";
        $par[] = $termo . '%';
    } else {
        $sql .= " ORDER BY s.popularidade DESC";
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
    $st = $pdo->prepare("SELECT s.*, u.estado, u.nota AS minha_nota, u.favorita
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
                   . ($limpa ? ", nota = NULL, favorita = NULL" : ""))
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
    $st = $pdo->prepare("SELECT u.estado, u.nota, u.atualizado_em,
                                s.id, s.titulo, s.poster, s.ano_inicio,
                                COALESCE(us.name, 'Alguém') AS quem
                           FROM series_usuario u
                           JOIN series s ON s.id = u.serie_id
                      LEFT JOIN users us ON us.id = u.user_id
                       ORDER BY u.atualizado_em DESC
                          LIMIT " . max(1, min(60, $quantos)));
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Quantas séries o catálogo tem — a página usa pra saber se já importou. */
function seriesQuantasNoCatalogo(PDO $pdo): int
{
    seriesGarantirTabelas($pdo);
    return (int)$pdo->query("SELECT COUNT(*) FROM series")->fetchColumn();
}
