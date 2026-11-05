<?php
/**
 * ESTUDOS DE JOGADORES — a base de dados do Marcos (marcos-estudos.php).
 *
 * Tudo o que o banco guarda sobre cada jogador que já passou pela FBA, numa
 * forma que dá pra comparar: carreira temporada a temporada, pico, fim, estatística,
 * letras, prêmios e tendências — e o ESTUDO POR NOME, que junta todas as "vidas" de
 * um mesmo jogador real (os "Kirilenkos") numa ficha só.
 *
 * ── O QUE É UMA "VIDA" ───────────────────────────────────────────────
 *
 * A FBA refaz as classes a cada sprint, então o mesmo jogador real aparece
 * várias vezes, cada uma com um `player_id` próprio: o Kirilenko tem 5 vidas e
 * 39 temporadas no banco. Cada vida é uma carreira à parte; o estudo por nome
 * é a média delas. A fonte é `player_season_log` (uma foto do jogador por
 * temporada: OVR, idade, posição e letras) mais `player_season_stats`
 * (estatística por jogo) e `season_awards` (prêmios, ligados por nome+temporada).
 *
 * ── PICO SUSTENTADO (por que existe) ─────────────────────────────────
 *
 * Pedido do Marcos: "ignore aqueles 99 que teve só uma temporada". O pico
 * absoluto (`pico`) é o maior OVR que o jogador teve; o PICO SUSTENTADO
 * (`pico_sust`) é o maior OVR que ele SEGUROU por duas temporadas seguidas —
 * o máximo, entre todos os pares de temporadas vizinhas, do MENOR OVR do par. Um
 * 99 isolado entre dois 85 dá pico 99 e pico sustentado 85. Vida de uma
 * temporada só tem pico sustentado igual ao OVR dela, e é marcada como tal.
 *
 * ── IDADE NÃO É RÉGUA ────────────────────────────────────────────────
 *
 * A idade às vezes repete entre temporadas (29, 29, 31) porque é lançada na
 * mão. Por isso a ordem é SEMPRE a da temporada, e a idade só rotula.
 *
 * SÓ LEITURA. Nada aqui grava no banco.
 */

/** Temporada com menos jogos que isto não conta pra "pico de estatística". */
const ESTUDOS_MIN_JOGOS = 20;
/** No estudo por nome, vida com menos temporadas que isto fica fora das médias. */
const ESTUDOS_MIN_TEMP_COORTE = 3;
/** Pico absoluto este tanto acima do sustentado = "pico de uma temporada". */
const ESTUDOS_FLUKE_DIF = 3;

/** As letras como número, pra tirar média (F = 0 ... A+ = 12). */
const ESTUDOS_NOTAS = ['F' => 0, 'D-' => 1, 'D' => 2, 'D+' => 3, 'C-' => 4, 'C' => 5, 'C+' => 6,
                       'B-' => 7, 'B' => 8, 'B+' => 9, 'A-' => 10, 'A' => 11, 'A+' => 12];

/** As dez letras: coluna do banco => sigla do jogo, na ordem da tela do jogo. */
const ESTUDOS_SKILLS = ['skill_in' => 'IN', 'skill_mid' => 'MID', 'skill_3pt' => '3PT',
                        'skill_post_d' => 'POST D', 'skill_per_d' => 'PER D', 'skill_play' => 'PLAY',
                        'skill_reb' => 'REB', 'skill_athl' => 'ATHL', 'skill_iq' => 'IQ', 'skill_pot' => 'POT'];

/** As estatísticas por jogo que o banco guarda. */
const ESTUDOS_STATS = ['pts_pg' => 'PTS', 'reb_pg' => 'REB', 'ast_pg' => 'AST', 'stl_pg' => 'STL', 'blk_pg' => 'BLK'];

function estudosNotaNum(?string $letra): ?int
{
    $l = strtoupper(trim((string)$letra));
    return $l !== '' && isset(ESTUDOS_NOTAS[$l]) ? ESTUDOS_NOTAS[$l] : null;
}

function estudosNumNota(?float $n): ?string
{
    if ($n === null) return null;
    $i = max(0, min(12, (int)round($n)));
    return array_search($i, ESTUDOS_NOTAS, true) ?: 'F';
}

function estudosMedia(array $xs): ?float
{
    $xs = array_values(array_filter($xs, fn($x) => $x !== null));
    return $xs ? array_sum($xs) / count($xs) : null;
}

function estudosMediana(array $xs): ?float
{
    $xs = array_values(array_filter($xs, fn($x) => $x !== null));
    if (!$xs) return null;
    sort($xs);
    $m = intdiv(count($xs), 2);
    return count($xs) % 2 ? (float)$xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2;
}

function estudosArred(?float $x, int $casas = 1): ?float
{
    return $x === null ? null : round($x, $casas);
}

/** Média, mediana, mínimo, máximo e quantos valores entraram (vazios ficam de fora). */
function estudosResumo(array $xs): array
{
    $v = array_values(array_filter($xs, fn($x) => $x !== null));
    return ['media' => estudosArred(estudosMedia($v)), 'mediana' => estudosArred(estudosMediana($v)),
            'min' => $v ? min($v) : null, 'max' => $v ? max($v) : null, 'n' => count($v)];
}

/**
 * As métricas de UMA vida.
 *
 * @param array<int,array> $temps  linhas do player_season_log da vida, EM ORDEM de temporada
 * @param array<int,array> $stats  estatísticas indexadas por season_id
 * @param array<int,array> $premios prêmios indexados por season_id => [tipo, ...]
 */
function estudosMetricas(array $temps, array $stats = [], array $premios = []): array
{
    $n = count($temps);
    if ($n === 0) return ['n' => 0];

    $ovrs = array_map(fn($t) => (int)$t['ovr'], $temps);
    $idades = array_map(fn($t) => (int)$t['age'], $temps);

    $pico = max($ovrs);
    $iPico = array_search($pico, $ovrs, true);

    // Pico sustentado: o maior OVR segurado por duas temporadas seguidas.
    if ($n === 1) {
        $picoSust = $ovrs[0]; $iSust = 0;
    } else {
        $picoSust = -1; $iSust = 0;
        for ($i = 0; $i < $n - 1; $i++) {
            $m = min($ovrs[$i], $ovrs[$i + 1]);
            if ($m > $picoSust) { $picoSust = $m; $iSust = $i; }
        }
    }

    $m = [
        'n'             => $n,
        'temporada_ini' => (int)$temps[0]['season_number'],
        'temporada_fim' => (int)$temps[$n - 1]['season_number'],
        'ano_ini'       => (int)$temps[0]['year'],
        'ano_fim'       => (int)$temps[$n - 1]['year'],
        'idade_ini'     => $idades[0],
        'idade_fim'     => $idades[$n - 1],
        'ovr_ini'       => $ovrs[0],
        'ovr_fim'       => $ovrs[$n - 1],
        'pico'          => $pico,
        'idade_pico'    => $idades[$iPico],
        'pico_sust'     => $picoSust,
        'idade_pico_sust' => $idades[$iSust],
        // Pico de uma temporada: o absoluto está bem acima do que ele segurou,
        // ou é uma vida de uma temporada só com OVR de estrela.
        'fluke'         => ($pico - $picoSust >= ESTUDOS_FLUKE_DIF) || ($n === 1 && $pico >= 95),
        'uma_temporada' => $n === 1,
        'ovr_medio'     => estudosArred(array_sum($ovrs) / $n),
        'anos_85'       => count(array_filter($ovrs, fn($o) => $o >= 85)),
        'anos_90'       => count(array_filter($ovrs, fn($o) => $o >= 90)),
        'queda'         => $picoSust - $ovrs[$n - 1],   // do pico sustentado até o fim
        'posicoes'      => array_values(array_unique(array_map(fn($t) => (string)$t['position'], $temps))),
        'times'         => count(array_unique(array_map(fn($t) => (string)$t['team_name'], $temps))),
    ];

    // As letras na temporada do pico sustentado.
    $m['letras_pico'] = [];
    foreach (ESTUDOS_SKILLS as $col => $sigla) {
        $v = trim((string)($temps[$iSust][$col] ?? ''));
        $m['letras_pico'][$sigla] = $v !== '' ? $v : null;
    }

    // Estatísticas: média ponderada por jogos na carreira, e o melhor ano
    // (só com jogos suficientes, senão uma temporada de 3 jogos vira "pico").
    $jogos = 0; $soma = array_fill_keys(array_keys(ESTUDOS_STATS), 0.0);
    $fgJogos = 0; $fgSoma = 0.0; $minSoma = 0.0;
    $picos = array_fill_keys(array_keys(ESTUDOS_STATS), null);
    $picoFg = null; $temporadasComStats = 0;
    foreach ($temps as $t) {
        $s = $stats[(int)$t['season_id']] ?? null;
        if (!$s || (int)$s['games'] <= 0) continue;
        $g = (int)$s['games'];
        $temporadasComStats++;
        $jogos += $g;
        $minSoma += (float)$s['min_pg'] * $g;
        foreach (ESTUDOS_STATS as $col => $_) {
            $soma[$col] += (float)$s[$col] * $g;
            if ($g >= ESTUDOS_MIN_JOGOS && ($picos[$col] === null || (float)$s[$col] > $picos[$col])) $picos[$col] = (float)$s[$col];
        }
        if ($s['fg_pct'] !== null && $s['fg_pct'] !== '') {
            $fgJogos += $g; $fgSoma += (float)$s['fg_pct'] * $g;
            if ($g >= ESTUDOS_MIN_JOGOS && ($picoFg === null || (float)$s['fg_pct'] > $picoFg)) $picoFg = (float)$s['fg_pct'];
        }
    }
    $m['jogos'] = $jogos;
    $m['temporadas_com_stats'] = $temporadasComStats;
    $m['min'] = $jogos ? estudosArred($minSoma / $jogos) : null;
    foreach (ESTUDOS_STATS as $col => $_) {
        $m[$col] = $jogos ? estudosArred($soma[$col] / $jogos) : null;
        $m['pico_' . $col] = estudosArred($picos[$col]);
    }
    $m['fg'] = $fgJogos ? estudosArred($fgSoma / $fgJogos) : null;
    $m['pico_fg'] = estudosArred($picoFg);

    // Prêmios nas temporadas desta vida.
    $pr = [];
    foreach ($temps as $t) {
        foreach ($premios[(int)$t['season_id']] ?? [] as $tipo) $pr[$tipo] = ($pr[$tipo] ?? 0) + 1;
    }
    $m['premios'] = $pr;
    $m['premios_total'] = array_sum($pr);
    return $m;
}

/**
 * Só as métricas que a TABELA mostra. A lista do histórico tem quase 7 mil
 * linhas, e mandar junto as letras, os prêmios por tipo e a lista de posições de
 * cada uma triplicava o tamanho da resposta. O detalhe de uma vida traz tudo.
 */
function estudosCompacta(array $m): array
{
    if (($m['n'] ?? 0) === 0) return ['n' => 0];
    static $chaves = ['n', 'temporada_ini', 'temporada_fim', 'idade_ini', 'ovr_ini', 'ovr_fim', 'idade_fim', 'pico', 'idade_pico',
        'pico_sust', 'idade_pico_sust', 'fluke', 'uma_temporada', 'anos_85', 'anos_90', 'queda', 'times', 'jogos', 'temporadas_com_stats',
        'min', 'pts_pg', 'reb_pg', 'ast_pg', 'stl_pg', 'blk_pg', 'fg', 'pico_pts_pg', 'pico_reb_pg', 'pico_ast_pg', 'pico_stl_pg',
        'pico_blk_pg', 'pico_fg', 'premios_total'];
    return array_intersect_key($m, array_flip($chaves));
}

/** A curva OVR por idade de uma vida (idade repetida: média do que ela teve). */
function estudosCurva(array $temps): array
{
    $por = [];
    foreach ($temps as $t) $por[(int)$t['age']][] = (int)$t['ovr'];
    ksort($por);
    $c = [];
    foreach ($por as $idade => $os) $c[$idade] = round(array_sum($os) / count($os), 1);
    return $c;
}

/**
 * Carrega o histórico do banco, já agrupado por vida.
 *
 * @param ?array<int> $ids  só estas vidas (null = todas)
 * @param ?string $nome     só as vidas com este nome (sem diferenciar maiúscula)
 * @return array{log:array<int,array>,stats:array<int,array<int,array>>,premios:array<string,array>}
 */
function estudosCarregar(PDO $pdo, ?array $ids = null, ?string $nome = null, bool $comLetras = true): array
{
    // As letras são dez colunas de texto por temporada: nas listas grandes (o
    // histórico tem 36 mil linhas) elas só pesam na memória, e a tabela não usa.
    $colLetras = $comLetras
        ? ', skill_in, skill_mid, skill_3pt, skill_post_d, skill_per_d, skill_play, skill_reb, skill_athl, skill_iq, skill_pot'
        : '';
    $where = ''; $par = [];
    if ($nome !== null) { $where = 'WHERE player_name = ?'; $par = [$nome]; }
    elseif ($ids !== null) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return ['log' => [], 'stats' => [], 'premios' => []];
        $where = 'WHERE player_id IN (' . implode(',', $ids) . ')';
    }
    $st = $pdo->prepare("SELECT player_id, player_name, league, sprint_number, season_id, season_number, year,
                                team_name, ovr, age, position{$colLetras}
                           FROM player_season_log {$where}
                       ORDER BY player_id, year, season_number");
    $st->execute($par);
    $log = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $log[(int)$r['player_id']][] = $r;

    $idsVidas = array_keys($log);
    $stats = [];
    if ($idsVidas) {
        $in = ($ids === null && $nome === null) ? '' : 'WHERE player_id IN (' . implode(',', $idsVidas) . ')';
        $s2 = $pdo->query("SELECT player_id, season_id, games, min_pg, pts_pg, reb_pg, ast_pg, stl_pg, blk_pg, fg_pct
                             FROM player_season_stats {$in} ORDER BY id");
        foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r) $stats[(int)$r['player_id']][(int)$r['season_id']] = $r;   // a última linha vale
    }

    // Prêmios são por NOME + temporada (a tabela guarda o nome, não o id).
    $premios = [];
    foreach ($pdo->query("SELECT season_id, player_name, award_type FROM season_awards")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $premios[mb_strtolower(trim($r['player_name'])) . '|' . (int)$r['season_id']][] = $r['award_type'];
    }
    return ['log' => $log, 'stats' => $stats, 'premios' => $premios];
}

/** Os prêmios de uma vida, indexados por temporada. */
function estudosPremiosDaVida(array $temps, array $premiosMapa): array
{
    $out = [];
    foreach ($temps as $t) {
        $k = mb_strtolower(trim((string)$t['player_name'])) . '|' . (int)$t['season_id'];
        if (!empty($premiosMapa[$k])) $out[(int)$t['season_id']] = $premiosMapa[$k];
    }
    return $out;
}

/** Quantas vidas cada nome tem (todas, em todo o histórico). */
function estudosVidasPorNome(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query("SELECT player_name, COUNT(DISTINCT player_id) v FROM player_season_log GROUP BY player_name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[mb_strtolower(trim($r['player_name']))] = (int)$r['v'];
    }
    return $out;
}

/**
 * A lista de uma aba.
 *   elite     jogadores que estão hoje em times da ELITE
 *   outras    jogadores de NEXT, RISE e ROOKIE
 *   historico todo mundo: quem está nos elencos e quem já saiu
 *
 * Uma linha por jogador (vida), com a situação de HOJE (time, idade, OVR) quando
 * ele ainda está num elenco, e as métricas da carreira inteira.
 */
function estudosLista(PDO $pdo, string $grupo): array
{
    $atuais = [];   // player_id => situação de hoje
    $sqlAtual = "SELECT p.id, p.name, p.position, p.age, p.ovr, p.height, p.is_lenda, p.seasons_in_league,
                        p.skill_in, p.skill_mid, p.skill_3pt, p.skill_post_d, p.skill_per_d, p.skill_play,
                        p.skill_reb, p.skill_athl, p.skill_iq, p.skill_pot,
                        t.league AS liga, TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS time
                   FROM players p JOIN teams t ON t.id = p.team_id";
    if ($grupo === 'elite')       $sqlAtual .= " WHERE t.league = 'ELITE'";
    elseif ($grupo === 'outras')  $sqlAtual .= " WHERE t.league IN ('NEXT','RISE','ROOKIE')";
    foreach ($pdo->query($sqlAtual)->fetchAll(PDO::FETCH_ASSOC) as $p) $atuais[(int)$p['id']] = $p;

    $ids = $grupo === 'historico' ? null : array_keys($atuais);
    $c = estudosCarregar($pdo, $ids, null, false);   // lista: sem as letras
    $vidasNome = estudosVidasPorNome($pdo);

    $tend = [];
    try {
        foreach ($pdo->query("SELECT player_id FROM player_tendencies")->fetchAll(PDO::FETCH_COLUMN) as $pid) $tend[(int)$pid] = true;
    } catch (Throwable $e) { /* sem a tabela: sem tendências */ }

    // No histórico entram os do log e também quem está nos elencos sem log ainda.
    $todos = $grupo === 'historico' ? array_unique(array_merge(array_keys($c['log']), array_keys($atuais))) : array_keys($atuais);

    $linhas = [];
    foreach ($todos as $pid) {
        $temps = $c['log'][$pid] ?? [];
        $m = estudosMetricas($temps, $c['stats'][$pid] ?? [], estudosPremiosDaVida($temps, $c['premios']));
        $hoje = $atuais[$pid] ?? null;
        $ultima = $temps ? $temps[count($temps) - 1] : null;
        $nome = $hoje['name'] ?? ($ultima['player_name'] ?? '?');
        $linhas[] = [
            'id'    => $pid,
            'nome'  => $nome,
            'ativo' => $hoje !== null,
            'liga'  => $hoje['liga'] ?? ($ultima['league'] ?? null),
            'time'  => $hoje['time'] ?? ($ultima['team_name'] ?? null),
            'pos'   => $hoje['position'] ?? ($ultima['position'] ?? null),
            'idade' => isset($hoje['age']) ? (int)$hoje['age'] : ($ultima ? (int)$ultima['age'] : null),
            'ovr'   => isset($hoje['ovr']) ? (int)$hoje['ovr'] : ($ultima ? (int)$ultima['ovr'] : null),
            'lenda' => !empty($hoje['is_lenda']),
            'vidas' => $vidasNome[mb_strtolower(trim($nome))] ?? 1,
            'tend'  => isset($tend[$pid]),
            'm'     => estudosCompacta($m),
        ];
    }
    usort($linhas, fn($a, $b) => ($b['ovr'] ?? 0) <=> ($a['ovr'] ?? 0) ?: strcmp($a['nome'], $b['nome']));
    return $linhas;
}

/** A carreira completa de uma vida: temporada a temporada. */
function estudosVida(PDO $pdo, int $id): ?array
{
    $c = estudosCarregar($pdo, [$id]);
    $temps = $c['log'][$id] ?? [];

    $st = $pdo->prepare("SELECT p.*, t.league AS liga, TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS time
                           FROM players p LEFT JOIN teams t ON t.id = p.team_id WHERE p.id = ?");
    $st->execute([$id]);
    $hoje = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$temps && !$hoje) return null;

    $nome = $hoje['name'] ?? $temps[0]['player_name'];
    $prem = estudosPremiosDaVida($temps, $c['premios']);
    $m = estudosMetricas($temps, $c['stats'][$id] ?? [], $prem);

    $seasons = [];
    foreach ($temps as $t) {
        $sid = (int)$t['season_id'];
        $s = $c['stats'][$id][$sid] ?? null;
        $letras = [];
        foreach (ESTUDOS_SKILLS as $col => $sigla) { $v = trim((string)$t[$col]); $letras[$sigla] = $v !== '' ? $v : null; }
        $seasons[] = [
            'temporada' => (int)$t['season_number'], 'ano' => (int)$t['year'], 'liga' => $t['league'],
            'time' => $t['team_name'], 'idade' => (int)$t['age'], 'ovr' => (int)$t['ovr'], 'pos' => $t['position'],
            'letras' => $letras,
            'stats' => $s ? [
                'jogos' => (int)$s['games'], 'min' => (float)$s['min_pg'],
                'pts' => (float)$s['pts_pg'], 'reb' => (float)$s['reb_pg'], 'ast' => (float)$s['ast_pg'],
                'stl' => (float)$s['stl_pg'], 'blk' => (float)$s['blk_pg'],
                'fg' => ($s['fg_pct'] === null || $s['fg_pct'] === '') ? null : (float)$s['fg_pct'],
            ] : null,
            'premios' => $prem[$sid] ?? [],
        ];
    }

    $tendencias = null;
    try {
        $q = $pdo->prepare("SELECT dados, atualizado_em FROM player_tendencies WHERE player_id = ?");
        $q->execute([$id]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) $tendencias = ['dados' => json_decode((string)$r['dados'], true) ?: [], 'em' => $r['atualizado_em']];
    } catch (Throwable $e) {}

    $outras = [];
    $q = $pdo->prepare("SELECT player_id, league, MIN(season_number) de, MAX(season_number) ate, COUNT(*) n, MAX(ovr) pico
                          FROM player_season_log WHERE player_name = ? AND player_id <> ? GROUP BY player_id, league ORDER BY player_id");
    $q->execute([$nome, $id]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $outras[] = ['id' => (int)$r['player_id'], 'liga' => $r['league'], 'n' => (int)$r['n'], 'pico' => (int)$r['pico']];

    return [
        'id' => $id, 'nome' => $nome,
        'hoje' => $hoje ? ['time' => $hoje['time'], 'liga' => $hoje['liga'], 'pos' => $hoje['position'], 'idade' => (int)$hoje['age'],
                           'ovr' => (int)$hoje['ovr'], 'altura' => $hoje['height'], 'lenda' => !empty($hoje['is_lenda'])] : null,
        'm' => $m, 'temporadas' => $seasons, 'curva' => estudosCurva($temps),
        'tendencias' => $tendencias, 'outras_vidas' => $outras,
    ];
}

/** Os nomes com mais de uma vida — a lista do "estudo por nome". */
function estudosNomes(PDO $pdo, int $minVidas = 2): array
{
    $st = $pdo->prepare("SELECT player_name nome, COUNT(DISTINCT player_id) vidas, COUNT(*) temporadas, MAX(ovr) pico
                           FROM player_season_log GROUP BY player_name HAVING vidas >= ?
                       ORDER BY vidas DESC, temporadas DESC, nome");
    $st->execute([$minVidas]);
    return array_map(fn($r) => ['nome' => $r['nome'], 'vidas' => (int)$r['vidas'], 'temporadas' => (int)$r['temporadas'], 'pico' => (int)$r['pico']],
                     $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * O ESTUDO POR NOME: todas as vidas de um jogador real, e a média delas.
 *
 * As médias só usam vidas com pelo menos $minTemp temporadas (padrão 3): vida
 * de uma temporada só é, quase sempre, uma foto isolada e não uma carreira, e
 * um 99 desses puxaria o "pico médio" pra um número que ninguém sustentou. As
 * vidas curtas continuam na lista, marcadas, só ficam fora da conta.
 */
function estudosNome(PDO $pdo, string $nome, int $minTemp = ESTUDOS_MIN_TEMP_COORTE): array
{
    // O nome exato como está no banco (a busca não diferencia maiúscula).
    $q = $pdo->prepare("SELECT player_name FROM player_season_log WHERE LOWER(TRIM(player_name)) = LOWER(TRIM(?)) LIMIT 1");
    $q->execute([$nome]);
    $exato = $q->fetchColumn();
    if ($exato === false) return ['ok' => false, 'erro' => 'Nenhum jogador com esse nome no histórico.'];

    $c = estudosCarregar($pdo, null, (string)$exato);
    $vidas = [];
    foreach ($c['log'] as $pid => $temps) {
        $m = estudosMetricas($temps, $c['stats'][$pid] ?? [], estudosPremiosDaVida($temps, $c['premios']));
        $vidas[] = ['id' => $pid, 'liga' => $temps[0]['league'], 'm' => $m, 'curva' => estudosCurva($temps),
                    'usada' => $m['n'] >= $minTemp];
    }
    $usadas = array_values(array_filter($vidas, fn($v) => $v['usada']));
    $col = fn(string $k) => array_map(fn($v) => $v['m'][$k] ?? null, $usadas);
    $res = 'estudosResumo';

    $resumo = [];
    foreach (['n' => 'temporadas', 'pico' => 'pico', 'pico_sust' => 'pico_sustentado', 'idade_pico_sust' => 'idade_do_pico',
              'ovr_fim' => 'ovr_no_fim', 'idade_fim' => 'idade_no_fim', 'queda' => 'queda_pico_ao_fim',
              'anos_85' => 'anos_85_ou_mais', 'anos_90' => 'anos_90_ou_mais', 'ovr_ini' => 'ovr_inicial', 'idade_ini' => 'idade_inicial',
              'min' => 'minutos', 'pts_pg' => 'pts', 'reb_pg' => 'reb', 'ast_pg' => 'ast', 'stl_pg' => 'stl', 'blk_pg' => 'blk', 'fg' => 'fg',
              'pico_pts_pg' => 'pico_pts', 'pico_reb_pg' => 'pico_reb', 'pico_ast_pg' => 'pico_ast',
              'pico_stl_pg' => 'pico_stl', 'pico_blk_pg' => 'pico_blk', 'pico_fg' => 'pico_fg'] as $k => $rotulo) {
        $resumo[$rotulo] = $res($col($k));
    }

    // Letras no pico: média de cada uma, na escala numérica, de volta pra letra.
    $letras = [];
    foreach (ESTUDOS_SKILLS as $sigla) {
        $nums = array_map(fn($v) => estudosNotaNum($v['m']['letras_pico'][$sigla] ?? null), $usadas);
        $media = estudosMedia($nums);
        $letras[$sigla] = ['letra' => estudosNumNota($media), 'media' => estudosArred($media, 2)];
    }

    // A curva média: OVR por idade, com quantas vidas entraram em cada ponto.
    $porIdade = [];
    foreach ($usadas as $v) foreach ($v['curva'] as $idade => $ovr) $porIdade[$idade][] = $ovr;
    ksort($porIdade);
    $curva = [];
    foreach ($porIdade as $idade => $os) $curva[] = ['idade' => (int)$idade, 'ovr' => round(array_sum($os) / count($os), 1), 'vidas' => count($os)];

    // Quanto o OVR muda de um ano pro seguinte, na mesma vida (só com idade consecutiva).
    $deltas = [];
    foreach ($usadas as $v) {
        $ids = array_keys($v['curva']);
        foreach ($ids as $i) if (isset($v['curva'][$i + 1])) $deltas[$i][] = $v['curva'][$i + 1] - $v['curva'][$i];
    }
    ksort($deltas);
    $variacao = [];
    foreach ($deltas as $idade => $ds) $variacao[] = ['de' => (int)$idade, 'para' => (int)$idade + 1, 'media' => round(array_sum($ds) / count($ds), 2), 'vidas' => count($ds)];

    return ['ok' => true, 'nome' => (string)$exato, 'min_temporadas' => $minTemp,
            'vidas' => $vidas, 'vidas_total' => count($vidas), 'vidas_usadas' => count($usadas),
            'resumo' => $resumo, 'letras_pico' => $letras, 'curva_media' => $curva, 'variacao_anual' => $variacao];
}
