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

/**
 * As temporadas da sprint atual da liga, em ordem.
 *
 * O ciclo conta DENTRO da sprint, não a partir da temporada 1 absoluta:
 * sprint nova recomeça a contagem, senão um ciclo ficaria com metade das
 * temporadas de uma sprint e metade da outra.
 *
 * Devolve [['season_number'=>n, 'status'=>s], ...] ordenado.
 */
function cicloTemporadasDaSprint(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        // A sprint atual é a da temporada mais recente da liga.
        $st = $pdo->prepare("SELECT sprint_id FROM seasons WHERE league = ?
                             ORDER BY season_number DESC LIMIT 1");
        $st->execute([CICLO_LIGA]);
        $sprint = $st->fetchColumn();
        if ($sprint === false || $sprint === null) return $cache;

        $st = $pdo->prepare("SELECT season_number, status FROM seasons
                             WHERE league = ? AND sprint_id = ?
                             ORDER BY season_number ASC");
        $st->execute([CICLO_LIGA, $sprint]);
        $cache = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[ciclos] temporadas da sprint: ' . $e->getMessage());
    }
    return $cache;
}

/** Quantos ciclos a sprint tem (contando o que está em andamento). */
function cicloQuantos(PDO $pdo): int
{
    $n = count(cicloTemporadasDaSprint($pdo));
    return $n ? (int)ceil($n / CICLO_TEMPORADAS) : 0;
}

/**
 * As temporadas que um ciclo cobre: [primeira, ultima] em season_number.
 *
 * Sai da posição dentro da sprint — o 1º ciclo são as 5 primeiras temporadas
 * DELA, quaisquer que sejam os números.
 */
function cicloIntervalo(PDO $pdo, int $ciclo): array
{
    $temps = cicloTemporadasDaSprint($pdo);
    if (!$temps) return [0, 0];
    $ciclo = max(1, $ciclo);
    $ini = ($ciclo - 1) * CICLO_TEMPORADAS;
    $fatia = array_slice($temps, $ini, CICLO_TEMPORADAS);
    if (!$fatia) return [0, 0];
    return [(int)$fatia[0]['season_number'], (int)$fatia[count($fatia) - 1]['season_number']];
}

/** O ciclo em que a liga está agora. */
function cicloAtual(PDO $pdo): int
{
    return max(1, cicloQuantos($pdo));
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
    [, $ate] = cicloIntervalo($pdo, $ciclo);
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
    [$de, $ate] = cicloIntervalo($pdo, $ciclo);
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
 * Um resumo por CICLO da sprint — é o que vira os cards do topo.
 *
 * Cada card mostra o campeão da SOMA das 5 temporadas, não o líder de uma
 * temporada isolada: o ciclo é a unidade, e mostrar vencedor por temporada
 * responderia uma pergunta que ninguém fez.
 *
 * Vêm todos os ciclos da sprint, inclusive o que está rolando (marcado como
 * em andamento) e os que ainda não têm pontuação — a sequência é o que deixa
 * claro em que ponto da sprint a liga está.
 */
function cicloResumos(PDO $pdo): array
{
    $quantos = cicloQuantos($pdo);
    if (!$quantos) return [];

    $out = [];
    for ($c = 1; $c <= $quantos; $c++) {
        [$de, $ate] = cicloIntervalo($pdo, $c);
        $tab = cicloClassificacao($pdo, $c);
        $fechado = cicloFechado($pdo, $c);
        $tem = $tab && $tab[0]['pontos'] > 0;

        $out[] = [
            'ciclo'       => $c,
            'de'          => $de,
            'ate'         => $ate,
            'fechado'     => $fechado,
            'tem_dados'   => $tem,
            // Fechado com pontuação = campeão. Em andamento = quem lidera
            // agora, e a tela diz que é parcial: anunciar "campeão" de um
            // ciclo que ainda corre seria dar título que pode mudar de dono.
            'campeao'     => $tem ? ($tab[0]['time'] ?: 'Time #' . $tab[0]['team_id']) : null,
            'team_id'     => $tem ? $tab[0]['team_id'] : null,
            'photo_url'   => $tem ? $tab[0]['photo_url'] : null,
            'pontos'      => $tem ? $tab[0]['pontos'] : null,
            'vice'        => $tem && isset($tab[1]) ? $tab[1]['time'] : null,
            'vice_pontos' => $tem && isset($tab[1]) ? $tab[1]['pontos'] : null,
            'times'       => $tab ? count($tab) : 0,
        ];
    }
    return $out;
}

/** Só os ciclos fechados COM pontuação — os campeões de verdade. */
function cicloCampeoes(PDO $pdo): array
{
    return array_values(array_filter(cicloResumos($pdo),
        fn($r) => $r['fechado'] && $r['tem_dados']));
}
