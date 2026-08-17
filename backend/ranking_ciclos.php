<?php
/**
 * Ranking por ciclo de 5 temporadas — só ELITE.
 *
 * O ranking normal soma tudo desde sempre, e quem chegou depois nunca alcança
 * quem está lá desde a primeira temporada. Aqui a conta zera a cada 5
 * temporadas: cada ciclo tem um campeão e todo mundo recomeça do zero.
 *
 * A pontuação é a MESMA do ranking padrão (team_season_points.points) — não
 * existe um segundo critério de pontos. O que muda é só a janela somada.
 */

const CICLO_TEMPORADAS = 5;
const CICLO_LIGA = 'ELITE';

/** O ciclo de uma temporada: 1..5 → 1, 6..10 → 2, e assim por diante. */
function cicloDaTemporada(int $seasonNumber): int
{
    return (int)max(1, ceil($seasonNumber / CICLO_TEMPORADAS));
}

/** As temporadas que um ciclo cobre: [primeira, ultima]. */
function cicloIntervalo(int $ciclo): array
{
    $ciclo = max(1, $ciclo);
    return [($ciclo - 1) * CICLO_TEMPORADAS + 1, $ciclo * CICLO_TEMPORADAS];
}

/**
 * A temporada em que a liga está.
 *
 * Sai de `seasons`, não da tabela de pontos: pontuação é lançada depois, e
 * usar o último lançamento faria o ciclo atual voltar no tempo entre o começo
 * de uma temporada e o fechamento dela.
 */
function cicloTemporadaAtual(PDO $pdo): int
{
    try {
        $st = $pdo->prepare("SELECT MAX(season_number) FROM seasons WHERE league = ?");
        $st->execute([CICLO_LIGA]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('[ciclos] temporada atual: ' . $e->getMessage());
        return 0;
    }
}

/**
 * O ciclo acabou?
 *
 * Só quando a ÚLTIMA temporada dele está marcada como concluída. Contar
 * "temporada 10 tem pontos" como fim do ciclo declararia campeão no meio de
 * uma temporada ainda em andamento — a pontuação entra aos poucos, e o líder
 * de terça não é o campeão de domingo.
 */
function cicloFechado(PDO $pdo, int $ciclo): bool
{
    [, $ate] = cicloIntervalo($ciclo);
    try {
        $st = $pdo->prepare("SELECT status FROM seasons WHERE league = ? AND season_number = ? LIMIT 1");
        $st->execute([CICLO_LIGA, $ate]);
        $status = $st->fetchColumn();
        return $status !== false && strtolower((string)$status) === 'completed';
    } catch (Throwable $e) {
        error_log('[ciclos] fechado: ' . $e->getMessage());
        return false;
    }
}

/**
 * A soma de pontos de cada time num ciclo.
 *
 * Devolve as linhas ordenadas, com posição já calculada. Empate é resolvido
 * por mais temporadas pontuadas e depois pelo nome — sem critério de desempate
 * a ordem mudava a cada carregamento e a mesma tela mostrava campeões
 * diferentes.
 */
function cicloClassificacao(PDO $pdo, int $ciclo): array
{
    [$de, $ate] = cicloIntervalo($ciclo);
    try {
        $st = $pdo->prepare("
            SELECT tsp.team_id,
                   TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS time,
                   t.photo_url,
                   SUM(tsp.points)          AS pontos,
                   SUM(tsp.points_regular)  AS pts_regular,
                   SUM(tsp.points_playoffs) AS pts_playoffs,
                   SUM(tsp.points_prizes)   AS pts_premios,
                   COUNT(DISTINCT tsp.season_number) AS temporadas
            FROM team_season_points tsp
            LEFT JOIN teams t ON t.id = tsp.team_id
            WHERE tsp.league = ? AND tsp.season_number BETWEEN ? AND ?
            GROUP BY tsp.team_id, time, t.photo_url
            ORDER BY pontos DESC, temporadas DESC, time ASC");
        $st->execute([CICLO_LIGA, $de, $ate]);
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[ciclos] classificacao: ' . $e->getMessage());
        return [];
    }

    $pos = 0;
    foreach ($linhas as &$l) {
        $l['pos'] = ++$pos;
        foreach (['pontos', 'pts_regular', 'pts_playoffs', 'pts_premios', 'temporadas', 'team_id'] as $k) {
            $l[$k] = (int)$l[$k];
        }
    }
    return $linhas;
}

/**
 * As 5 temporadas de um ciclo, uma por uma — inclusive as que ainda não
 * aconteceram.
 *
 * Devolve sempre CINCO entradas: a faixa de temporadas é o que dá forma ao
 * ciclo, e esconder as vazias faria "faltam duas" virar uma conta de cabeça.
 * Cada entrada diz se tem pontuação e quem liderou aquela temporada.
 */
function cicloTemporadas(PDO $pdo, int $ciclo): array
{
    [$de, $ate] = cicloIntervalo($ciclo);

    $porTemporada = [];
    try {
        $st = $pdo->prepare("
            SELECT tsp.season_number,
                   TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS time,
                   t.photo_url, tsp.points
            FROM team_season_points tsp
            LEFT JOIN teams t ON t.id = tsp.team_id
            WHERE tsp.league = ? AND tsp.season_number BETWEEN ? AND ?
            ORDER BY tsp.season_number ASC, tsp.points DESC");
        $st->execute([CICLO_LIGA, $de, $ate]);
        foreach ($st as $r) {
            $n = (int)$r['season_number'];
            // A consulta já vem ordenada por pontos: a primeira de cada
            // temporada é a líder.
            if (!isset($porTemporada[$n])) {
                $porTemporada[$n] = ['lider' => $r['time'], 'photo_url' => $r['photo_url'],
                                     'pontos' => (int)$r['points'], 'times' => 0];
            }
            $porTemporada[$n]['times']++;
        }
    } catch (Throwable $e) {
        error_log('[ciclos] temporadas: ' . $e->getMessage());
    }

    // Status de cada temporada, pra distinguir "ainda não rolou" de "rolou e
    // ninguém lançou pontuação" — são coisas diferentes pra quem administra.
    $status = [];
    try {
        $st = $pdo->prepare("SELECT season_number, status FROM seasons
                             WHERE league = ? AND season_number BETWEEN ? AND ?");
        $st->execute([CICLO_LIGA, $de, $ate]);
        foreach ($st as $r) $status[(int)$r['season_number']] = (string)$r['status'];
    } catch (Throwable $e) { /* sem status: tudo vira "por vir" */ }

    $out = [];
    for ($n = $de; $n <= $ate; $n++) {
        $tem = isset($porTemporada[$n]);
        $out[] = [
            'temporada' => $n,
            'tem_dados' => $tem,
            'status'    => $status[$n] ?? null,
            'existe'    => isset($status[$n]),
            'lider'     => $tem ? $porTemporada[$n]['lider'] : null,
            'photo_url' => $tem ? $porTemporada[$n]['photo_url'] : null,
            'pontos'    => $tem ? $porTemporada[$n]['pontos'] : null,
            'times'     => $tem ? $porTemporada[$n]['times'] : 0,
        ];
    }
    return $out;
}

/**
 * Os campeões dos ciclos JÁ FECHADOS.
 *
 * Um ciclo só entra na lista depois da 5ª temporada dele ter pontuação — o
 * líder de um ciclo em andamento não é campeão de nada, e mostrá-lo como tal
 * seria anunciar um título que ainda pode mudar de dono.
 */
function cicloCampeoes(PDO $pdo): array
{
    $atual = cicloTemporadaAtual($pdo);
    if ($atual < CICLO_TEMPORADAS) return [];

    $out = [];
    for ($c = 1; $c <= cicloDaTemporada($atual); $c++) {
        if (!cicloFechado($pdo, $c)) continue;
        $tab = cicloClassificacao($pdo, $c);
        // Ciclo fechado sem pontuação lançada não tem campeão — a ELITE tem
        // ciclos assim, de antes de a pontuação por temporada existir. Melhor
        // ausente que um campeão de zero ponto.
        if (!$tab || $tab[0]['pontos'] <= 0) continue;
        [$de, $ate] = cicloIntervalo($c);
        $out[] = [
            'ciclo'      => $c,
            'de'         => $de,
            'ate'        => $ate,
            'campeao'    => $tab[0]['time'] ?: 'Time #' . $tab[0]['team_id'],
            'team_id'    => $tab[0]['team_id'],
            'photo_url'  => $tab[0]['photo_url'] ?? null,
            'pontos'     => $tab[0]['pontos'],
            // O vice serve de medida: "campeão com 62" não diz nada sozinho.
            'vice'       => $tab[1]['time'] ?? null,
            'vice_pontos'=> isset($tab[1]) ? $tab[1]['pontos'] : null,
        ];
    }
    return $out;
}
