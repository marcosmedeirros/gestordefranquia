<?php
/**
 * PROJEÇÕES DO /duvida — temporada de um time, confronto entre dois e médias
 * de um jogador pra próxima temporada.
 *
 * A CONTA RODA AQUI, E NÃO NO MODELO. Deixado por conta própria, o Gemini
 * inventa uma régua por pergunta e escreve "vai terminar em 3º" como se fosse
 * fato. Estas funções devolvem o número pronto, com a base ao lado, e o bot só
 * explica.
 *
 * A RÉGUA DE FORÇA É O ELENCO, calibrada contra a liga (11/09/2026):
 *   - A classificação só guarda a POSIÇÃO na conferência; vitória e derrota são
 *     zero em todas as linhas. Não há placar pra medir força por jogo.
 *   - OVR dos 8 melhores acerta a ordem final com correlação 0,66 (14
 *     conferências-temporada, as quatro ligas). Top 5 deu 0,659, top 8 0,664,
 *     top 10 0,603; a versão ponderada abaixo, 0,668.
 *   - A produção lançada acerta menos (de -0,08 a 0,77) e falta time sem
 *     estatística — não entra.
 *   - A posição da temporada anterior prevê a seguinte com 0,59: menos que o
 *     elenco. Aparece como referência, não como parte da conta.
 *
 * O ACASO TEM O TAMANHO QUE A LIGA MOSTROU: somando ruído normal de 1 desvio
 * (PROJ_ACASO) à força padronizada, a simulação reproduz a mesma correlação
 * de 0,66 que a liga teve. Com menos ruído as chances sairiam otimistas demais
 * — a FBA é bem mais imprevisível que o OVR sugere.
 */

require_once __DIR__ . '/stats_temporada.php';

const PROJ_ACASO = 1.0;
const PROJ_SIMULACOES = 3000;
const PROJ_VAGAS_PLAYOFF = 8;

/** Peso de cada jogador na força, pela ordem de OVR no elenco. */
function projPeso(int $i): float
{
    return $i < 3 ? 1.5 : ($i < 5 ? 1.0 : 0.5);
}

function projNum(float $n, int $casas = 1): string
{
    return number_format($n, $casas, ',', '.');
}

function projPct(float $p): string
{
    $v = $p * 100;
    if ($v > 0 && $v < 1) return '<1%';
    if ($v < 100 && $v > 99) return '>99%';
    return round($v) . '%';
}

/** Função de distribuição da normal padrão (Abramowitz-Stegun 26.2.17). */
function projNormal(float $x): float
{
    $t = 1 / (1 + 0.2316419 * abs($x));
    $d = 0.3989423 * exp(-$x * $x / 2);
    $p = $d * $t * (0.3193815 + $t * (-0.3565638 + $t * (1.781478 + $t * (-1.821256 + $t * 1.330274))));
    return $x > 0 ? 1 - $p : $p;
}

function projGauss(): float
{
    $u = 1 - mt_rand() / mt_getrandmax();
    $v = mt_rand() / mt_getrandmax();
    return sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
}

/** Força de um time: OVR ponderado dos 8 melhores do elenco de hoje. */
function projForcaDoTime(PDO $pdo, int $teamId): array
{
    $st = $pdo->prepare('SELECT name, ovr, position, role FROM players WHERE team_id = ? ORDER BY ovr DESC, name LIMIT 8');
    $st->execute([$teamId]);
    $js = $st->fetchAll(PDO::FETCH_ASSOC);
    $soma = 0; $pesos = 0;
    foreach ($js as $i => $j) { $p = projPeso($i); $soma += (int)$j['ovr'] * $p; $pesos += $p; }
    return ['forca' => $pesos ? $soma / $pesos : 0.0, 'top' => array_slice($js, 0, 3), 'n' => count($js)];
}

/** Todos os times da liga com força e força padronizada (z). */
function projTimesDaLiga(PDO $pdo, string $liga): array
{
    $st = $pdo->prepare('SELECT id, city, name, conference FROM teams WHERE league = ? ORDER BY id');
    $st->execute([strtoupper($liga)]);
    $times = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $f = projForcaDoTime($pdo, (int)$t['id']);
        if ($f['n'] === 0) continue;
        $times[(int)$t['id']] = [
            'id' => (int)$t['id'],
            'nome' => trim(($t['city'] ?? '') . ' ' . $t['name']),
            'conf' => $t['conference'] ?: 'Liga',
            'forca' => $f['forca'],
            'top' => $f['top'],
        ];
    }
    if (!$times) return [];
    $vals = array_column($times, 'forca');
    $media = array_sum($vals) / count($vals);
    $dp = sqrt(array_sum(array_map(fn($v) => ($v - $media) ** 2, $vals)) / count($vals)) ?: 1.0;
    foreach ($times as &$t) $t['z'] = ($t['forca'] - $media) / $dp;
    unset($t);
    return $times;
}

/**
 * Acha um time pelo que a pessoa escreveu ("Coyotes", "Las Vegas", "San Jose").
 * Prefere a liga do grupo; ambíguo, devolve as opções em vez de chutar.
 */
function projAcharTime(PDO $pdo, string $texto, string $ligaPreferida): array
{
    $texto = trim($texto);
    if ($texto === '') return ['erro' => 'Qual time? Não veio nome nenhum.'];
    $like = '%' . $texto . '%';
    $st = $pdo->prepare("SELECT id, city, name, league FROM teams
                          WHERE CONCAT(COALESCE(city,''),' ',name) LIKE ? OR name LIKE ? OR city LIKE ?");
    $st->execute([$like, $like, $like]);
    $achados = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$achados) return ['erro' => "Não achei time com \"{$texto}\"."];

    $daLiga = array_values(array_filter($achados, fn($t) => strtoupper($t['league']) === strtoupper($ligaPreferida)));
    $lista = $daLiga ?: $achados;
    $alvo = mb_strtolower($texto);
    foreach ($lista as $t) {
        $cheio = mb_strtolower(trim(($t['city'] ?? '') . ' ' . $t['name']));
        if ($cheio === $alvo || mb_strtolower($t['name']) === $alvo) return ['time' => $t];
    }
    if (count($lista) === 1) return ['time' => $lista[0]];
    $nomes = array_map(fn($t) => trim(($t['city'] ?? '') . ' ' . $t['name']) . ' (' . $t['league'] . ')', array_slice($lista, 0, 6));
    return ['erro' => "\"{$texto}\" pode ser mais de um time: " . implode(', ', $nomes) . '. Pergunte qual.'];
}

/** Série melhor de 7: chance de A levar e a chance de cada placar. */
function projSerie(float $p): array
{
    $comb = fn($n, $k) => $k === 0 ? 1 : array_product(range($n - $k + 1, $n)) / array_product(range(1, $k));
    $placares = []; $a = 0.0;
    for ($d = 0; $d <= 3; $d++) {
        $pr = $comb(3 + $d, $d) * ($p ** 4) * ((1 - $p) ** $d);
        $placares["4-{$d}"] = $pr; $a += $pr;
    }
    for ($v = 0; $v <= 3; $v++) {
        $placares["{$v}-4"] = $comb(3 + $v, $v) * ((1 - $p) ** 4) * ($p ** $v);
    }
    return ['a' => $a, 'placares' => $placares];
}

/** A chance num jogo isolado que produz esta chance de série. */
function projJogoDaSerie(float $pSerie): float
{
    $lo = 0.0; $hi = 1.0;
    for ($i = 0; $i < 40; $i++) {
        $m = ($lo + $hi) / 2;
        if (projSerie($m)['a'] < $pSerie) $lo = $m; else $hi = $m;
    }
    return ($lo + $hi) / 2;
}

/**
 * Chance de A passar por B numa série. É a mesma chance de A terminar à frente
 * de B na simulação da temporada: diferença de força sobre o acaso dos dois.
 */
function projChanceSerie(float $zA, float $zB): float
{
    return projNormal(($zA - $zB) / (PROJ_ACASO * M_SQRT2));
}

/** Simula a temporada inteira N vezes: posição, playoff, final e título. */
function projSimularLiga(array $times, string $semente): array
{
    mt_srand(crc32($semente));
    $confs = [];
    foreach ($times as $id => $t) $confs[$t['conf']][] = $id;
    $r = [];
    foreach ($times as $id => $_) {
        $r[$id] = ['somaPos' => 0, 'pos' => [], 'playoff' => 0, 'top4' => 0, 'lider' => 0, 'final' => 0, 'titulo' => 0];
    }
    $avanca = function (int $a, int $b) use ($times): int {
        return (mt_rand() / mt_getrandmax()) < projChanceSerie($times[$a]['z'], $times[$b]['z']) ? $a : $b;
    };

    for ($n = 0; $n < PROJ_SIMULACOES; $n++) {
        $campeoesConf = [];
        foreach ($confs as $ids) {
            $placar = [];
            foreach ($ids as $id) $placar[$id] = $times[$id]['z'] + PROJ_ACASO * projGauss();
            arsort($placar);
            $ordem = array_keys($placar);
            foreach ($ordem as $i => $id) {
                $pos = $i + 1;
                $r[$id]['somaPos'] += $pos;
                $r[$id]['pos'][$pos] = ($r[$id]['pos'][$pos] ?? 0) + 1;
                if ($pos <= PROJ_VAGAS_PLAYOFF) $r[$id]['playoff']++;
                if ($pos <= 4) $r[$id]['top4']++;
                if ($pos === 1) $r[$id]['lider']++;
            }
            // Chave de 8: 1x8, 4x5, 2x7, 3x6.
            $s = array_slice($ordem, 0, PROJ_VAGAS_PLAYOFF);
            if (count($s) < PROJ_VAGAS_PLAYOFF) { $campeoesConf[] = $s[0]; continue; }
            $w1 = $avanca($s[0], $s[7]); $w2 = $avanca($s[3], $s[4]);
            $w3 = $avanca($s[1], $s[6]); $w4 = $avanca($s[2], $s[5]);
            $campeoesConf[] = $avanca($avanca($w1, $w2), $avanca($w3, $w4));
        }
        foreach ($campeoesConf as $id) $r[$id]['final']++;
        if (count($campeoesConf) === 2) {
            $r[$avanca($campeoesConf[0], $campeoesConf[1])]['titulo']++;
        } elseif (count($campeoesConf) === 1) {
            $r[$campeoesConf[0]]['titulo']++;
        }
    }
    return $r;
}

/** Faixa de posições que cobre 80% das simulações (do 10% ao 90%). */
function projFaixa(array $contagem): array
{
    ksort($contagem);
    $total = array_sum($contagem); $acc = 0; $lo = null; $hi = null; $moda = null; $max = -1;
    foreach ($contagem as $pos => $c) {
        if ($c > $max) { $max = $c; $moda = $pos; }
        $acc += $c;
        if ($lo === null && $acc >= 0.10 * $total) $lo = $pos;
        if ($hi === null && $acc >= 0.90 * $total) $hi = $pos;
    }
    return [$lo, $hi, $moda];
}

function projUltimaPosicao(PDO $pdo, int $teamId): string
{
    try {
        $st = $pdo->prepare("SELECT ss.position, s.season_number FROM season_standings ss
                               JOIN seasons s ON s.id = ss.season_id JOIN sprints sp ON sp.id = s.sprint_id
                              WHERE ss.team_id = ? AND sp.status = 'active'
                           ORDER BY s.season_number DESC LIMIT 1");
        $st->execute([$teamId]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) return (int)$r['position'] . 'º na T' . (int)$r['season_number'];
    } catch (Throwable $e) { error_log('[duvida/proj] ultima posicao: ' . $e->getMessage()); }
    return 'sem classificação nesta sprint';
}

const PROJ_RESSALVA = 'COMO LER: é estimativa pelo elenco de hoje (OVR ponderado dos 8 melhores). '
    . 'Não entra tática, química, lesão nem troca futura. Na FBA essa régua acerta a ordem da tabela '
    . 'com correlação de 0,66 — boa, longe de certeza; as chances já embutem esse acaso. '
    . 'Ao responder, fale em chance e faixa, nunca como resultado cravado.';

/** Temporada de um time, pronta pro bot. */
function projTemporadaTexto(PDO $pdo, string $textoTime, string $ligaGrupo): string
{
    $a = projAcharTime($pdo, $textoTime, $ligaGrupo);
    if (isset($a['erro'])) return $a['erro'];
    $time = $a['time'];
    $liga = strtoupper($time['league']);
    $times = projTimesDaLiga($pdo, $liga);
    $id = (int)$time['id'];
    if (!isset($times[$id])) return 'Esse time está sem elenco — não dá pra projetar.';

    $sim = projSimularLiga($times, $liga . '|' . date('Y-m-d'));
    $me = $times[$id];
    $conf = array_filter($times, fn($t) => $t['conf'] === $me['conf']);
    uasort($conf, fn($x, $y) => $y['forca'] <=> $x['forca']);
    $rankForca = array_search($id, array_keys($conf), true) + 1;
    $mediaConf = array_sum(array_column($conf, 'forca')) / count($conf);
    [$lo, $hi, $moda] = projFaixa($sim[$id]['pos']);
    $N = PROJ_SIMULACOES;
    $s = $sim[$id];

    $l = [];
    $l[] = "PROJEÇÃO DA TEMPORADA — {$me['nome']} ({$liga}, conferência {$me['conf']}, {$N} temporadas simuladas)";
    $l[] = '- Força do elenco: OVR ponderado ' . projNum($me['forca']) . " — {$rankForca}º mais forte de " . count($conf)
         . ' na conferência (média da conferência ' . projNum($mediaConf) . ').';
    $l[] = '- Quem mais pesa: ' . implode(', ', array_map(fn($j) => "{$j['name']} ({$j['ovr']})", $me['top'])) . '.';
    $l[] = "- Posição mais provável: {$moda}º. Em 80% das simulações terminou entre {$lo}º e {$hi}º (média "
         . projNum($s['somaPos'] / $N) . ').';
    $l[] = '- Playoff (top ' . PROJ_VAGAS_PLAYOFF . ' da conferência): ' . projPct($s['playoff'] / $N)
         . ' · Top 4: ' . projPct($s['top4'] / $N) . ' · Líder da conferência: ' . projPct($s['lider'] / $N) . '.';
    $l[] = '- Chegar à final: ' . projPct($s['final'] / $N) . ' · Título: ' . projPct($s['titulo'] / $N) . '.';
    $l[] = '- Referência: ' . projUltimaPosicao($pdo, $id) . ' (a última classificação; não entra na conta).';
    $favoritos = $sim; uasort($favoritos, fn($x, $y) => $y['titulo'] <=> $x['titulo']);
    $l[] = '- Favoritos ao título na liga: ' . implode(', ', array_map(
        fn($tid) => $times[$tid]['nome'] . ' ' . projPct($sim[$tid]['titulo'] / $N),
        array_slice(array_keys($favoritos), 0, 3))) . '.';
    $l[] = PROJ_RESSALVA;
    return implode("\n", $l);
}

/** O titular de maior OVR de cada posição (ou o melhor da posição, sem titular). */
function projQuintetoPorPosicao(PDO $pdo, int $teamId): array
{
    $st = $pdo->prepare("SELECT name, ovr, UPPER(TRIM(position)) pos, role FROM players WHERE team_id = ?
                       ORDER BY (role = 'Titular') DESC, ovr DESC");
    $st->execute([$teamId]);
    $q = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $j) {
        if (in_array($j['pos'], ['PG', 'SG', 'SF', 'PF', 'C'], true) && !isset($q[$j['pos']])) $q[$j['pos']] = $j;
    }
    return $q;
}

/** Confronto entre dois times, pronto pro bot. */
function projConfrontoTexto(PDO $pdo, string $textoA, string $textoB, string $ligaGrupo): string
{
    $a = projAcharTime($pdo, $textoA, $ligaGrupo);
    if (isset($a['erro'])) return $a['erro'];
    $b = projAcharTime($pdo, $textoB, $ligaGrupo);
    if (isset($b['erro'])) return $b['erro'];
    if ((int)$a['time']['id'] === (int)$b['time']['id']) return 'Os dois nomes caíram no mesmo time.';
    if (strtoupper($a['time']['league']) !== strtoupper($b['time']['league'])) {
        return 'Os dois times são de ligas diferentes (' . $a['time']['league'] . ' e ' . $b['time']['league']
             . '): a força é medida dentro da liga, então o confronto não tem régua comum.';
    }
    $liga = strtoupper($a['time']['league']);
    $times = projTimesDaLiga($pdo, $liga);
    $A = $times[(int)$a['time']['id']] ?? null; $B = $times[(int)$b['time']['id']] ?? null;
    if (!$A || !$B) return 'Um dos times está sem elenco — não dá pra projetar.';

    $pSerie = projChanceSerie($A['z'], $B['z']);
    $pJogo = projJogoDaSerie($pSerie);
    $serie = projSerie($pJogo)['placares'];
    arsort($serie);
    $maisProvavel = array_key_first($serie);
    [$ga, $gb] = array_map('intval', explode('-', $maisProvavel));
    $quemLeva = $ga === 4 ? $A['nome'] : $B['nome'];
    $placarTxt = $ga === 4 ? "4-{$gb}" : "4-{$ga}";

    $l = [];
    $l[] = "CONFRONTO — {$A['nome']} x {$B['nome']} ({$liga})";
    $l[] = '- Força do elenco: ' . $A['nome'] . ' ' . projNum($A['forca']) . ' x ' . projNum($B['forca']) . ' ' . $B['nome'] . '.';
    $l[] = '- Série melhor de 7: ' . $A['nome'] . ' ' . projPct($pSerie) . ' x ' . projPct(1 - $pSerie) . ' ' . $B['nome'] . '.';
    $l[] = "- Placar mais provável: {$placarTxt} pra {$quemLeva} (" . projPct($serie[$maisProvavel]) . ').';
    $l[] = '- Num jogo só: ' . $A['nome'] . ' ' . projPct($pJogo) . ' x ' . projPct(1 - $pJogo) . ' ' . $B['nome'] . '.';
    $qa = projQuintetoPorPosicao($pdo, $A['id']); $qb = projQuintetoPorPosicao($pdo, $B['id']);
    $duelos = [];
    foreach (['PG', 'SG', 'SF', 'PF', 'C'] as $p) {
        $ja = $qa[$p] ?? null; $jb = $qb[$p] ?? null;
        if (!$ja && !$jb) continue;
        $duelos[] = "{$p}: " . ($ja ? "{$ja['name']} {$ja['ovr']}" : '—') . ' x ' . ($jb ? "{$jb['name']} {$jb['ovr']}" : '—');
    }
    if ($duelos) $l[] = '- Duelo dos titulares por posição: ' . implode(' · ', $duelos) . '.';
    $l[] = PROJ_RESSALVA;
    return implode("\n", $l);
}

/** Médias projetadas de um jogador pra próxima temporada, pronto pro bot. */
function projJogadorTexto(PDO $pdo, string $textoJogador, string $ligaGrupo): string
{
    $texto = trim($textoJogador);
    if ($texto === '') return 'Qual jogador? Não veio nome nenhum.';
    $st = $pdo->prepare("SELECT p.id, p.name, p.age, p.ovr, p.position, t.league, TRIM(CONCAT(COALESCE(t.city,''),' ',t.name)) time
                           FROM players p JOIN teams t ON t.id = p.team_id WHERE p.name LIKE ?
                       ORDER BY (t.league = ?) DESC, (p.name = ?) DESC, p.ovr DESC LIMIT 6");
    $st->execute(['%' . $texto . '%', strtoupper($ligaGrupo), $texto]);
    $achados = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$achados) return "Não achei jogador em elenco com \"{$texto}\".";
    $exatos = array_values(array_filter($achados, fn($j) => mb_strtolower($j['name']) === mb_strtolower($texto)));
    // Nome exato repetido entre ligas (há um Kobe Bryant na ELITE e outro na
    // ROOKIE): vale o da liga do grupo, que é de quem se está falando.
    $exatosDaLiga = array_values(array_filter($exatos, fn($x) => strtoupper($x['league']) === strtoupper($ligaGrupo)));
    if (count($exatos) > 1 && count($exatosDaLiga) === 1) $exatos = $exatosDaLiga;
    if (count($exatos) === 1) $j = $exatos[0];
    elseif (count($achados) === 1) $j = $achados[0];
    else {
        return "\"{$texto}\" pode ser: " . implode(', ', array_map(fn($x) => "{$x['name']} ({$x['time']}, {$x['league']})", $achados)) . '. Pergunte qual.';
    }

    // O mesmo jogador pode ter mais de um id: dispensa e recontratação criam
    // cadastro novo. O histórico liga os ids pelo nome — mas SÓ DENTRO DA LIGA
    // dele: há um Kobe Bryant na ELITE e outro na ROOKIE, e ligar pelo nome sem
    // a liga dava ao da ROOKIE a temporada do da ELITE.
    $liga = strtoupper((string)$j['league']);
    $ids = [(int)$j['id']];
    try {
        $sl = $pdo->prepare('SELECT DISTINCT player_id FROM player_season_log WHERE player_name = ? AND league = ?');
        $sl->execute([$j['name'], $liga]);
        foreach ($sl->fetchAll(PDO::FETCH_COLUMN) as $pid) $ids[] = (int)$pid;
    } catch (Throwable $e) { /* sem histórico, fica o id atual */ }
    $ids = array_values(array_unique($ids));
    $in = implode(',', array_fill(0, count($ids), '?'));

    $st = $pdo->prepare("SELECT s.id season_id, s.season_number, ps.games, ps.min_pg, ps.pts_pg, ps.reb_pg, ps.ast_pg, ps.stl_pg, ps.blk_pg,
                                (SELECT l.ovr FROM player_season_log l WHERE l.player_id = ps.player_id AND l.season_id = ps.season_id LIMIT 1) ovr_epoca
                           FROM player_season_stats ps
                           JOIN seasons s ON s.id = ps.season_id JOIN sprints sp ON sp.id = s.sprint_id
                          WHERE ps.player_id IN ($in) AND s.league = ? AND sp.status = 'active' AND ps.games > 0
                       ORDER BY s.season_number DESC LIMIT 2");
    $st->execute(array_merge($ids, [$liga]));
    $temps = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$temps) {
        return "{$j['name']} ({$j['time']}) não tem estatística lançada nesta sprint — calouro, recém-chegado ou time que não lançou. Sem base, não há projeção.";
    }

    // Peso: a mais recente 65%, a anterior 35%, e cada uma pelos jogos.
    $cols = ['min_pg' => 'MIN', 'pts_pg' => 'PTS', 'reb_pg' => 'REB', 'ast_pg' => 'AST', 'stl_pg' => 'ROU', 'blk_pg' => 'TOC'];
    $base = []; $pesoTotal = 0; $jogos = 0;
    foreach ($temps as $i => $t) {
        $w = ($i === 0 ? 0.65 : 0.35) * (int)$t['games'];
        $pesoTotal += $w; $jogos += ($i === 0 ? 0.65 : 0.35) * (int)$t['games'];
        foreach ($cols as $c => $_) $base[$c] = ($base[$c] ?? 0) + (float)$t[$c] * $w;
    }
    foreach ($base as $c => $v) $base[$c] = $pesoTotal ? $v / $pesoTotal : 0;
    $pesoJogos = count($temps) === 2 ? 1.0 : 0.65;

    $ovrEpoca = (int)($temps[0]['ovr_epoca'] ?? 0);
    $ajOvr = $ovrEpoca > 0 ? max(0.85, min(1.20, (int)$j['ovr'] / $ovrEpoca)) : 1.0;
    $idade = (int)$j['age'];
    $ajIdade = $idade <= 23 ? 1.05 : ($idade <= 29 ? 1.00 : ($idade <= 32 ? 0.97 : 0.92));

    $linhas = [];
    foreach ($cols as $c => $rot) {
        $proj = $base[$c] * $ajOvr * $ajIdade;
        if ($c === 'min_pg') $proj = min(40.0, $proj);
        // Faixa: metade da diferença entre as duas temporadas, e no mínimo 10%.
        $dif = count($temps) === 2 ? abs((float)$temps[0][$c] - (float)$temps[1][$c]) / 2 * $ajOvr * $ajIdade : 0;
        $meia = max(0.10 * $proj, $dif);
        $linhas[] = "{$rot} " . projNum($proj) . ' (' . projNum(max(0, $proj - $meia)) . '–' . projNum($proj + $meia) . ')';
    }

    $baseTxt = implode(' e ', array_map(fn($t) => 'T' . (int)$t['season_number'] . ' (' . (int)$t['games'] . ' jogos, '
        . projNum((float)$t['pts_pg']) . ' PTS, ' . projNum((float)$t['reb_pg']) . ' REB, ' . projNum((float)$t['ast_pg']) . ' AST)', $temps));

    $l = [];
    $l[] = "PROJEÇÃO PRA PRÓXIMA TEMPORADA — {$j['name']} ({$j['position']}, {$j['time']}, {$j['league']})";
    $l[] = '- Médias projetadas por jogo (faixa provável entre parênteses): ' . implode(' · ', $linhas) . '.';
    $l[] = "- Base: {$baseTxt}" . (count($temps) === 2 ? ' — a mais recente pesa 65%, a anterior 35%, cada uma pelos jogos.' : ' — só uma temporada com número, então a faixa é mais larga na prática.');
    $l[] = '- Ajuste de OVR: ' . ($ovrEpoca > 0 ? "{$ovrEpoca} na época → {$j['ovr']} hoje (×" . projNum($ajOvr, 2) . ')' : 'sem OVR da época no histórico (×1,00)') . '.';
    $l[] = "- Ajuste de idade: {$idade} anos (×" . projNum($ajIdade, 2) . ': até 23 sobe, 24–29 estável, 30–32 cai um pouco, 33+ cai mais).';
    $l[] = 'COMO LER: estimativa a partir do que ele já fez. Não entra minutagem nova, troca, tática nem mudança de papel no elenco. '
         . 'Mostre a base junto do número e fale em faixa — número solto vira boato de grupo.';
    return implode("\n", $l);
}
