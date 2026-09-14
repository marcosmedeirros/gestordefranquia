<?php
/**
 * Estatísticas de playoff da sprint ativa — UMA conta, usada pela página
 * (estatisticas.php) e pelo bot (backend/estatisticas_bot.php).
 *
 * A fonte é o que o admin registra ao fechar cada temporada (api/seasons.php):
 *   playoff_results      onde cada time parou: champion, runner_up,
 *                        conference_final, second_round, first_round
 *   team_ranking_points  regular_season_position — a posição na conferência,
 *                        que é o seed de quem foi ao playoff (1 a 8)
 *   playoff_series       as séries do chaveamento: quem enfrentou quem, quem
 *                        passou e em quantos jogos
 *
 * Antes estas seções liam playoff_brackets e playoff_matches, que o fluxo de
 * fechar temporada não preenche mais: na sprint ativa as duas tabelas estão
 * vazias nas quatro ligas, e por isso Títulos, Dinastia, Eterno Vice, Seed
 * Médio, Rivalidades e Domínio saíam "Sem dados" (14/09/2026).
 *
 * Saída no formato do resto da página: [liga => [linha, ...]] já ordenado,
 * cada linha com 'name' e 'count' — e 'a', 'b', 'a_long', 'b_long' nas duplas.
 */

function epSprintAtiva(): string
{
    return "(SELECT id FROM seasons WHERE sprint_id IN (SELECT id FROM sprints WHERE status = 'active'))";
}

function epNome(?string $cidade, ?string $nome): string
{
    return trim(trim((string) $cidade) . ' ' . trim((string) $nome));
}

/** Roda uma conta sem derrubar a página, mas deixando rastro no log. */
function epSeguro(callable $conta, string $qual): array
{
    try {
        return $conta();
    } catch (Throwable $e) {
        error_log('[estatisticas ' . $qual . '] ' . $e->getMessage());
        return [];
    }
}

function epOrdena(array $linhas, bool $crescente = false): array
{
    usort($linhas, function ($a, $b) use ($crescente) {
        $c = $crescente ? $a['count'] <=> $b['count'] : $b['count'] <=> $a['count'];
        return $c ?: strcasecmp($a['name'], $b['name']);
    });
    return $linhas;
}

/** Campeões e vices das temporadas fechadas da sprint, por liga, em ordem de temporada. */
function epFinalistas(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $st = $pdo->query("
        SELECT s.league, s.season_number, pr.team_id, pr.position, t.city, t.name
        FROM playoff_results pr
        JOIN seasons s ON s.id = pr.season_id
        JOIN teams t ON t.id = pr.team_id
        WHERE pr.position IN ('champion', 'runner_up') AND pr.season_id IN " . epSprintAtiva() . "
        ORDER BY s.league, s.season_number, pr.position");
    $cache = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['nome'] = epNome($r['city'], $r['name']);
        $cache[$r['league']][] = $r;
    }
    return $cache;
}

/** Ranking de Títulos: quantas vezes foi campeão na sprint. */
function epTitulos(PDO $pdo): array
{
    $map = [];
    foreach (epFinalistas($pdo) as $lg => $linhas) {
        $conta = [];
        foreach ($linhas as $r) {
            if ($r['position'] !== 'champion') continue;
            $tid = (int) $r['team_id'];
            $conta[$tid] ??= ['team_id' => $tid, 'name' => $r['nome'], 'count' => 0];
            $conta[$tid]['count']++;
        }
        if ($conta) $map[$lg] = epOrdena(array_values($conta));
    }
    return $map;
}

/**
 * Maior Dinastia: a maior sequência de títulos em temporadas seguidas.
 * Todo campeão entra (sequência 1 é um título); bicampeão seguido vale 2.
 */
function epDinastia(PDO $pdo): array
{
    $map = [];
    foreach (epFinalistas($pdo) as $lg => $linhas) {
        $melhor = [];
        $atualId = null;
        $atual = 0;
        $ultima = null;
        foreach ($linhas as $r) {
            if ($r['position'] !== 'champion') continue;
            $tid = (int) $r['team_id'];
            $num = (int) $r['season_number'];
            // Só é sequência se a temporada for a seguinte: campeão na 1 e na 3
            // não é bicampeão seguido.
            $atual = ($tid === $atualId && $ultima !== null && $num === $ultima + 1) ? $atual + 1 : 1;
            $atualId = $tid;
            $ultima = $num;
            if (!isset($melhor[$tid]) || $atual > $melhor[$tid]['count']) {
                $melhor[$tid] = ['team_id' => $tid, 'name' => $r['nome'], 'count' => $atual];
            }
        }
        if ($melhor) $map[$lg] = epOrdena(array_values($melhor));
    }
    return $map;
}

/**
 * Eterno Vice: vice-campeonatos de quem nunca foi campeão na sprint. Se todo
 * vice também tem título (raro), a lista mostra os vices marcando o título.
 */
function epEternoVice(PDO $pdo): array
{
    $map = [];
    foreach (epFinalistas($pdo) as $lg => $linhas) {
        $vices = [];
        $campeao = [];
        foreach ($linhas as $r) {
            $tid = (int) $r['team_id'];
            if ($r['position'] === 'champion') { $campeao[$tid] = true; continue; }
            $vices[$tid] ??= ['team_id' => $tid, 'name' => $r['nome'], 'count' => 0];
            $vices[$tid]['count']++;
        }
        $semTitulo = array_values(array_filter($vices, fn($v) => empty($campeao[$v['team_id']])));
        if (!$semTitulo && $vices) {
            $semTitulo = array_map(fn($v) => ['name' => $v['name'] . ' (tem título)'] + $v, array_values($vices));
        }
        if ($semTitulo) $map[$lg] = epOrdena($semTitulo);
    }
    return $map;
}

/**
 * Seed Médio no Playoff: a posição média na conferência de quem foi ao
 * playoff. Menor = entra mais favorito.
 */
function epSeedMedio(PDO $pdo): array
{
    $st = $pdo->query("
        SELECT s.league, pr.team_id, t.city, t.name,
               COUNT(*) AS vezes, AVG(r.regular_season_position) AS seed
        FROM playoff_results pr
        JOIN seasons s ON s.id = pr.season_id
        JOIN teams t ON t.id = pr.team_id
        JOIN team_ranking_points r ON r.season_id = pr.season_id AND r.team_id = pr.team_id
        WHERE pr.season_id IN " . epSprintAtiva() . " AND r.regular_season_position > 0
        GROUP BY s.league, pr.team_id, t.city, t.name");
    $porLiga = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $porLiga[$r['league']][] = [
            'team_id' => (int) $r['team_id'],
            'name'    => epNome($r['city'], $r['name']),
            'count'   => round((float) $r['seed'], 1),
            'vezes'   => (int) $r['vezes'],
        ];
    }
    $map = [];
    foreach ($porLiga as $lg => $linhas) {
        // A média de um playoff só é o seed daquela temporada: entra quem foi
        // pelo menos duas vezes. Em sprint curta, sem ninguém com duas, vale uma.
        $duas = array_values(array_filter($linhas, fn($l) => $l['vezes'] >= 2));
        $map[$lg] = epOrdena($duas ?: $linhas, true);
    }
    return $map;
}

/** As duplas que se enfrentaram em série de playoff na sprint, por liga. */
function epPares(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $st = $pdo->query("
        SELECT s.league, ps.team_a_id, ps.team_b_id, ps.winner_team_id, ps.jogos,
               ta.city AS ca, ta.name AS na, tb.city AS cb, tb.name AS nb
        FROM playoff_series ps
        JOIN seasons s ON s.id = ps.season_id
        JOIN teams ta ON ta.id = ps.team_a_id
        JOIN teams tb ON tb.id = ps.team_b_id
        WHERE ps.season_id IN " . epSprintAtiva() . "
        ORDER BY s.league, s.season_number");
    $cache = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $a = (int) $r['team_a_id'];
        $b = (int) $r['team_b_id'];
        if ($a <= 0 || $b <= 0 || $a === $b) continue;
        $lg = $r['league'];
        $k = min($a, $b) . '|' . max($a, $b);
        if (!isset($cache[$lg][$k])) {
            $cache[$lg][$k] = ['vit' => [$a => 0, $b => 0], 'longo' => [], 'curto' => [], 'series' => 0, 'jogos' => 0];
        }
        $cache[$lg][$k]['longo'][$a] = epNome($r['ca'], $r['na']);
        $cache[$lg][$k]['longo'][$b] = epNome($r['cb'], $r['nb']);
        $cache[$lg][$k]['curto'][$a] = trim((string) $r['na']);
        $cache[$lg][$k]['curto'][$b] = trim((string) $r['nb']);
        $cache[$lg][$k]['series']++;
        $cache[$lg][$k]['jogos'] += (int) $r['jogos'];
        $w = (int) $r['winner_team_id'];
        if (isset($cache[$lg][$k]['vit'][$w])) $cache[$lg][$k]['vit'][$w]++;
    }
    return $cache;
}

/** Linha de dupla no formato da página. */
function epLinhaDupla(array $p, int $x, int $y, string $sep): array
{
    return [
        'a' => $p['curto'][$x], 'b' => $p['curto'][$y],
        'a_long' => $p['longo'][$x], 'b_long' => $p['longo'][$y],
        'name' => $p['longo'][$x] . $sep . $p['longo'][$y],
        'count' => $p['series'], 'jogos' => $p['jogos'],
    ];
}

/**
 * Maiores Rivalidades: duplas que mais se enfrentaram no playoff. No empate de
 * séries, a que teve mais jogos (uma série de sete é mais rivalidade que 4-0).
 */
function epRivalidades(PDO $pdo): array
{
    $map = [];
    foreach (epPares($pdo) as $lg => $pares) {
        $linhas = [];
        foreach ($pares as $p) {
            [$x, $y] = array_keys($p['vit']);
            $linhas[] = epLinhaDupla($p, $x, $y, ' × ');
        }
        usort($linhas, fn($l, $m) => ($m['count'] <=> $l['count']) ?: ($m['jogos'] <=> $l['jogos']) ?: strcasecmp($l['name'], $m['name']));
        if ($linhas) $map[$lg] = $linhas;
    }
    return $map;
}

/**
 * Domínio Total: duplas em que um time venceu TODAS as séries contra o outro.
 * Mais séries vencidas primeiro; no empate, quem precisou de menos jogos.
 */
function epDominio(PDO $pdo): array
{
    $map = [];
    foreach (epPares($pdo) as $lg => $pares) {
        $linhas = [];
        foreach ($pares as $p) {
            [$x, $y] = array_keys($p['vit']);
            $dono = $p['vit'][$x] >= $p['vit'][$y] ? $x : $y;
            $outro = $dono === $x ? $y : $x;
            // Venceu todas — e todas com vencedor registrado.
            if ($p['vit'][$outro] > 0 || $p['vit'][$dono] !== $p['series']) continue;
            $linhas[] = epLinhaDupla($p, $dono, $outro, ' sobre ');
        }
        usort($linhas, fn($l, $m) => ($m['count'] <=> $l['count']) ?: ($l['jogos'] <=> $m['jogos']) ?: strcasecmp($l['name'], $m['name']));
        if ($linhas) $map[$lg] = $linhas;
    }
    return $map;
}

/** Todos os times de cada liga: [liga => [team_id => nome]]. */
function epTimes(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach ($pdo->query("SELECT id, league, city, name FROM teams")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $cache[$t['league']][(int) $t['id']] = epNome($t['city'], $t['name']);
    }
    return $cache;
}

/**
 * Quem foi ao playoff em cada temporada fechada da sprint: tem linha em
 * playoff_results naquela temporada, em qualquer fase. Só entram temporadas
 * com resultado registrado; a temporada em andamento não conta como "fora".
 *
 * Antes a régua era "3 pontos ou mais na temporada", que deixava de fora o 7º
 * e o 8º que caíam na 1ª rodada (2 pontos) e contava quem só pontuou bem na
 * temporada regular.
 *
 * @return array [liga => [season_number => [team_id => true]]]
 */
function epClassificados(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $st = $pdo->query("
        SELECT s.league, s.season_number, pr.team_id
        FROM playoff_results pr
        JOIN seasons s ON s.id = pr.season_id
        WHERE pr.season_id IN " . epSprintAtiva() . "
        ORDER BY s.league, s.season_number");
    $cache = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cache[$r['league']][(int) $r['season_number']][(int) $r['team_id']] = true;
    }
    foreach ($cache as $lg => $temps) {
        ksort($temps);
        $cache[$lg] = $temps;
    }
    return $cache;
}

/** Aparições no Playoff: em quantas temporadas fechadas da sprint o time foi ao playoff. */
function epAparicoes(PDO $pdo): array
{
    $map = [];
    $times = epTimes($pdo);
    foreach (epClassificados($pdo) as $lg => $temps) {
        $linhas = [];
        foreach ($times[$lg] ?? [] as $tid => $nome) {
            $n = 0;
            foreach ($temps as $foram) {
                if (isset($foram[$tid])) $n++;
            }
            $linhas[] = ['team_id' => $tid, 'name' => $nome, 'count' => $n];
        }
        if ($linhas) $map[$lg] = epOrdena($linhas);
    }
    return $map;
}

/**
 * Maior sequência de idas ao playoff e maior jejum, temporada a temporada nas
 * temporadas fechadas da sprint. Um buraco na numeração quebra as duas contas.
 *
 * @return array ['sequencia' => mapa, 'jejum' => mapa]
 */
function epSequencias(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = ['sequencia' => [], 'jejum' => []];
    $times = epTimes($pdo);
    foreach (epClassificados($pdo) as $lg => $temps) {
        $seq = [];
        $jej = [];
        foreach ($times[$lg] ?? [] as $tid => $nome) {
            $maxS = $maxJ = $curS = $curJ = 0;
            $ultima = null;
            foreach ($temps as $num => $foram) {
                if ($ultima !== null && $num !== $ultima + 1) { $curS = 0; $curJ = 0; }
                $ultima = $num;
                if (isset($foram[$tid])) { $curS++; $curJ = 0; } else { $curJ++; $curS = 0; }
                $maxS = max($maxS, $curS);
                $maxJ = max($maxJ, $curJ);
            }
            $seq[] = ['team_id' => $tid, 'name' => $nome, 'count' => $maxS];
            $jej[] = ['team_id' => $tid, 'name' => $nome, 'count' => $maxJ];
        }
        if ($seq) {
            $cache['sequencia'][$lg] = epOrdena($seq);
            $cache['jejum'][$lg] = epOrdena($jej);
        }
    }
    return $cache;
}

function epSequenciaPlayoff(PDO $pdo): array { return epSequencias($pdo)['sequencia']; }
function epJejumPlayoff(PDO $pdo): array { return epSequencias($pdo)['jejum']; }
