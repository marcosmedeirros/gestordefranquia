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
 * Estas funções só LEEM o histórico do jogo. Favoritos, correções e lixeira vivem na
 * camada do estudos_ajustes.php, que é aplicada POR CIMA na hora de carregar —
 * nada daqui altera `player_season_log` nem `player_season_stats`.
 */

require_once __DIR__ . '/estudos_ajustes.php';

/** Temporada com menos jogos que isto não conta pra "pico de estatística". */
const ESTUDOS_MIN_JOGOS = 20;
/** No estudo por nome, vida com menos temporadas que isto fica fora das médias. */
const ESTUDOS_MIN_TEMP_COORTE = 3;
/** Pico absoluto este tanto acima do sustentado = "pico de uma temporada". */
const ESTUDOS_FLUKE_DIF = 3;
/**
 * O OVR do "99 festivo": vida de UMA temporada só, e com este OVR.
 *
 * Pedido do Marcos (05/11/2026): "todos os jogadores que tiveram apenas uma
 * temporada 99 e não tiveram mais, tire das métricas essa temporada, porque ela
 * foi festiva". São 11 vidas no banco e todas caem na mesma temporada — a T20 de
 * 2044, a última do sprint 1. Elas continuam na base (e a carreira abre normal),
 * mas NÃO entram em nenhuma métrica: nem nas colunas da lista, nem nas médias do
 * estudo por nome.
 */
const ESTUDOS_OVR_FESTIVO = 99;

/** As letras como número, pra tirar média (F = 0 ... A+ = 12). */
const ESTUDOS_NOTAS = ['F' => 0, 'D-' => 1, 'D' => 2, 'D+' => 3, 'C-' => 4, 'C' => 5, 'C+' => 6,
                       'B-' => 7, 'B' => 8, 'B+' => 9, 'A-' => 10, 'A' => 11, 'A+' => 12];

/** As dez letras: coluna do banco => sigla do jogo, na ordem da tela do jogo. */
const ESTUDOS_SKILLS = ['skill_in' => 'IN', 'skill_mid' => 'MID', 'skill_3pt' => '3PT',
                        'skill_post_d' => 'POST D', 'skill_per_d' => 'PER D', 'skill_play' => 'PLAY',
                        'skill_reb' => 'REB', 'skill_athl' => 'ATHL', 'skill_iq' => 'IQ', 'skill_pot' => 'POT'];

/**
 * ── PEAK START E PEAK END (as definições do Marcos, 05/11/2026) ──────
 *
 *   PEAK START  a idade em que ele chega ao patamar de pico: a primeira temporada
 *               com OVR dentro de ESTUDOS_PLATO do pico sustentado.
 *   PEAK END    a idade em que ele COMEÇA A CAIR — e não o fim da carreira (ex.: 32, 38).
 *               É a primeira temporada depois do pico em que o OVR cai abaixo do pico
 *               sustentado; opcionalmente também a soma das letras dos atributos
 *               (ver ESTUDOS_OPC_PADRAO). Quem ainda não caiu fica sem peak end.
 *   ANOS DE PICO  quantas temporadas entre um e outro.
 *   ANOS ATÉ O PICO  quantas temporadas entre a entrada na liga e o peak start.
 *
 * A "idade de entrada" é a da primeira temporada registrada: pra quem chegou como
 * calouro é a idade de rookie, mas quem já estava no elenco quando o sprint abriu
 * entra com a idade que tinha na época.
 */
const ESTUDOS_PLATO = 1;
const ESTUDOS_ATRIB_QUEDA = 2;

/**
 * As opções da definição de peak, ajustáveis na página (05/11/2026: "o peak end deve
 * ser a idade que começou a cair"):
 *
 *   plato  quantos pontos abaixo do pico sustentado ainda contam como "no pico" (começo)
 *   queda  quantos pontos ABAIXO do pico sustentado o OVR precisa estar pra contar como queda
 *   atrib  degraus de queda na soma das letras que também contam como queda; 0 = só o OVR
 *   ponto  'queda' = idade da PRIMEIRA temporada em queda · 'ultimo' = idade da última temporada no pico
 *
 * O padrão é só o OVR (atrib 0): o atributo oscila de ano pra ano e puxava o "começou a
 * cair" pra antes da queda de verdade. Ligar `atrib` volta à regra anterior.
 */
const ESTUDOS_OPC_PADRAO = ['plato' => ESTUDOS_PLATO, 'queda' => 1, 'atrib' => 0, 'ponto' => 'queda'];

/** Limpa as opções que vieram da tela (qualquer lixo vira o padrão). */
function estudosOpcoes(array $in = []): array
{
    $o = ESTUDOS_OPC_PADRAO;
    $n = fn($k, $min, $max) => isset($in[$k]) && is_numeric($in[$k]) ? max($min, min($max, (int)$in[$k])) : $o[$k];
    $o['plato'] = $n('plato', 0, 3);
    $o['queda'] = $n('queda', 1, 6);
    $o['atrib'] = $n('atrib', 0, 6);
    $o['ponto'] = (($in['ponto'] ?? '') === 'ultimo') ? 'ultimo' : 'queda';
    return $o;
}

/** Os nove atributos (todas as letras menos o POT, que é teto e não habilidade). */
const ESTUDOS_ATRIBUTOS = ['skill_in', 'skill_mid', 'skill_3pt', 'skill_post_d', 'skill_per_d', 'skill_play', 'skill_reb', 'skill_athl', 'skill_iq'];

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
 * A soma das letras dos nove atributos de uma temporada (F = 0 ... A+ = 12), ou
 * null quando faltam letras demais pra a soma significar alguma coisa (menos de
 * sete dos nove) — as temporadas mais antigas nem sempre têm.
 */
function estudosAtribSoma(array $t): ?int
{
    $soma = 0; $tem = 0;
    foreach (ESTUDOS_ATRIBUTOS as $col) {
        $v = estudosNotaNum($t[$col] ?? null);
        if ($v !== null) { $soma += $v; $tem++; }
    }
    return $tem >= 7 ? $soma : null;
}

/**
 * As três viradas da carreira: onde o pico começa, e onde ele acaba pelo OVR e
 * pelos atributos. Tudo em ÍNDICE de temporada (a idade só rotula, e repete).
 *
 * @param array<int,int>      $ovrs
 * @param array<int,?int>     $attrs soma das letras por temporada (null = sem dado)
 * @return array{inicio:int,fim_ovr:?int,fim_atrib:?int,fim:?int}
 */
function estudosFasePico(array $ovrs, array $attrs, int $picoSust, array $opc = []): array
{
    $opc = $opc + ESTUDOS_OPC_PADRAO;
    $n = count($ovrs);
    $inicio = 0;
    foreach ($ovrs as $i => $o) { if ($o >= $picoSust - $opc['plato']) { $inicio = $i; break; } }

    // Fim pelo OVR: a primeira temporada em queda (OVR `queda` pontos abaixo do pico
    // sustentado) DEPOIS da última vez que ele esteve nele.
    $ultimoNoPico = $inicio;
    foreach ($ovrs as $i => $o) { if ($i >= $inicio && $o >= $picoSust) $ultimoNoPico = $i; }
    $fimOvr = null;
    for ($i = $ultimoNoPico + 1; $i < $n; $i++) { if ($ovrs[$i] <= $picoSust - $opc['queda']) { $fimOvr = $i; break; } }

    // Fim pelos atributos (só se ligado): a soma das letras cai `atrib` degraus abaixo
    // do melhor da fase de pico em diante. Precisa de pelo menos três temporadas com
    // letra, senão é ruído de lançamento manual.
    $fimAtrib = null;
    $com = array_filter($attrs, fn($a, $i) => $a !== null && $i >= $inicio, ARRAY_FILTER_USE_BOTH);
    if ($opc['atrib'] > 0 && count($com) >= 3) {
        $melhor = max($com);
        $iMelhor = $inicio;
        foreach ($com as $i => $a) { if ($a === $melhor) $iMelhor = $i; }   // a última vez no melhor
        for ($i = $iMelhor + 1; $i < $n; $i++) {
            if ($attrs[$i] !== null && $attrs[$i] <= $melhor - $opc['atrib']) { $fimAtrib = $i; break; }
        }
    }

    $cand = array_filter([$fimOvr, $fimAtrib], fn($x) => $x !== null);
    return ['inicio' => $inicio, 'fim_ovr' => $fimOvr, 'fim_atrib' => $fimAtrib, 'fim' => $cand ? min($cand) : null];
}

/** A vida é um "99 festivo"? (uma única temporada, com OVR 99) */
function estudosVidaFestiva(array $temps): bool
{
    return count($temps) === 1 && (int)$temps[0]['ovr'] === ESTUDOS_OVR_FESTIVO;
}

/**
 * As métricas de UMA vida.
 *
 * @param array<int,array> $temps  linhas do player_season_log da vida, EM ORDEM de temporada
 * @param array<int,array> $stats  estatísticas indexadas por season_id
 * @param array<int,array> $premios prêmios indexados por season_id => [tipo, ...]
 */
function estudosMetricas(array $temps, array $stats = [], array $premios = [], array $opc = []): array
{
    $opc = $opc + ESTUDOS_OPC_PADRAO;
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

    // Peak start e peak end (ver ESTUDOS_PLATO lá em cima).
    $attrs = array_map(fn($t) => array_key_exists('attr_soma', $t) ? $t['attr_soma'] : estudosAtribSoma($t), $temps);
    $fase = estudosFasePico($ovrs, $attrs, $picoSust, $opc);
    $iFim = $fase['fim'];
    // 'ultimo' = a idade da última temporada ainda no pico (a de antes da queda).
    $refFim = fn(?int $i) => $i === null ? null : ($opc['ponto'] === 'ultimo' ? max($fase['inicio'], $i - 1) : $i);
    $iFimRef = $refFim($iFim);
    $pot0 = estudosNotaNum($temps[0]['skill_pot'] ?? null);

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
        'festiva'       => estudosVidaFestiva($temps),   // fica FORA de toda métrica (ver ESTUDOS_OVR_FESTIVO)
        'ovr_medio'     => estudosArred(array_sum($ovrs) / $n),
        'anos_85'       => count(array_filter($ovrs, fn($o) => $o >= 85)),
        'anos_90'       => count(array_filter($ovrs, fn($o) => $o >= 90)),
        'queda'         => $picoSust - $ovrs[$n - 1],   // do pico sustentado até o fim
        'peak_start'    => $idades[$fase['inicio']],
        // A idade em que começa a perder OVR e atributos; null = ainda não caiu.
        'peak_end'      => $iFimRef === null ? null : $idades[$iFimRef],
        'peak_end_ovr'  => $fase['fim_ovr'] === null ? null : $idades[$refFim($fase['fim_ovr'])],
        'peak_end_atrib' => $fase['fim_atrib'] === null ? null : $idades[$refFim($fase['fim_atrib'])],
        'anos_pico'     => $iFimRef === null ? null : $iFimRef - $fase['inicio'],
        'anos_ate_pico' => $fase['inicio'],
        'ainda_no_pico' => $iFim === null,
        'pot_ini'       => $pot0 === null ? null : estudosNumNota((float)$pot0),   // o POT na entrada, em letra
        'pot_ini_num'   => $pot0,
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
    // O 99 festivo não mostra métrica nenhuma: a linha existe, mas vazia.
    if (!empty($m['festiva'])) return ['n' => 0, 'festiva' => true];
    static $chaves = ['n', 'temporada_ini', 'temporada_fim', 'idade_ini', 'ovr_ini', 'ovr_fim', 'idade_fim', 'pico', 'idade_pico',
        'pico_sust', 'idade_pico_sust', 'fluke', 'uma_temporada', 'anos_85', 'anos_90', 'queda', 'times', 'jogos', 'temporadas_com_stats',
        'min', 'pts_pg', 'reb_pg', 'ast_pg', 'stl_pg', 'blk_pg', 'fg', 'pico_pts_pg', 'pico_reb_pg', 'pico_ast_pg', 'pico_stl_pg',
        'pico_blk_pg', 'pico_fg', 'premios_total', 'peak_start', 'peak_end', 'anos_pico', 'anos_ate_pico', 'ainda_no_pico', 'pot_ini'];
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
 * @param string $letras    'completo' guarda as dez letras de cada temporada; 'resumo' guarda só a
 *                          soma dos atributos e o POT — as listas grandes (36 mil linhas) não
 *                          cabem na memória com dez strings por linha, e a tabela só precisa disso.
 * @return array{log:array<int,array>,stats:array<int,array<int,array>>,premios:array<string,array>}
 */
function estudosCarregar(PDO $pdo, ?array $ids = null, ?string $nome = null, string $letras = 'completo', bool $comAjustes = true): array
{
    $colLetras = ', skill_in, skill_mid, skill_3pt, skill_post_d, skill_per_d, skill_play, skill_reb, skill_athl, skill_iq, skill_pot';
    $meta = $comAjustes ? estudosMeta($pdo) : [];
    $ajustes = $comAjustes ? estudosAjustes($pdo) : [];

    // Por nome: quem tem esse nome DE VERDADE (com as correções de nome da camada do Marcos).
    if ($nome !== null) { $ids = estudosIdsDoNome($pdo, $nome, $meta); $nome = null; }

    $where = '';
    if ($ids !== null) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return ['log' => [], 'stats' => [], 'premios' => []];
        $where = 'WHERE player_id IN (' . implode(',', $ids) . ')';
    }
    $st = $pdo->query("SELECT player_id, player_name, league, sprint_number, season_id, season_number, year,
                              team_name, ovr, age, position{$colLetras}
                         FROM player_season_log {$where}
                     ORDER BY player_id, year, season_number", PDO::FETCH_ASSOC);
    $log = [];
    // Linha a linha, e já enxugando: fetchAll() seguraria as 36 mil linhas inteiras
    // na memória antes de a gente descartar qualquer coisa.
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int)$r['player_id'];
        $r['attr_soma'] = estudosAtribSoma($r);
        // Quem tem correção guarda as letras até aplicá-la (a soma é refeita depois).
        if ($letras === 'resumo' && !isset($ajustes[$pid])) foreach (ESTUDOS_ATRIBUTOS as $col) unset($r[$col]);   // sobram a soma e o POT
        $log[$pid][] = $r;
    }

    $idsVidas = array_keys($log);
    $stats = [];
    if ($idsVidas) {
        $in = ($ids === null) ? '' : 'WHERE player_id IN (' . implode(',', $idsVidas) . ')';
        $s2 = $pdo->query("SELECT player_id, season_id, games, min_pg, pts_pg, reb_pg, ast_pg, stl_pg, blk_pg, fg_pct
                             FROM player_season_stats {$in} ORDER BY id");
        foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r) $stats[(int)$r['player_id']][(int)$r['season_id']] = $r;   // a última linha vale
    }

    if ($ajustes) estudosAplicarAjustes($log, $stats, $ajustes, $meta, $ids, $letras);

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

/**
 * As duas tendências que o Marcos acompanha: T/SHOT (o quanto ele arremessa) e
 * T/TOUCH (o quanto ele fica com a bola), de 0 a 99. Existem só pra quem foi
 * lançado em tendencys-elite.php — hoje a ELITE de 75 OVR pra cima. Sem a tabela,
 * devolve vazio em vez de quebrar.
 *
 * @return array<int,array{'T/SHOT':?int,'T/TOUCH':?int}>
 */
function estudosTendenciasChave(PDO $pdo, array $meta = []): array
{
    $out = [];
    try {
        foreach ($pdo->query("SELECT player_id, dados FROM player_tendencies")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = json_decode((string)$r['dados'], true) ?: [];
            $out[(int)$r['player_id']] = [
                'T/SHOT'  => isset($d['T/SHOT'])  ? (int)$d['T/SHOT']  : null,
                'T/TOUCH' => isset($d['T/TOUCH']) ? (int)$d['T/TOUCH'] : null,
            ];
        }
    } catch (Throwable $e) { /* sem a tabela: sem tendências */ }
    // O que o Marcos digitou vale por cima do que o jogo tem.
    foreach ($meta as $pid => $m) {
        if ($m['tshot'] !== null)  $out[(int)$pid]['T/SHOT']  = (int)$m['tshot'];
        if ($m['ttouch'] !== null) $out[(int)$pid]['T/TOUCH'] = (int)$m['ttouch'];
        if (isset($out[(int)$pid])) $out[(int)$pid] += ['T/SHOT' => null, 'T/TOUCH' => null];
    }
    return $out;
}

/**
 * Quantas vidas cada nome tem (todas, em todo o histórico), com os nomes corrigidos
 * e sem as vidas na lixeira.
 */
function estudosVidasPorNome(PDO $pdo, array $meta = []): array
{
    $por = [];   // pid => nome efetivo
    foreach ($pdo->query("SELECT DISTINCT player_id, player_name FROM player_season_log")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['player_id'];
        if (!empty($meta[$pid]['excluido'])) continue;
        $por[$pid] = estudosNomeEfetivo($pid, (string)$r['player_name'], $meta);
    }
    foreach ($meta as $pid => $m) {
        if (!isset($por[$pid]) && empty($m['excluido']) && trim((string)$m['nome']) !== '') $por[(int)$pid] = trim($m['nome']);
    }
    $out = [];
    foreach ($por as $nome) { $k = mb_strtolower(trim($nome)); $out[$k] = ($out[$k] ?? 0) + 1; }
    return $out;
}

/**
 * A lista de uma aba.
 *   elite     jogadores que estão hoje em times da ELITE
 *   outras    jogadores de NEXT, RISE e ROOKIE
 *   historico todo mundo: quem está nos elencos e quem já saiu
 *   favoritos quem o Marcos marcou com a estrela (de qualquer liga, ativo ou não)
 *   lixeira   quem o Marcos excluiu dos estudos (dá pra restaurar)
 *
 * Uma linha por jogador (vida), com a situação de HOJE (time, idade, OVR) quando
 * ele ainda está num elenco, e as métricas da carreira inteira. As correções da
 * camada do Marcos já vêm aplicadas; `ajustada` diz quem tem alguma. Lenda NÃO é
 * filtrada aqui — a flag `lenda` vai em cada linha e a tela decide (padrão: oculta).
 */
function estudosLista(PDO $pdo, string $grupo, array $opc = []): array
{
    $opc = estudosOpcoes($opc);
    $meta = estudosMeta($pdo);
    $ajustes = estudosAjustes($pdo);
    $lendas = estudosLendasIds($pdo, $meta);

    // Quem entra na aba, quando ela é uma seleção de ids e não de times.
    $selecionados = null;
    if ($grupo === 'favoritos') $selecionados = array_keys(array_filter($meta, fn($m) => (int)$m['favorito'] === 1 && (int)$m['excluido'] === 0));
    elseif ($grupo === 'lixeira') $selecionados = array_keys(array_filter($meta, fn($m) => (int)$m['excluido'] === 1));

    $atuais = [];   // player_id => situação de hoje
    $sqlAtual = "SELECT p.id, p.name, p.position, p.age, p.ovr, p.height, p.is_lenda, p.seasons_in_league,
                        t.league AS liga, TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS time
                   FROM players p JOIN teams t ON t.id = p.team_id";
    if ($grupo === 'elite')       $sqlAtual .= " WHERE t.league = 'ELITE'";
    elseif ($grupo === 'outras')  $sqlAtual .= " WHERE t.league IN ('NEXT','RISE','ROOKIE')";
    elseif ($selecionados !== null) $sqlAtual .= $selecionados ? ' WHERE p.id IN (' . implode(',', array_map('intval', $selecionados)) . ')' : ' WHERE 1 = 0';
    foreach ($pdo->query($sqlAtual)->fetchAll(PDO::FETCH_ASSOC) as $p) $atuais[(int)$p['id']] = $p;

    $ids = $grupo === 'historico' ? null : ($selecionados ?? array_keys($atuais));
    $c = estudosCarregar($pdo, $ids, null, 'resumo');   // lista: só a soma dos atributos e o POT
    $vidasNome = estudosVidasPorNome($pdo, $meta);
    $tend = estudosTendenciasChave($pdo, $meta);

    // No histórico entram os do log e também quem está nos elencos sem log ainda.
    if ($selecionados !== null) $todos = $selecionados;
    elseif ($grupo === 'historico') {
        $todos = array_unique(array_merge(array_keys($c['log']), array_keys($atuais),
                 array_keys(array_filter($meta, fn($m) => (int)$m['excluido'] === 0))));
    } else $todos = array_keys($atuais);

    $linhas = [];
    foreach ($todos as $pid) {
        $pid = (int)$pid;
        $mt = $meta[$pid] ?? null;
        if ($selecionados === null && $mt && (int)$mt['excluido'] === 1) continue;   // lixeira só na aba dela
        $temps = $c['log'][$pid] ?? [];
        $m = estudosMetricas($temps, $c['stats'][$pid] ?? [], estudosPremiosDaVida($temps, $c['premios']), $opc);
        $hoje = $atuais[$pid] ?? null;
        $ultima = $temps ? $temps[count($temps) - 1] : null;
        $nome = estudosNomeEfetivo($pid, (string)($hoje['name'] ?? ($ultima['player_name'] ?? '')), $meta);
        if ($nome === '') $nome = '?';
        // O OVR do 99 festivo não é de ninguém: sem isso ele liderava a coluna OVR.
        $ovrFestivo = !$hoje && !empty($m['festiva']);
        $linhas[] = [
            'id'    => $pid,
            'nome'  => $nome,
            'ativo' => $hoje !== null,
            'liga'  => $hoje['liga'] ?? ($ultima['league'] ?? null),
            'time'  => $hoje['time'] ?? ($ultima['team_name'] ?? null),
            'pos'   => $hoje['position'] ?? ($ultima['position'] ?? null),
            'idade' => isset($hoje['age']) ? (int)$hoje['age'] : ($ultima ? (int)$ultima['age'] : null),
            'ovr'   => isset($hoje['ovr']) ? (int)$hoje['ovr'] : (($ultima && !$ovrFestivo) ? (int)$ultima['ovr'] : null),
            'festiva' => !empty($m['festiva']),
            'lenda' => isset($lendas[$pid]),
            'fav'   => (int)($mt['favorito'] ?? 0) === 1,
            'ajustada' => isset($ajustes[$pid]) || ($mt && ($mt['nome'] !== null || $mt['lenda'] !== null || $mt['tshot'] !== null || $mt['ttouch'] !== null)),
            'manual' => $pid >= ESTUDOS_ID_MANUAL,
            'nota'  => $mt && trim((string)$mt['nota']) !== '' ? (string)$mt['nota'] : null,
            'vidas' => $vidasNome[mb_strtolower(trim($nome))] ?? 1,
            'tend'  => isset($tend[$pid]),
            'tshot' => $tend[$pid]['T/SHOT'] ?? null,
            'ttouch' => $tend[$pid]['T/TOUCH'] ?? null,
            'm'     => estudosCompacta($m),
        ];
    }
    usort($linhas, fn($a, $b) => ($b['ovr'] ?? 0) <=> ($a['ovr'] ?? 0) ?: strcmp($a['nome'], $b['nome']));
    return $linhas;
}

/** Uma temporada no formato da tela (a do detalhe da vida e a do editor). */
function estudosFormatarTemporada(array $t, ?array $s, array $premios = []): array
{
    $letras = [];
    foreach (ESTUDOS_SKILLS as $col => $sigla) { $v = trim((string)($t[$col] ?? '')); $letras[$sigla] = $v !== '' ? $v : null; }
    $f = fn($x) => ($x === null || $x === '') ? null : (float)$x;
    return [
        'season_id' => (int)$t['season_id'], 'temporada' => (int)$t['season_number'], 'ano' => (int)$t['year'], 'liga' => $t['league'],
        'time' => $t['team_name'], 'idade' => (int)$t['age'], 'ovr' => (int)$t['ovr'], 'pos' => $t['position'],
        'letras' => $letras,
        'stats' => ($s && isset($s['games'])) ? [
            'jogos' => (int)$s['games'], 'min' => $f($s['min_pg'] ?? null),
            'pts' => $f($s['pts_pg'] ?? null), 'reb' => $f($s['reb_pg'] ?? null), 'ast' => $f($s['ast_pg'] ?? null),
            'stl' => $f($s['stl_pg'] ?? null), 'blk' => $f($s['blk_pg'] ?? null), 'fg' => $f($s['fg_pct'] ?? null),
        ] : null,
        'premios' => $premios,
        'ajustada' => !empty($t['aj']), 'manual' => !empty($t['man']),
    ];
}

/** A carreira completa de uma vida: temporada a temporada. */
function estudosVida(PDO $pdo, int $id, array $opc = []): ?array
{
    $opc = estudosOpcoes($opc);
    $meta = estudosMeta($pdo);
    $mt = $meta[$id] ?? null;
    $c = estudosCarregar($pdo, [$id]);
    $temps = $c['log'][$id] ?? [];

    $st = $pdo->prepare("SELECT p.*, t.league AS liga, TRIM(CONCAT(COALESCE(t.city,''),' ',COALESCE(t.name,''))) AS time
                           FROM players p LEFT JOIN teams t ON t.id = p.team_id WHERE p.id = ?");
    $st->execute([$id]);
    $hoje = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    // Temporadas que existem no jogo e o Marcos tirou: voltam pelo editor.
    $base = estudosBaseDaVida($pdo, $id);
    if (!$temps && !$hoje && !$mt && !$base['temporadas']) return null;

    $original = (string)($hoje['name'] ?? ($base['nome'] ?: ''));
    $nome = estudosNomeEfetivo($id, $original, $meta);
    $prem = estudosPremiosDaVida($temps, $c['premios']);
    $m = estudosMetricas($temps, $c['stats'][$id] ?? [], $prem, $opc);

    $seasons = [];
    foreach ($temps as $t) {
        $sid = (int)$t['season_id'];
        $seasons[] = estudosFormatarTemporada($t, $c['stats'][$id][$sid] ?? null, $prem[$sid] ?? []);
    }
    $vivas = array_flip(array_map(fn($t) => (int)$t['season_id'], $temps));
    $removidas = [];
    foreach ($base['temporadas'] as $sid => $t) {
        if (!isset($vivas[$sid])) $removidas[] = estudosFormatarTemporada($t, $base['stats'][$sid] ?? null);
    }

    $tendencias = null;
    try {
        $q = $pdo->prepare("SELECT dados, atualizado_em FROM player_tendencies WHERE player_id = ?");
        $q->execute([$id]);
        if ($r = $q->fetch(PDO::FETCH_ASSOC)) $tendencias = ['dados' => json_decode((string)$r['dados'], true) ?: [], 'em' => $r['atualizado_em']];
    } catch (Throwable $e) {}
    if ($mt && ($mt['tshot'] !== null || $mt['ttouch'] !== null)) {
        $tendencias = $tendencias ?? ['dados' => [], 'em' => null];
        if ($mt['tshot'] !== null)  $tendencias['dados']['T/SHOT']  = (int)$mt['tshot'];
        if ($mt['ttouch'] !== null) $tendencias['dados']['T/TOUCH'] = (int)$mt['ttouch'];
    }

    $outras = [];
    $outrosIds = array_values(array_diff(estudosIdsDoNome($pdo, $nome, $meta), [$id]));
    if ($outrosIds) {
        $o = estudosCarregar($pdo, $outrosIds, null, 'resumo');
        foreach ($outrosIds as $oid) {
            $ts = $o['log'][$oid] ?? [];
            if (!$ts) continue;
            $outras[] = ['id' => $oid, 'liga' => $ts[0]['league'], 'n' => count($ts), 'pico' => max(array_map(fn($t) => (int)$t['ovr'], $ts))];
        }
    }

    $lendaAuto = !empty($hoje['is_lenda']);
    $lendaManual = $mt && $mt['lenda'] !== null ? (int)$mt['lenda'] : null;
    return [
        'id' => $id, 'nome' => $nome,
        'hoje' => $hoje ? ['time' => $hoje['time'], 'liga' => $hoje['liga'], 'pos' => $hoje['position'], 'idade' => (int)$hoje['age'],
                           'ovr' => (int)$hoje['ovr'], 'altura' => $hoje['height'], 'lenda' => $lendaAuto] : null,
        'meta' => [
            'favorito' => (int)($mt['favorito'] ?? 0) === 1, 'excluido' => (int)($mt['excluido'] ?? 0) === 1,
            'nome_original' => $original, 'nome_corrigido' => $mt && trim((string)$mt['nome']) !== '' ? $mt['nome'] : null,
            'lenda_auto' => $lendaAuto, 'lenda_manual' => $lendaManual, 'lenda' => $lendaManual !== null ? $lendaManual === 1 : $lendaAuto,
            'nota' => $mt['nota'] ?? null, 'manual' => $id >= ESTUDOS_ID_MANUAL,
            'tshot_manual' => $mt['tshot'] ?? null, 'ttouch_manual' => $mt['ttouch'] ?? null,
        ],
        'm' => $m, 'temporadas' => $seasons, 'removidas' => $removidas, 'curva' => estudosCurva($temps),
        'tendencias' => $tendencias, 'outras_vidas' => $outras,
    ];
}

/** Os nomes com mais de uma vida — a lista do "estudo por nome" (com as correções de nome, sem a lixeira). */
function estudosNomes(PDO $pdo, int $minVidas = 2): array
{
    $meta = estudosMeta($pdo);
    $ajustes = estudosAjustes($pdo);
    $vidas = [];   // pid => [nome, n, pico]
    foreach ($pdo->query("SELECT player_id, player_name, COUNT(*) n, MAX(ovr) pico FROM player_season_log GROUP BY player_id, player_name")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['player_id'];
        if (!empty($meta[$pid]['excluido'])) continue;
        $tiradas = count(array_filter($ajustes[$pid] ?? [], fn($a) => $a['excluida']));
        $vidas[$pid] = [estudosNomeEfetivo($pid, (string)$r['player_name'], $meta), max(0, (int)$r['n'] - $tiradas), (int)$r['pico']];
    }
    foreach ($ajustes as $pid => $porTemp) {
        foreach ($porTemp as $a) {
            if (!$a['manual'] || !empty($meta[$pid]['excluido'])) continue;
            $v = $vidas[$pid] ?? [estudosNomeEfetivo($pid, '', $meta), 0, 0];
            $v[1]++; $v[2] = max($v[2], (int)($a['ajuste']['ovr'] ?? 0));
            $vidas[$pid] = $v;
        }
    }
    $por = [];   // nome minúsculo => dados
    foreach ($vidas as [$nome, $n, $pico]) {
        if (trim($nome) === '') continue;
        $k = mb_strtolower(trim($nome));
        $x = $por[$k] ?? ['nome' => trim($nome), 'vidas' => 0, 'temporadas' => 0, 'pico' => 0];
        $x['vidas']++; $x['temporadas'] += $n; $x['pico'] = max($x['pico'], $pico);
        $por[$k] = $x;
    }
    $out = array_values(array_filter($por, fn($x) => $x['vidas'] >= $minVidas));
    usort($out, fn($a, $b) => $b['vidas'] <=> $a['vidas'] ?: $b['temporadas'] <=> $a['temporadas'] ?: strcmp($a['nome'], $b['nome']));
    return $out;
}

/**
 * O ESTUDO POR NOME: todas as vidas de um jogador real, e a média delas.
 *
 * As médias só usam vidas com pelo menos $minTemp temporadas (padrão 3): vida
 * de uma temporada só é, quase sempre, uma foto isolada e não uma carreira, e
 * um 99 desses puxaria o "pico médio" pra um número que ninguém sustentou. As
 * vidas curtas continuam na lista, marcadas, só ficam fora da conta.
 * Lendas ficam fora da conta, a menos que $comLendas (pedido do Marcos, 05/11/2026).
 */
function estudosNome(PDO $pdo, string $nome, int $minTemp = ESTUDOS_MIN_TEMP_COORTE, array $opc = [], bool $comLendas = false): array
{
    $opc = estudosOpcoes($opc);
    $meta = estudosMeta($pdo);
    $ids = estudosIdsDoNome($pdo, $nome, $meta);
    if (!$ids) return ['ok' => false, 'erro' => 'Nenhum jogador com esse nome no histórico.'];

    $lendas = estudosLendasIds($pdo, $meta);
    $c = estudosCarregar($pdo, $ids);
    $tendencias = estudosTendenciasChave($pdo, $meta);
    $exato = null;
    $vidas = [];
    foreach ($ids as $pid) {
        $temps = $c['log'][$pid] ?? [];
        if ($exato === null) $exato = estudosNomeEfetivo($pid, (string)($temps[0]['player_name'] ?? ''), $meta);
        $m = estudosMetricas($temps, $c['stats'][$pid] ?? [], estudosPremiosDaVida($temps, $c['premios']), $opc);
        $ehLenda = isset($lendas[$pid]);
        // Vida de 99 festivo nunca entra nas médias, qualquer que seja o mínimo de temporadas.
        $vidas[] = ['id' => $pid, 'liga' => $temps[0]['league'] ?? null, 'm' => $m, 'curva' => estudosCurva($temps),
                    'festiva' => !empty($m['festiva']), 'lenda' => $ehLenda, 'fav' => (int)($meta[$pid]['favorito'] ?? 0) === 1,
                    'tshot' => $tendencias[$pid]['T/SHOT'] ?? null, 'ttouch' => $tendencias[$pid]['T/TOUCH'] ?? null,
                    'usada' => ($m['n'] ?? 0) >= $minTemp && empty($m['festiva']) && ($comLendas || !$ehLenda)];
    }
    $usadas = array_values(array_filter($vidas, fn($v) => $v['usada']));
    $col = fn(string $k) => array_map(fn($v) => $v['m'][$k] ?? null, $usadas);
    $res = 'estudosResumo';

    $resumo = [];
    foreach (['peak_start' => 'peak_start', 'peak_end' => 'peak_end', 'anos_pico' => 'anos_de_pico', 'anos_ate_pico' => 'anos_ate_o_pico',
              'pot_ini_num' => 'pot_inicial',
              'n' => 'temporadas', 'pico' => 'pico', 'pico_sust' => 'pico_sustentado', 'idade_pico_sust' => 'idade_do_pico',
              'ovr_fim' => 'ovr_no_fim', 'idade_fim' => 'idade_no_fim', 'queda' => 'queda_pico_ao_fim',
              'anos_85' => 'anos_85_ou_mais', 'anos_90' => 'anos_90_ou_mais', 'ovr_ini' => 'ovr_inicial', 'idade_ini' => 'idade_inicial',
              'min' => 'minutos', 'pts_pg' => 'pts', 'reb_pg' => 'reb', 'ast_pg' => 'ast', 'stl_pg' => 'stl', 'blk_pg' => 'blk', 'fg' => 'fg',
              'pico_pts_pg' => 'pico_pts', 'pico_reb_pg' => 'pico_reb', 'pico_ast_pg' => 'pico_ast',
              'pico_stl_pg' => 'pico_stl', 'pico_blk_pg' => 'pico_blk', 'pico_fg' => 'pico_fg'] as $k => $rotulo) {
        $resumo[$rotulo] = $res($col($k));
    }

    // As tendências vêm de fora do log: só as vidas lançadas têm, e o "Vidas" diz quantas.
    $resumo['t_shot']  = $res(array_map(fn($v) => $v['tshot'], $usadas));
    $resumo['t_touch'] = $res(array_map(fn($v) => $v['ttouch'], $usadas));

    // O POT na entrada, de volta em letra (a média numérica fica na dica).
    $potMedia = $resumo['pot_inicial']['media'];
    $potLetra = estudosNumNota($potMedia);

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

    return ['ok' => true, 'nome' => (string)$exato, 'min_temporadas' => $minTemp, 'opc' => $opc, 'com_lendas' => $comLendas,
            'lendas_fora' => count(array_filter($vidas, fn($v) => $v['lenda'])),
            'vidas' => $vidas, 'vidas_total' => count($vidas), 'vidas_usadas' => count($usadas),
            'resumo' => $resumo, 'pot_inicial_letra' => $potLetra, 'letras_pico' => $letras,
            'curva_media' => $curva, 'variacao_anual' => $variacao];
}
