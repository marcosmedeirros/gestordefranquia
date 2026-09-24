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
 *   genero    'm' ou 'f' — o gênero do rótulo, pra quem escreve frase com ele
 *   premio    quanto vale o bloco em reais, ou null quando não há prêmio
 *   corte_gm  a pontuação conta só a partir de quando o GM atual assumiu?
 *   sobe_geral quantos sobem pela classificação geral no fim da edição (0 = nenhum)
 *   aba_padrao qual tabela a liga abre: 'geral' ou 'bloco'
 *
 * A aba_padrao é 'geral' na ELITE e 'bloco' na ROOKIE porque é o que cada
 * liga é: a ROOKIE é jogada em Sprints — a Sprint é que vale os R$ 40 e a
 * vaga —, e na ELITE o ciclo é uma janela a mais sobre a tabela que sempre
 * foi a principal. Quando a ELITE deixou de ter botão próprio e passou a
 * abrir aqui, abrir no ciclo escondia a classificação que todo mundo procura.
 *
 * O sobe_geral é 4 na ROOKIE e 0 na ELITE porque subir é regra da ROOKIE: a
 * Sprint alimenta a lista de desistência e a geral sobe os 4 primeiros
 * (regra fechada em 23/09/2026 — ver cicloFilaDeSubida). Da ELITE não se
 * sobe pra lugar nenhum.
 *
 * O genero anda junto do rotulo porque é propriedade dele: "Sprint" é
 * feminino e "Ciclo" masculino, e o bot escreve frase com o rótulo dentro
 * ("faltam 4 Sprints depois desta" / "4 Ciclos depois deste"). Sem isso a
 * concordância sai errada em uma das duas ligas, sempre.
 *
 * O corte_gm está ligado só na ROOKIE porque é lá que a regra existe: GM que
 * sobe pra Rise deixa a cadeira, e quem assume não herda os pontos de quem
 * subiu (o admin já zera o ranking_points nessas trocas). Na ELITE ele fica
 * desligado até alguém pedir — ligar sozinho mudaria a aba ELITE 5T, que não
 * foi o que pediram.
 */
const CICLO_CONFIG = [
    'ELITE'  => ['tamanho' => 5, 'blocos' => 5, 'rotulo' => 'Ciclo',  'genero' => 'm', 'premio' => null, 'corte_gm' => false, 'sobe_geral' => 0, 'aba_padrao' => 'geral'],
    'ROOKIE' => ['tamanho' => 3, 'blocos' => 5, 'rotulo' => 'Sprint', 'genero' => 'f', 'premio' => 40,   'corte_gm' => true,  'sobe_geral' => 4, 'aba_padrao' => 'bloco'],
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
            $st = $pdo->prepare("SELECT season_number, status, created_at FROM seasons
                                 WHERE sprint_id = ? ORDER BY season_number ASC");
            $st->execute([(int)$sprint['id']]);
            $cache[$liga] = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        // Sem sprint ativa — ou com uma sprint que não tem temporada
        // amarrada —, cai pras temporadas da liga. Melhor um ranking com a
        // faixa toda que uma tela zerada sem explicação.
        if (!$cache[$liga]) {
            $st = $pdo->prepare("SELECT season_number, status, created_at FROM seasons
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
/**
 * A TABELA DE UMA JANELA DE TEMPORADAS, com a liga INTEIRA dentro.
 *
 * As duas tabelas do ranking — a do bloco e a geral — faziam esta mesma
 * consulta, uma em cada função, mudando só o BETWEEN. Agora é uma só.
 *
 * E ela parte de `teams`, não de `team_season_points`. Partindo dos pontos,
 * quem ainda não pontuou NESTA sprint não tem linha lá e sumia da tabela
 * inteira: a ELITE tem 32 times e o ranking mostrava 30, faltando Miami
 * Sunsets e Colorado Goats — os dois com histórico em sprints antigas e nada
 * na atual. Um time sem ponto é um time com zero ponto, não um time que não
 * existe; a última colocação também é informação.
 *
 * Os TÍTULOS saem de playoff_results, e não de team_season_points: lá só há
 * pontuação, e pontos de playoff não dizem quem foi campeão — um vice pontua.
 * Essa tabela também sobrevive ao reset de sprint, ao contrário de
 * team_ranking_points, que é apagada nele.
 *
 * A liga vai por PARÂMETRO em toda comparação, nunca `s2.league = tsp.league`:
 * as duas colunas têm collation diferente (uca1400 numa, unicode noutra) e
 * compará-las derruba a consulta com erro 1267, de mistura ilegal de
 * collations — a tabela inteira vinha vazia, em todas as ligas.
 *
 * @param int $de  primeira temporada da janela (inclusive)
 * @param int $ate última temporada da janela (inclusive)
 */
function cicloLinhasDaJanela(PDO $pdo, string $liga, int $de, int $ate): array
{
    $liga = strtoupper(trim($liga));
    try {
        $st = $pdo->prepare("SELECT id FROM sprints
                              WHERE league = ? AND status = 'active'
                           ORDER BY id DESC LIMIT 1");
        $st->execute([$liga]);
        $sprintId = (int)($st->fetchColumn() ?: 0);
        // Sem sprint ativa não há janela: devolver a liga toda zerada seria
        // inventar uma disputa que não começou.
        if (!$sprintId) return [];

        $st = $pdo->prepare("
            SELECT t.id AS team_id,
                   TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS time,
                   /* A alcunha separada: dentro de uma liga ela identifica o time sozinha,
                      e quem escreve pro WhatsApp precisa da lista curta. Cortar a cidade
                      do nome completo por contagem de palavras não funciona — 'San Diego
                      Empire' virava 'Diego Empire' e 'St. Louis Archers', 'Louis Archers'. */
                   COALESCE(NULLIF(TRIM(t.name),''), TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,'')))) AS alcunha,
                   t.photo_url,
                   COALESCE(p.pontos, 0)     AS pontos,
                   COALESCE(p.temporadas, 0) AS temporadas,
                   (SELECT COUNT(*) FROM playoff_results pr
                     JOIN seasons s2 ON s2.id = pr.season_id
                    WHERE pr.team_id = t.id
                      AND pr.position = 'champion'
                      AND s2.league = ?
                      AND s2.season_number BETWEEN ? AND ?
                      AND s2.sprint_id = ?) AS titulos
              FROM teams t
              LEFT JOIN (
                    SELECT tsp.team_id,
                           SUM(tsp.points)                 AS pontos,
                           COUNT(DISTINCT s.season_number) AS temporadas
                      FROM team_season_points tsp
                      JOIN seasons s ON s.id = tsp.season_id
                     WHERE tsp.league = ?
                       AND s.season_number BETWEEN ? AND ?
                       AND s.sprint_id = ?
                  GROUP BY tsp.team_id
              ) p ON p.team_id = t.id
             WHERE t.league = ?
          ORDER BY pontos DESC, titulos DESC, time ASC");
        $st->execute([$liga, $de, $ate, $sprintId, $liga, $de, $ate, $sprintId, $liga]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[ciclos] janela: ' . $e->getMessage());
        return [];
    }
}

function cicloClassificacao(PDO $pdo, int $ciclo, string $liga = CICLO_LIGA, bool $cortarPorGm = false): array
{
    $liga = strtoupper(trim($liga));
    [$de, $ate] = cicloIntervalo($pdo, $ciclo, $liga);

    // MESMO recorte da aba da liga (get_points_history em
    // api/history-points.php): temporadas da sprint ATIVA, ligadas por
    // season_id. A única diferença pra geral é o BETWEEN: aqui só as do bloco.
    $linhas = cicloLinhasDaJanela($pdo, $liga, $de, $ate);

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

    $linhas = cicloLinhasDaJanela($pdo, $liga, 1, $ultimo);

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
            /* Só a alcunha, pra quem escreve em linha curta (o bot, no grupo).
               Vem do banco e não de um corte do nome completo: "San Diego
               Empire" não tem como ser partido por contagem de palavras. */
            'campeao_curto' => $tem ? ($tab[0]['alcunha'] ?: $tab[0]['time']) : null,
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
 * As subidas de GM que já aconteceram, por franquia: team_id => [datas].
 *
 * Quem sobe é o GM, não a franquia: a cadeira fica pra outra pessoa e o time
 * continua na ROOKIE (é por isso que existe o corte_gm). Então a subida se lê
 * no histórico da cadeira, não em teams.league — o time nunca sai da liga.
 *
 * As datas vêm em ordem porque quem chama consome uma por bloco: ver a nota
 * em cicloFilaDeSubida sobre marcar o bloco errado como usado.
 */
function cicloSubidasDeGm(PDO $pdo, string $liga = CICLO_LIGA): array
{
    static $cache = [];
    $liga = strtoupper(trim($liga));
    if (isset($cache[$liga])) return $cache[$liga];

    $cache[$liga] = [];
    try {
        // Liga por parâmetro, nunca coluna com coluna — ver a nota sobre o
        // choque de collation em cicloClassificacao.
        $st = $pdo->prepare("SELECT h.team_id, h.criado_em
                               FROM team_gm_historico h
                              WHERE h.league = ?
                                AND h.motivo LIKE 'subiu%'
                           ORDER BY h.criado_em ASC");
        $st->execute([$liga]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cache[$liga][(int)$r['team_id']][] = (string)$r['criado_em'];
        }
    } catch (Throwable $e) {
        error_log('[ciclos] subidas de gm: ' . $e->getMessage());
    }
    return $cache[$liga];
}

/**
 * A FILA DE SUBIDA e quem sobe pela geral — a regra fechada em 23/09/2026.
 *
 * As Sprints existem pra alimentar a LISTA DE DESISTÊNCIA: no momento em que
 * alguém desiste na Rise, sobe o primeiro da lista. O campeão da Sprint leva
 * os R$ 40 de qualquer jeito, tenha vaga aberta ou não. No fim da edição, a
 * classificação geral sobe os 4 primeiros, tirando quem já subiu por
 * desistência.
 *
 * Duas coisas da regra que a conta tem que respeitar:
 *
 * - CAMPEÃO REPETIDO não ocupa duas vagas. Quem ganha duas Sprints leva os
 *   R$ 40 das duas, mas a segunda vaga desce pro próximo daquele bloco —
 *   senão a fila teria um nome só e as outras Sprints não valeriam subida.
 *
 * - "JÁ SUBIU" se lê por bloco, e cada registro de subida é consumido por UM
 *   bloco, em ordem. Sem isso a subida do GM campeão da Sprint 3 marcaria a
 *   vaga da Sprint 1 como usada também, e a fila esvaziaria sozinha.
 *
 * O que NÃO está aqui: quantas vagas existem. Isso depende de quantos
 * desistiram na Rise e é chamada de admin. A tela mostra a fila e quem já
 * subiu; quem promove é gente.
 */
function cicloFilaDeSubida(PDO $pdo, string $liga = CICLO_LIGA): array
{
    $cfg = cicloConfig($liga);
    $liga = strtoupper(trim($liga));

    // A data de cada temporada, pra saber o que é "depois do bloco".
    $quando = [];
    foreach (cicloTemporadasDaSprint($pdo, $liga) as $t) {
        $quando[(int)$t['season_number']] = (string)($t['created_at'] ?? '');
    }

    $subidas = cicloSubidasDeGm($pdo, $liga);
    $consumidas = [];  // team_id => quantos registros de subida já foram usados
    $naFila = [];      // team_id => já tem vaga na fila
    $fila = [];

    foreach (cicloCampeoes($pdo, $liga) as $r) {
        [, $ate] = cicloIntervalo($pdo, $r['ciclo'], $liga);
        // Bloco fechado não leva corte — resultado não se reescreve. É a
        // mesma tabela que a tela mostra no card do bloco.
        $tab = cicloClassificacao($pdo, $r['ciclo'], $liga, false);
        if (!$tab) continue;

        // A vaga é do campeão; se ele já está na fila, desce pro próximo do
        // bloco que ainda não está. Sem pontuação ninguém entra.
        $vaga = null;
        foreach ($tab as $l) {
            if ((int)$l['pontos'] <= 0) break;
            if (!isset($naFila[(int)$l['team_id']])) { $vaga = $l; break; }
        }
        if (!$vaga) continue;

        $vagaId = (int)$vaga['team_id'];
        $naFila[$vagaId] = true;

        // Consome a primeira subida daquela cadeira datada depois do fim do
        // bloco. Uma subida anterior ao bloco é de outra fila, e uma já
        // consumida por um bloco anterior não vale duas vezes.
        $fim = $quando[$ate] ?? '';
        $jaSubiu = null;
        $lista = $subidas[$vagaId] ?? [];
        $i = $consumidas[$vagaId] ?? 0;
        for (; $i < count($lista); $i++) {
            if ($fim === '' || $lista[$i] >= $fim) {
                $jaSubiu = $lista[$i];
                $consumidas[$vagaId] = $i + 1;
                break;
            }
        }

        $fila[] = [
            'ciclo'    => $r['ciclo'],
            'de'       => $r['de'],
            'ate'      => $r['ate'],
            // O campeão leva o prêmio mesmo quando a vaga desce pro próximo.
            'campeao'  => $r['campeao_curto'] ?: $r['campeao'],
            'premio'   => $r['premio'],
            'team_id'  => $vagaId,
            'nome'     => (string)($vaga['alcunha'] ?: $vaga['time']),
            'pontos'   => (int)$vaga['pontos'],
            'repetido' => $vagaId !== (int)$r['team_id'],
            'ja_subiu' => $jaSubiu,
        ];
    }

    /* OS QUE SOBEM PELA GERAL.
       A regra diz "tirando os que subiram por desistência", e na ROOKIE isso
       já vem de graça: a geral aplica o corte_gm, então os pontos de quem
       subiu não estão mais na linha — o que está lá é o que o GM novo fez.
       Excluir a FRANQUIA seria tirar a vaga de quem assumiu e pontuou. */
    $sobeGeral = [];
    $quantos = (int)($cfg['sobe_geral'] ?? 0);
    if ($quantos > 0) {
        foreach (cicloClassificacaoGeral($pdo, $liga) as $l) {
            if (count($sobeGeral) >= $quantos) break;
            if ((int)$l['pontos'] <= 0) break;
            $sobeGeral[] = [
                'team_id'  => (int)$l['team_id'],
                'nome'     => (string)($l['alcunha'] ?: $l['time']),
                'pontos'   => (int)$l['pontos'],
                // A cadeira já trocou por subida no meio da edição? Não tira
                // ninguém da lista, mas o admin precisa ver.
                'trocou'   => !empty($subidas[(int)$l['team_id']]),
            ];
        }
    }

    return [
        'sobe_geral'  => $quantos,
        'fila'        => array_values(array_filter($fila, fn($f) => !$f['ja_subiu'])),
        'blocos'      => $fila,       // a lista inteira, inclusive quem já subiu
        'ja_subiram'  => array_values(array_filter($fila, fn($f) => (bool)$f['ja_subiu'])),
        'geral'       => $sobeGeral,
    ];
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
        'genero'          => $cfg['genero'] ?? 'm',
        'aba_padrao'      => $cfg['aba_padrao'] ?? 'bloco',
        'tamanho'         => $cfg['tamanho'],
        'premio'          => $cfg['premio'],
        'temporada_atual' => cicloTemporadaAtual($pdo, $liga),
        'ciclo_atual'     => $atual,
        'ciclos'          => cicloResumos($pdo, $liga),
        // A tabela de CADA bloco, pra a tela trocar sem ida ao servidor: são
        // poucas linhas e o jogador clica de um card pro outro na sequência.
        'tabelas'         => $tabelas,
        'geral'           => cicloClassificacaoGeral($pdo, $liga),
        // A fila de desistência e quem sobe pela geral: é a pergunta que vem
        // logo depois de "quem ganhou a Sprint" — ver cicloFilaDeSubida.
        'subida'          => cicloFilaDeSubida($pdo, $liga),
    ];
}
