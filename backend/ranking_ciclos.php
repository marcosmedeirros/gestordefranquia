<?php
/**
 * Ranking por BLOCO de temporadas — a aba "ELITE 5T" e as Sprints da ROOKIE.
 *
 * O ranking normal soma tudo desde sempre, e quem chegou depois nunca alcança
 * quem está lá desde a primeira temporada. Aqui a conta zera a cada N
 * temporadas: cada bloco tem um campeão e todo mundo recomeça do zero.
 *
 * A pontuação é a MESMA do ranking padrão (team_season_points.points) — não
 * existe um segundo critério de pontos. O que muda é só a janela somada.
 *
 * ── DUAS LIGAS, A MESMA MECÂNICA ─────────────────────────────────────
 *
 * A ELITE tem uma sprint de 25 temporadas partida em 5 ciclos de 5. A ROOKIE
 * tem 15 temporadas partidas em 5 Sprints de 3 (comunicado oficial dos admins
 * em 23/09/2026, com R$ 40 de premiação por Sprint). É a mesma estrutura com
 * outro tamanho de bloco, então é a mesma função com outro parâmetro — o
 * arquivo era fixo na ELITE e duplicá-lo pra ROOKIE seria pedir pra as duas
 * divergirem no primeiro ajuste.
 *
 * O nome do bloco muda por liga porque muda na liga: na ELITE se fala "ciclo",
 * na ROOKIE se fala "Sprint".
 */

/**
 * A configuração de cada liga que usa ranking por bloco.
 *
 *   tamanho   temporadas em cada bloco
 *   blocos    quantos blocos a tela desenha (a grade é fixa: ver cicloQuantos)
 *   rotulo    como a liga chama o bloco
 *   premio    quanto vale o bloco em reais, ou null quando não há prêmio
 *   corte_gm  a pontuação conta só a partir de quando o GM atual assumiu?
 *
 * O corte_gm está ligado só na ROOKIE porque é lá que a regra existe: GM que
 * sobe pra Rise deixa a cadeira, e quem assume não herda os pontos de quem
 * subiu (o admin já zera o ranking_points nessas trocas). Na ELITE ele fica
 * desligado até alguém pedir — ligar sozinho mudaria a aba ELITE 5T, que não
 * foi o que pediram.
 */
const CICLO_CONFIG = [
    'ELITE'  => ['tamanho' => 5, 'blocos' => 5, 'rotulo' => 'Ciclo',  'premio' => null, 'corte_gm' => false],
    'ROOKIE' => ['tamanho' => 3, 'blocos' => 5, 'rotulo' => 'Sprint', 'premio' => 40,   'corte_gm' => true],
];

/* Mantidas porque rankings.php as usa. A ELITE continua sendo a liga padrão de
   todas as funções daqui, então nada muda pra quem não passa liga. */
const CICLO_TEMPORADAS = 5;
const CICLO_LIGA = 'ELITE';
const CICLO_BLOCOS = 5;

/** A configuração de uma liga, ou a da ELITE se a liga não tiver bloco. */
function cicloConfig(string $liga): array
{
    $liga = strtoupper(trim($liga));
    return CICLO_CONFIG[$liga] ?? CICLO_CONFIG['ELITE'];
}

/** As ligas que têm ranking por bloco. */
function cicloLigas(): array
{
    return array_keys(CICLO_CONFIG);
}

/**
 * As temporadas da sprint atual da liga, em ordem.
 *
 * O bloco conta DENTRO da sprint, não a partir da temporada 1 absoluta:
 * sprint nova recomeça a contagem, senão um bloco ficaria com metade das
 * temporadas de uma sprint e metade da outra.
 *
 * Devolve [['season_number'=>n, 'status'=>s], ...] ordenado.
 */
function cicloTemporadasDaSprint(PDO $pdo, string $liga = CICLO_LIGA): array
{
    // O cache é POR LIGA. Era uma variável só, e a segunda liga a perguntar
    // recebia as temporadas da primeira — a ROOKIE mostrando a grade da ELITE.
    static $cache = [];
    $liga = strtoupper(trim($liga));
    if (isset($cache[$liga])) return $cache[$liga];

    $cache[$liga] = [];
    try {
        // Quem sabe qual é a sprint atual é sprintAtualDaLiga(), em
        // backend/helpers.php: ela olha sprints.status='active' da liga.
        require_once __DIR__ . '/helpers.php';
        $sprint = sprintAtualDaLiga($pdo, $liga);

        if ($sprint) {
            $st = $pdo->prepare("SELECT season_number, status FROM seasons
                                 WHERE sprint_id = ? ORDER BY season_number ASC");
            $st->execute([(int)$sprint['id']]);
            $cache[$liga] = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        // Sem sprint ativa — ou com uma sprint que não tem temporada
        // amarrada —, cai pras temporadas da liga. Melhor um ranking com a
        // faixa toda que uma tela zerada sem explicação.
        if (!$cache[$liga]) {
            $st = $pdo->prepare("SELECT season_number, status FROM seasons
                                 WHERE league = ? ORDER BY season_number ASC");
            $st->execute([$liga]);
            $cache[$liga] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        error_log('[ciclos] temporadas da sprint: ' . $e->getMessage());
    }
    return $cache[$liga];
}

/**
 * Quantos blocos a tela mostra: sempre a grade cheia.
 *
 * Fixo de propósito. Derivar de quantas temporadas já existem fazia a tela
 * mostrar 3 cards hoje e 4 no mês que vem, e o bloco em andamento aparecia
 * com faixa encurtada ("T11–11"). A grade é a mesma sempre; o que muda é o
 * que está preenchido dentro dela.
 */
function cicloQuantos(PDO $pdo, string $liga = CICLO_LIGA): int
{
    return cicloConfig($liga)['blocos'];
}

/**
 * As temporadas que um bloco cobre: blocos fixos do tamanho da liga.
 *
 * ELITE: 1..5, 6..10, … 21..25.  ROOKIE: 1..3, 4..6, … 13..15.
 */
function cicloIntervalo(PDO $pdo, int $ciclo, string $liga = CICLO_LIGA): array
{
    $n = cicloConfig($liga)['tamanho'];
    $ciclo = max(1, $ciclo);
    return [($ciclo - 1) * $n + 1, $ciclo * $n];
}

/** O bloco em que a liga está agora — o que contém a temporada atual. */
function cicloAtual(PDO $pdo, string $liga = CICLO_LIGA): int
{
    $cfg = cicloConfig($liga);
    $t = cicloTemporadaAtual($pdo, $liga);
    return $t > 0 ? min($cfg['blocos'], (int)ceil($t / $cfg['tamanho'])) : 1;
}

/**
 * A temporada em que a liga está.
 *
 * Sai de `seasons`, não da tabela de pontos: pontuação é lançada depois, e
 * usar o último lançamento faria o bloco atual voltar no tempo entre o começo
 * de uma temporada e o fechamento dela.
 */
function cicloTemporadaAtual(PDO $pdo, string $liga = CICLO_LIGA): int
{
    // Da sprint ATIVA, não da liga inteira: a numeração reinicia a cada
    // sprint, e o MAX de todas devolvia a maior temporada de uma sprint
    // antiga. A tela dizia "temporada 20" com a liga na 1.
    $temps = cicloTemporadasDaSprint($pdo, $liga);
    if (!$temps) return 0;
    return (int)end($temps)['season_number'];
}

/**
 * O bloco acabou?
 *
 * Só quando a ÚLTIMA temporada dele está marcada como concluída. Contar
 * "a última temporada tem pontos" como fim do bloco declararia campeão no
 * meio de uma temporada em andamento — a pontuação entra aos poucos, e o
 * líder de terça não é o campeão de domingo.
 */
function cicloFechado(PDO $pdo, int $ciclo, string $liga = CICLO_LIGA): bool
{
    [, $ate] = cicloIntervalo($pdo, $ciclo, $liga);
    // Procura a temporada DENTRO da sprint atual — pelo mesmo motivo: com a
    // numeração reiniciando, "temporada 5" existe em toda sprint, e pegar a
    // de outra daria por fechado um bloco que nem começou.
    foreach (cicloTemporadasDaSprint($pdo, $liga) as $t) {
        if ((int)$t['season_number'] === $ate) {
            return strtolower((string)($t['status'] ?? '')) === 'completed';
        }
    }
    return false;
}

/**
 * A PARTIR DE QUE TEMPORADA cada time conta pontos.
 *
 * Quando um GM sobe de liga (ou desiste), a cadeira fica pra outra pessoa — e
 * quem assume não herda o que o anterior fez. O admin já zera o
 * `teams.ranking_points` nessas trocas; esta função é o mesmo corte aplicado
 * ao histórico por temporada, que é de onde os blocos somam.
 *
 * A data da troca vem de `team_gm_historico` e virá sempre a MAIS RECENTE: um
 * time pode ter trocado três vezes, e o que vale é o GM que está lá agora. A
 * temporada sai de `seasons.created_at` — é a última temporada que já havia
 * começado quando a troca aconteceu.
 *
 * @return array [team_id => primeira temporada que conta]
 */
function cicloCortesDeGm(PDO $pdo, string $liga): array
{
    static $cache = [];
    $liga = strtoupper(trim($liga));
    if (isset($cache[$liga])) return $cache[$liga];

    $cache[$liga] = [];
    try {
        $st = $pdo->prepare("
            SELECT h.team_id,
                   (SELECT MAX(se.season_number) FROM seasons se
                     WHERE se.league = ? AND se.created_at <= h.criado_em) AS temporada
              FROM team_gm_historico h
              JOIN teams t ON t.id = h.team_id
             WHERE t.league = ?
               AND h.user_id_novo IS NOT NULL
               AND h.criado_em = (SELECT MAX(h2.criado_em) FROM team_gm_historico h2
                                   WHERE h2.team_id = h.team_id AND h2.user_id_novo IS NOT NULL)");
        $st->execute([$liga, $liga]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t = (int)($r['temporada'] ?? 0);
            // Troca antes da primeira temporada não corta nada: o GM está lá
            // desde o começo, mesmo que o registro exista.
            if ($t > 1) $cache[$liga][(int)$r['team_id']] = $t;
        }
    } catch (Throwable $e) {
        error_log('[ciclos] cortes de gm: ' . $e->getMessage());
    }
    return $cache[$liga];
}

/**
 * A soma de pontos de cada time num bloco.
 *
 * Devolve as linhas ordenadas, com posição já calculada. Empate é resolvido
 * por mais temporadas pontuadas e depois pelo nome — sem critério de desempate
 * a ordem mudava a cada carregamento e a mesma tela mostrava campeões
 * diferentes.
 *
 * @param bool $cortarPorGm conta só o que o GM ATUAL fez. Vale na
 *        classificação geral e no bloco em andamento, e NÃO num bloco já
 *        fechado: a Sprint 1 da ROOKIE foi ganha pelo Marcos com o Hornets, e
 *        aplicar o corte lá apagaria o campeão de uma competição que
 *        aconteceu — justamente a que dá a promoção.
 */
function cicloClassificacao(PDO $pdo, int $ciclo, string $liga = CICLO_LIGA, bool $cortarPorGm = false): array
{
    $liga = strtoupper(trim($liga));
    [$de, $ate] = cicloIntervalo($pdo, $ciclo, $liga);
    try {
        // MESMO recorte da aba da liga (get_points_history em
        // api/history-points.php): temporadas da sprint ATIVA, ligadas por
        // season_id. Filtrar por tsp.season_number — que é uma cópia guardada
        // na linha de pontos — fazia a janela pegar temporadas diferentes das
        // que a aba da liga soma, e os dois números divergiam sem explicação.
        //
        // A única diferença pra ela é o BETWEEN: aqui só as do bloco.
        $st = $pdo->prepare("
            SELECT tsp.team_id,
                   TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS time,
                   t.photo_url,
                   SUM(tsp.points)                 AS pontos,
                   COUNT(DISTINCT s.season_number) AS temporadas,
                   /* Os TÍTULOS da janela saem de playoff_results, e não de
                      team_season_points: lá só há pontuação, e pontos de
                      playoff não dizem quem foi campeão — um vice pontua. Esta
                      tabela também sobrevive ao reset de sprint, ao contrário
                      de team_ranking_points, que é apagada nele. */
                   /* A liga vem por PARÂMETRO, e não de `s2.league =
                      tsp.league`: as duas colunas têm collation diferente
                      (uca1400 numa, unicode noutra) e compará-las derruba a
                      consulta com erro 1267, de mistura ilegal de collations —
                      a tabela inteira vinha vazia, em todas as ligas. */
                   (SELECT COUNT(*) FROM playoff_results pr
                     JOIN seasons s2 ON s2.id = pr.season_id
                    WHERE pr.team_id = tsp.team_id
                      AND pr.position = 'champion'
                      AND s2.league = ?
                      AND s2.season_number BETWEEN ? AND ?
                      AND s2.sprint_id = s.sprint_id) AS titulos
            FROM team_season_points tsp
            JOIN seasons s ON s.id = tsp.season_id
            LEFT JOIN teams t ON t.id = tsp.team_id
            WHERE tsp.league = ?
              AND s.season_number BETWEEN ? AND ?
              AND s.sprint_id = (SELECT id FROM sprints
                                 WHERE league = ? AND status = 'active'
                                 ORDER BY id DESC LIMIT 1)
            GROUP BY tsp.team_id, time, t.photo_url, s.sprint_id
            ORDER BY pontos DESC, titulos DESC, time ASC");
        $st->execute([$liga, $de, $ate, $liga, $de, $ate, $liga]);
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[ciclos] classificacao: ' . $e->getMessage());
        return [];
    }

    if ($cortarPorGm) $linhas = cicloAplicarCorte($pdo, $liga, $linhas, $de, $ate);

    $pos = 0;
    foreach ($linhas as &$l) {
        $l['pos'] = ++$pos;
        foreach (['pontos', 'temporadas', 'titulos', 'team_id'] as $k) {
            $l[$k] = (int)($l[$k] ?? 0);
        }
    }
    return $linhas;
}

/**
 * Refaz a soma dos times que trocaram de GM, contando só a partir da troca.
 *
 * Só recalcula QUEM TROCOU: uma segunda consulta pra três ou quatro times é
 * barata, e refazer a conta de todos em PHP jogaria fora o GROUP BY do banco
 * pra nada.
 *
 * O time cuja troca é posterior ao bloco inteiro fica com zero — e continua na
 * lista, porque ele existe e está disputando: sumir da tabela faria parecer
 * que o clube deixou a liga.
 */
function cicloAplicarCorte(PDO $pdo, string $liga, array $linhas, int $de, int $ate): array
{
    $cortes = cicloCortesDeGm($pdo, $liga);
    if (!$cortes) return $linhas;

    foreach ($linhas as &$l) {
        $corte = $cortes[(int)$l['team_id']] ?? 0;
        if ($corte <= $de) continue;   // o GM atual já estava lá no começo do bloco

        if ($corte > $ate) {
            // Assumiu depois do bloco todo: não fez nada dentro dele.
            $l['pontos'] = 0; $l['temporadas'] = 0; $l['titulos'] = 0;
            continue;
        }

        try {
            /* O TÍTULO TAMBÉM ENTRA NO CORTE. Ele é conquista de quem estava
               lá: o Hall da Fama já leva os títulos com o GM quando ele sobe
               (hallSeguirGm), então deixá-los somando no time que ele deixou
               daria o mesmo título a duas pessoas. */
            $st = $pdo->prepare("
                SELECT SUM(tsp.points) AS pontos,
                       COUNT(DISTINCT s.season_number) AS temporadas,
                       (SELECT COUNT(*) FROM playoff_results pr
                         JOIN seasons s2 ON s2.id = pr.season_id
                        WHERE pr.team_id = ? AND pr.position = 'champion'
                          AND s2.league = ? AND s2.season_number BETWEEN ? AND ?) AS titulos
                  FROM team_season_points tsp
                  JOIN seasons s ON s.id = tsp.season_id
                 WHERE tsp.team_id = ? AND tsp.league = ?
                   AND s.season_number BETWEEN ? AND ?");
            $st->execute([(int)$l['team_id'], $liga, $corte, $ate,
                          (int)$l['team_id'], $liga, $corte, $ate]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (['pontos', 'temporadas', 'titulos'] as $k) {
                $l[$k] = (int)($r[$k] ?? 0);
            }
        } catch (Throwable $e) {
            error_log('[ciclos] corte: ' . $e->getMessage());
        }
    }
    unset($l);

    // A ordem muda depois do corte: quem zerou desce.
    usort($linhas, fn($a, $b) =>
        [(int)$b['pontos'], (int)$b['titulos'], $a['time']]
    <=> [(int)$a['pontos'], (int)$a['titulos'], $b['time']]);

    return $linhas;
}

/**
 * A CLASSIFICAÇÃO GERAL da sprint: todas as temporadas somadas.
 *
 * É a outra aba que a ROOKIE precisa. O comunicado é explícito — "a
 * classificação geral continua valendo normalmente" —, então ela não pode ser
 * derivada somando os blocos na tela: bloco só entra na conta quando tem
 * pontuação, e a geral tem que contar tudo, inclusive a temporada em curso.
 */
function cicloClassificacaoGeral(PDO $pdo, string $liga = CICLO_LIGA): array
{
    $cfg = cicloConfig($liga);
    // O bloco 1 até o último cobre a sprint inteira: é a janela da geral.
    $ultimo = $cfg['blocos'] * $cfg['tamanho'];
    $liga = strtoupper(trim($liga));

    try {
        $st = $pdo->prepare("
            SELECT tsp.team_id,
                   TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS time,
                   t.photo_url,
                   SUM(tsp.points)                 AS pontos,
                   COUNT(DISTINCT s.season_number) AS temporadas,
                   /* Liga por parâmetro — ver a nota em cicloClassificacao
                      sobre o choque de collation. */
                   (SELECT COUNT(*) FROM playoff_results pr
                     JOIN seasons s2 ON s2.id = pr.season_id
                    WHERE pr.team_id = tsp.team_id
                      AND pr.position = 'champion'
                      AND s2.league = ?
                      AND s2.season_number BETWEEN 1 AND ?
                      AND s2.sprint_id = s.sprint_id) AS titulos
            FROM team_season_points tsp
            JOIN seasons s ON s.id = tsp.season_id
            LEFT JOIN teams t ON t.id = tsp.team_id
            WHERE tsp.league = ?
              AND s.season_number BETWEEN 1 AND ?
              AND s.sprint_id = (SELECT id FROM sprints
                                 WHERE league = ? AND status = 'active'
                                 ORDER BY id DESC LIMIT 1)
            GROUP BY tsp.team_id, time, t.photo_url, s.sprint_id
            ORDER BY pontos DESC, titulos DESC, time ASC");
        $st->execute([$liga, $ultimo, $liga, $ultimo, $liga]);
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[ciclos] geral: ' . $e->getMessage());
        return [];
    }

    /* A GERAL É A DISPUTA VIVA, então ela conta só o que o GM atual fez: quem
       subiu pra Rise saiu da briga, e deixar os pontos dele na tabela daria ao
       time que ele deixou uma vantagem que o novo GM não conquistou. */
    if ($cfg['corte_gm']) $linhas = cicloAplicarCorte($pdo, $liga, $linhas, 1, $ultimo);

    $pos = 0;
    foreach ($linhas as &$l) {
        $l['pos'] = ++$pos;
        foreach (['pontos', 'temporadas', 'titulos', 'team_id'] as $k) {
            $l[$k] = (int)$l[$k];
        }
    }
    return $linhas;
}

/**
 * Um resumo por bloco da sprint — é o que vira os cards do topo.
 *
 * Cada card mostra o campeão da SOMA das temporadas do bloco, não o líder de
 * uma temporada isolada: o bloco é a unidade, e mostrar vencedor por temporada
 * responderia uma pergunta que ninguém fez.
 *
 * Vêm todos os blocos da sprint, inclusive o que está rolando (marcado como
 * em andamento) e os que ainda não têm pontuação — a sequência é o que deixa
 * claro em que ponto da sprint a liga está.
 */
function cicloResumos(PDO $pdo, string $liga = CICLO_LIGA): array
{
    $quantos = cicloQuantos($pdo, $liga);
    if (!$quantos) return [];

    $atual = cicloAtual($pdo, $liga);
    $cfg = cicloConfig($liga);
    $premio = $cfg['premio'];

    $out = [];
    for ($c = 1; $c <= $quantos; $c++) {
        [$de, $ate] = cicloIntervalo($pdo, $c, $liga);
        $fechado = cicloFechado($pdo, $c, $liga);
        /* O CORTE NÃO ENTRA EM BLOCO FECHADO. Bloco fechado é resultado, e
           resultado não se reescreve quando o campeão muda de liga — a Sprint 1
           da ROOKIE continua sendo do Hornets com o Marcos. */
        $tab = cicloClassificacao($pdo, $c, $liga, $cfg['corte_gm'] && !$fechado);
        $tem = $tab && $tab[0]['pontos'] > 0;

        $out[] = [
            'ciclo'       => $c,
            'de'          => $de,
            'ate'         => $ate,
            'fechado'     => $fechado,
            // Distingue o bloco que esta rolando dos que nem comecaram: os dois
            // apareciam como "em andamento", e o ultimo nao comecou nada.
            'atual'       => ($c === $atual),
            'futuro'      => ($c > $atual),
            'tem_dados'   => $tem,
            // Fechado com pontuação = campeão. Em andamento = quem lidera
            // agora, e a tela diz que é parcial: anunciar "campeão" de um
            // bloco que ainda corre seria dar título que pode mudar de dono.
            'campeao'     => $tem ? ($tab[0]['time'] ?: 'Time #' . $tab[0]['team_id']) : null,
            'team_id'     => $tem ? $tab[0]['team_id'] : null,
            'photo_url'   => $tem ? $tab[0]['photo_url'] : null,
            'pontos'      => $tem ? $tab[0]['pontos'] : null,
            'vice'        => $tem && isset($tab[1]) ? $tab[1]['time'] : null,
            'vice_pontos' => $tem && isset($tab[1]) ? $tab[1]['pontos'] : null,
            'times'       => $tab ? count($tab) : 0,
            'premio'      => $premio,
        ];
    }
    return $out;
}

/** Só os blocos fechados COM pontuação — os campeões de verdade. */
function cicloCampeoes(PDO $pdo, string $liga = CICLO_LIGA): array
{
    return array_values(array_filter(cicloResumos($pdo, $liga),
        fn($r) => $r['fechado'] && $r['tem_dados']));
}

/**
 * Tudo que a tela precisa de uma liga, num pacote.
 *
 * Existe pra que rankings.php não precise chamar seis funções e montar o
 * mesmo array duas vezes — uma pra cada liga.
 */
function cicloPacoteDaLiga(PDO $pdo, string $liga): array
{
    $cfg = cicloConfig($liga);
    $atual = cicloAtual($pdo, $liga);

    $tabelas = [];
    foreach (cicloResumos($pdo, $liga) as $r) {
        // Mesma régua dos cards: corte só onde o bloco ainda está em disputa.
        $tabelas[$r['ciclo']] = cicloClassificacao($pdo, $r['ciclo'], $liga,
                                                   $cfg['corte_gm'] && !$r['fechado']);
    }

    return [
        'liga'            => strtoupper(trim($liga)),
        'rotulo'          => $cfg['rotulo'],
        'tamanho'         => $cfg['tamanho'],
        'premio'          => $cfg['premio'],
        'temporada_atual' => cicloTemporadaAtual($pdo, $liga),
        'ciclo_atual'     => $atual,
        'ciclos'          => cicloResumos($pdo, $liga),
        // A tabela de CADA bloco, pra a tela trocar sem ida ao servidor: são
        // poucas linhas e o jogador clica de um card pro outro na sequência.
        'tabelas'         => $tabelas,
        'geral'           => cicloClassificacaoGeral($pdo, $liga),
    ];
}
