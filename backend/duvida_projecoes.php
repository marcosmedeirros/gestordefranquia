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

/**
 * Acha um jogador em elenco pelo nome. Prefere a liga do grupo; ambíguo,
 * devolve as opções em vez de chutar.
 */
function projAcharJogador(PDO $pdo, string $textoJogador, string $ligaGrupo): array
{
    $texto = trim($textoJogador);
    if ($texto === '') return ['erro' => 'Qual jogador? Não veio nome nenhum.'];
    $st = $pdo->prepare("SELECT p.id, p.name, p.age, p.ovr, p.position, p.skill_pot, t.league, TRIM(CONCAT(COALESCE(t.city,''),' ',t.name)) time
                           FROM players p JOIN teams t ON t.id = p.team_id WHERE p.name LIKE ?
                       ORDER BY (t.league = ?) DESC, (p.name = ?) DESC, p.ovr DESC LIMIT 6");
    $st->execute(['%' . $texto . '%', strtoupper($ligaGrupo), $texto]);
    $achados = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$achados) return ['erro' => "Não achei jogador em elenco com \"{$texto}\"."];
    $exatos = array_values(array_filter($achados, fn($j) => mb_strtolower($j['name']) === mb_strtolower($texto)));
    // Nome exato repetido entre ligas (há um Kobe Bryant na ELITE e outro na
    // ROOKIE): vale o da liga do grupo, que é de quem se está falando.
    $exatosDaLiga = array_values(array_filter($exatos, fn($x) => strtoupper($x['league']) === strtoupper($ligaGrupo)));
    if (count($exatos) > 1 && count($exatosDaLiga) === 1) $exatos = $exatosDaLiga;
    if (count($exatos) === 1) return ['jogador' => $exatos[0]];
    if (count($achados) === 1) return ['jogador' => $achados[0]];
    return ['erro' => "\"{$texto}\" pode ser: " . implode(', ', array_map(fn($x) => "{$x['name']} ({$x['time']}, {$x['league']})", $achados)) . '. Pergunte qual.'];
}

/** Médias projetadas de um jogador pra próxima temporada, pronto pro bot. */
/**
 * O CÁLCULO DA PROJEÇÃO DE UM JOGADOR, sem texto nenhum.
 *
 * Saiu de dentro de projJogadorTexto() quando a projeção do QUINTETO nasceu:
 * a conta é a mesma para um jogador e para os cinco, e copiá-la seria garantir
 * que um dia a ficha individual e a tabela do time discordassem sobre o mesmo
 * jogador.
 *
 * @return array{temps:array, proj:array, meia:array, ajOvr:float, ajIdade:float, ovrEpoca:int}|null
 *         null quando não há estatística lançada — calouro, recém-chegado ou
 *         time que não lançou.
 */
function projCalculoDoJogador(PDO $pdo, array $j): ?array
{
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
    if (!$temps) return null;

    // Peso: a mais recente 65%, a anterior 35%, e cada uma pelos jogos.
    $cols = ['min_pg', 'pts_pg', 'reb_pg', 'ast_pg', 'stl_pg', 'blk_pg'];
    $base = []; $pesoTotal = 0;
    foreach ($temps as $i => $t) {
        $w = ($i === 0 ? 0.65 : 0.35) * (int)$t['games'];
        $pesoTotal += $w;
        foreach ($cols as $c) $base[$c] = ($base[$c] ?? 0) + (float)$t[$c] * $w;
    }
    foreach ($base as $c => $v) $base[$c] = $pesoTotal ? $v / $pesoTotal : 0;

    $ovrEpoca = (int)($temps[0]['ovr_epoca'] ?? 0);
    $ajOvr = $ovrEpoca > 0 ? max(0.85, min(1.20, (int)$j['ovr'] / $ovrEpoca)) : 1.0;
    $idade = (int)$j['age'];
    $ajIdade = $idade <= 23 ? 1.05 : ($idade <= 29 ? 1.00 : ($idade <= 32 ? 0.97 : 0.92));

    $proj = []; $meia = [];
    foreach ($cols as $c) {
        $v = $base[$c] * $ajOvr * $ajIdade;
        if ($c === 'min_pg') $v = min(40.0, $v);
        $proj[$c] = $v;
        // Faixa: metade da diferença entre as duas temporadas, e no mínimo 10%.
        $dif = count($temps) === 2 ? abs((float)$temps[0][$c] - (float)$temps[1][$c]) / 2 * $ajOvr * $ajIdade : 0;
        $meia[$c] = max(0.10 * $v, $dif);
    }

    return ['temps' => $temps, 'proj' => $proj, 'meia' => $meia,
            'ajOvr' => $ajOvr, 'ajIdade' => $ajIdade, 'ovrEpoca' => $ovrEpoca];
}

/**
 * A PROJEÇÃO DO QUINTETO INTEIRO — jogador, pontos, rebotes e assistências.
 *
 * É o pedido que chegava no grupo e que o bot não conseguia atender: ele
 * tentava descobrir sozinho quem eram os titulares, escrevia uma consulta e
 * voltava com "não encontrei jogadores listados como titulares", num time que
 * tem os cinco marcados. Adivinhar esquema por SQL improvisado é o caminho
 * mais longo pra pergunta mais comum.
 *
 * Quem não tem estatística lançada (calouro, recém-chegado) entra na lista com
 * o motivo, e não some: um quinteto com quatro linhas faz o leitor procurar o
 * quinto.
 *
 * A conta de cada jogador é a MESMA da projeção individual — as duas chamam
 * projCalculoDoJogador().
 */
function projQuintetoTexto(PDO $pdo, string $textoTime, string $ligaGrupo): string
{
    $achado = projAcharTime($pdo, $textoTime, $ligaGrupo);
    if (isset($achado['erro'])) return $achado['erro'];
    $t = $achado['time'] ?? $achado;
    $teamId = (int)($t['id'] ?? 0);
    $nomeTime = trim(((string)($t['city'] ?? '')) . ' ' . ((string)($t['name'] ?? '')));
    $liga = strtoupper((string)($t['league'] ?? $ligaGrupo));
    if (!$teamId) return "Não achei o time \"{$textoTime}\".";

    /* OS TITULARES MARCADOS, na ordem da quadra. Sem nenhum marcado, os cinco
       melhores por OVR — e a resposta diz isso, porque projetar "o quinteto"
       de um time que não montou quinteto é outra coisa. */
    $st = $pdo->prepare("SELECT id, name, age, ovr, position, role
                           FROM players WHERE team_id = ? AND role = 'Titular'
                       ORDER BY FIELD(UPPER(TRIM(position)),'PG','SG','SF','PF','C'), ovr DESC");
    $st->execute([$teamId]);
    $quinteto = $st->fetchAll(PDO::FETCH_ASSOC);

    $porOvr = false;
    if (!$quinteto) {
        $porOvr = true;
        $st = $pdo->prepare("SELECT id, name, age, ovr, position, role FROM players
                              WHERE team_id = ? ORDER BY ovr DESC LIMIT 5");
        $st->execute([$teamId]);
        $quinteto = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$quinteto) return "O {$nomeTime} não tem jogadores no elenco.";

    $linhas = [];
    $semBase = 0;
    foreach ($quinteto as $j) {
        $j['league'] = $liga;
        $calc = projCalculoDoJogador($pdo, $j);
        $pos = strtoupper(trim((string)$j['position']));

        if ($calc === null) {
            $semBase++;
            $linhas[] = "- {$j['name']} ({$pos}, {$j['ovr']} OVR): sem estatística lançada nesta sprint — não dá pra projetar.";
            continue;
        }

        $base = implode(' e ', array_map(fn($x) => 'T' . (int)$x['season_number']
            . ' (' . projNum((float)$x['pts_pg']) . '/' . projNum((float)$x['reb_pg']) . '/' . projNum((float)$x['ast_pg']) . ')',
            $calc['temps']));

        $linhas[] = "- {$j['name']} ({$pos}, {$j['ovr']} OVR): "
            . projNum($calc['proj']['pts_pg']) . ' / '
            . projNum($calc['proj']['reb_pg']) . ' / '
            . projNum($calc['proj']['ast_pg'])
            . ' — ' . projNum($calc['proj']['min_pg']) . ' min · base: ' . $base;
    }

    $l = [];
    $l[] = "PROJEÇÃO DO QUINTETO — {$nomeTime} ({$liga})";
    $l[] = 'Formato de cada linha: jogador (posição, OVR): PONTOS / REBOTES / ASSISTÊNCIAS por jogo.';
    if ($porOvr) $l[] = 'ATENÇÃO: este time não tem titulares marcados — são os 5 maiores OVR do elenco. Diga isso na resposta.';
    $l = array_merge($l, $linhas);
    if ($semBase) $l[] = "{$semBase} jogador(es) sem base: diga o motivo em vez de inventar número.";
    $l[] = 'A conta é a mesma da projeção individual: as duas últimas temporadas com jogo (a mais recente pesa 65%), '
         . 'ajustada pelo OVR de hoje contra o da época e pela idade.';
    $l[] = 'COMO LER: é estimativa do que já foi feito. Não entra minutagem nova, troca, tática nem mudança de papel. '
         . 'Responda no formato que pediram — "Nome 25/7/10" é o que a liga usa.';
    return implode("\n", $l);
}

function projJogadorTexto(PDO $pdo, string $textoJogador, string $ligaGrupo): string
{
    $achado = projAcharJogador($pdo, $textoJogador, $ligaGrupo);
    if (isset($achado['erro'])) return $achado['erro'];
    $j = $achado['jogador'];

    $liga = strtoupper((string)$j['league']);

    $calc = projCalculoDoJogador($pdo, $j);
    if ($calc === null) {
        return "{$j['name']} ({$j['time']}) não tem estatística lançada nesta sprint — calouro, recém-chegado ou time que não lançou. Sem base, não há projeção.";
    }
    $temps = $calc['temps'];
    $ovrEpoca = $calc['ovrEpoca'];
    $ajOvr = $calc['ajOvr'];
    $ajIdade = $calc['ajIdade'];
    $idade = (int)$j['age'];

    $cols = ['min_pg' => 'MIN', 'pts_pg' => 'PTS', 'reb_pg' => 'REB', 'ast_pg' => 'AST', 'stl_pg' => 'ROU', 'blk_pg' => 'TOC'];
    $linhas = [];
    foreach ($cols as $c => $rot) {
        $proj = $calc['proj'][$c];
        $meia = $calc['meia'][$c];
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

/* ══════════════════════════════════════════════════════════════════════════
   O FUTURO: os playoffs de agora, os próximos campeões, o ranking daqui a N
   temporadas, o jogador daqui a N temporadas e a loteria.

   Pedido do dono da liga (15/09/2026): "algo bem livre, que ele chute mesmo".
   O chute continua saindo DAQUI, com a base do lado. Solto, o modelo cravaria
   o time de quem perguntou; com o número pronto, ele só escolhe como contar.
   ══════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/pontuacao_ranking.php';
require_once __DIR__ . '/loteria_grupos.php';

/**
 * QUANTO O OVR ANDA DE UMA TEMPORADA PRA OUTRA, PELA IDADE: [média, desvio].
 *
 * Medido na FBA em 15/09/2026 — 22 mil pares de temporadas seguidas do mesmo
 * jogador, na mesma liga e sprint (player_season_log). As quatro ligas andam
 * juntas (nenhuma faixa de idade se afasta mais de meio ponto entre elas), então
 * a curva é uma só.
 *
 * Os 18 anos deram +9 com desvio 8: é o calouro que chega com OVR de draft e dá
 * o salto. Pra quem já está em elenco vale a régua dos 19. De 38 em diante
 * sobram poucos casos, e vale a dos 37.
 */
const PROJ_CURVA_IDADE = [
    19 => [3.4, 2.4], 20 => [2.7, 2.1], 21 => [2.2, 2.0], 22 => [1.6, 1.8], 23 => [1.0, 1.6],
    24 => [0.3, 1.2], 25 => [0.0, 1.0], 26 => [-0.2, 0.8], 27 => [-0.3, 0.9], 28 => [-0.5, 1.1],
    29 => [-0.6, 1.2], 30 => [-1.1, 1.4], 31 => [-1.5, 1.6], 32 => [-1.8, 1.5], 33 => [-1.7, 1.7],
    34 => [-1.6, 1.6], 35 => [-1.7, 2.2], 36 => [-1.8, 2.0], 37 => [-1.8, 1.5],
];

/**
 * Até os 24 anos o potencial muda a subida. Na mesma medição, quem tem A+ subiu
 * 2,4 por ano, a média 1,55 e o C 0,6: o número é a distância até a média.
 */
const PROJ_BONUS_POTENCIAL = [
    'A+' => 0.9, 'A' => 0.8, 'A-' => 0.5, 'B+' => -0.1, 'B' => -0.5, 'B-' => -0.5,
    'C+' => -0.9, 'C' => -1.0, 'C-' => -1.0,
];

/**
 * O QUANTO UM ELENCO FOGE DO PREVISTO a cada temporada à frente, em desvios de
 * força: troca, draft, dispensa, leilão. É chute declarado, não medição — só o
 * envelhecimento faria o time mais forte de hoje favorito pra sempre, e a FBA
 * troca demais pra isso ser verdade.
 */
const PROJ_MUDANCA_POR_TEMPORADA = 0.5;

const PROJ_MAX_TEMPORADAS = 10;
const PROJ_SIMULACOES_CHAVE = 10000;
const PROJ_SIMULACOES_RANKING = 2000;
const PROJ_SIMULACOES_LOTERIA = 400;

/** A liga pedida, ou a do grupo; null quando nenhuma das duas é liga. */
function projLiga(string $pedida, string $ligaGrupo): ?string
{
    foreach ([$pedida, $ligaGrupo] as $l) {
        $l = strtoupper(trim($l));
        if (in_array($l, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) return $l;
    }
    return null;
}

/** Os nomes dos times da liga: [id => "Cidade Nome"]. */
function projNomesDaLiga(PDO $pdo, string $liga): array
{
    $st = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(city,''),' ',name)) nome FROM teams WHERE league = ?");
    $st->execute([strtoupper($liga)]);
    $nomes = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $nomes[(int)$r['id']] = (string)$r['nome'];
    return $nomes;
}

/** As funções de temporada e chaveamento moram no arquivo de comandos do bot. */
function projComandosDoBot(): void
{
    if (!function_exists('wcTemporadaAtiva')) require_once __DIR__ . '/../api/whatsapp-comandos.php';
}

function projQuantil(array $ordenados, float $q): float
{
    return (float)$ordenados[(int)floor($q * (count($ordenados) - 1))];
}

/**
 * Um passo da curva: quanto o OVR anda no ano em que o jogador tem esta idade.
 *
 * PERTO DO TETO A SUBIDA FREIA. Na mesma medição, jovem (até 24) com 90–92
 * ainda subiu 1,4 por ano; com 93–95, 0,6; com 96 ou mais, nada. Sem o freio,
 * o LeBron de 22 anos e 96 batia 99 na temporada seguinte. Na queda é o
 * contrário e mais suave: de 93 pra cima o veterano cai ~0,7, e não 1,3.
 */
function projPassoIdade(int $idade, ?string $potencial, float $ovr = 80.0): array
{
    // Idade vazia (cadastro sem idade) fica no platô, sem subir nem cair.
    [$media, $dp] = PROJ_CURVA_IDADE[$idade <= 0 ? 25 : max(19, min(37, $idade))];
    if ($idade > 0 && $idade <= 24) $media += PROJ_BONUS_POTENCIAL[strtoupper(trim((string)$potencial))] ?? 0.0;
    if ($media > 0)      $media *= max(0.0, min(1.0, (96 - $ovr) / 5));
    elseif ($ovr >= 93)  $media *= 0.6;
    return [$media, $dp];
}

/** O OVR esperado daqui a $anos temporadas, só pela curva (sem acaso). */
function projOvrEnvelhecido(int $ovr, int $idade, ?string $potencial, int $anos): float
{
    $v = (float)$ovr;
    for ($k = 0; $k < $anos; $k++) $v += projPassoIdade($idade > 0 ? $idade + $k : 0, $potencial, $v)[0];
    return max(40.0, min(99.0, $v));
}

/**
 * Os times da liga com a força de cada temporada à frente: o elenco de hoje
 * envelhecido pela curva. 'z'[k] é a força padronizada na temporada k (0 = a de
 * agora), medida contra a liga daquela mesma temporada.
 */
function projLigaNoFuturo(PDO $pdo, string $liga, int $anosMax): array
{
    $st = $pdo->prepare("SELECT t.id, t.city, t.name, t.conference, p.ovr, p.age, p.skill_pot
                           FROM teams t JOIN players p ON p.team_id = t.id
                          WHERE t.league = ?");
    $st->execute([strtoupper($liga)]);
    $times = []; $elencos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int)$r['id'];
        $times[$id] ??= ['id' => $id, 'nome' => trim(($r['city'] ?? '') . ' ' . $r['name']), 'conf' => $r['conference'] ?: 'Liga'];
        $elencos[$id][] = $r;
    }
    if (!$times) return [];

    foreach ($times as $id => &$t) {
        for ($k = 0; $k <= $anosMax; $k++) {
            $ovrs = array_map(fn($j) => projOvrEnvelhecido((int)$j['ovr'], (int)$j['age'], $j['skill_pot'], $k), $elencos[$id]);
            rsort($ovrs);
            $soma = 0.0; $pesos = 0.0;
            foreach (array_slice($ovrs, 0, 8) as $i => $o) { $p = projPeso($i); $soma += $o * $p; $pesos += $p; }
            $t['forca'][$k] = $pesos ? $soma / $pesos : 0.0;
        }
    }
    unset($t);

    for ($k = 0; $k <= $anosMax; $k++) {
        $vals = array_map(fn($t) => $t['forca'][$k], $times);
        $media = array_sum($vals) / count($vals);
        $dp = sqrt(array_sum(array_map(fn($v) => ($v - $media) ** 2, $vals)) / count($vals)) ?: 1.0;
        foreach ($times as &$t) $t['z'][$k] = ($t['forca'][$k] - $media) / $dp;
        unset($t);
    }
    return $times;
}

/** A força de cada time numa temporada sorteada: o previsto pela idade mais o sorteio de mudança de elenco. */
function projForcasSorteadas(array $times, int $k): array
{
    $desvio = PROJ_MUDANCA_POR_TEMPORADA * sqrt($k);
    $z = [];
    foreach ($times as $id => $t) $z[$id] = $t['z'][$k] + ($desvio > 0 ? $desvio * projGauss() : 0.0);
    return $z;
}

/**
 * UMA temporada sorteada, com a mesma régua de projSimularLiga: a posição de
 * cada time na conferência e a etapa onde ele parou nos playoffs, já com os
 * nomes de PONTOS_PLAYOFF — é o que o ranking soma.
 *
 * @return array [team_id => ['pos' => int, 'etapa' => string|null]]
 */
function projSortearTemporada(array $times, array $z): array
{
    $confs = [];
    foreach ($times as $id => $t) $confs[$t['conf']][] = $id;
    $serie = fn(int $a, int $b): int => (mt_rand() / mt_getrandmax()) < projChanceSerie($z[$a], $z[$b]) ? $a : $b;

    $r = []; $campeoesConf = [];
    foreach ($confs as $ids) {
        $placar = [];
        foreach ($ids as $id) $placar[$id] = $z[$id] + PROJ_ACASO * projGauss();
        arsort($placar);
        $ordem = array_keys($placar);
        foreach ($ordem as $i => $id) $r[$id] = ['pos' => $i + 1, 'etapa' => null];
        if (count($ordem) < PROJ_VAGAS_PLAYOFF) {
            $campeoesConf[] = $ordem[0];
            continue;
        }
        $s = array_slice($ordem, 0, PROJ_VAGAS_PLAYOFF);
        foreach ($s as $id) $r[$id]['etapa'] = 'first_round';
        // Chave de 8: o 1x8 encontra o 4x5; o 3x6 encontra o 2x7.
        $semi = [$serie($s[0], $s[7]), $serie($s[3], $s[4]), $serie($s[2], $s[5]), $serie($s[1], $s[6])];
        foreach ($semi as $id) $r[$id]['etapa'] = 'second_round';
        $finalConf = [$serie($semi[0], $semi[1]), $serie($semi[2], $semi[3])];
        foreach ($finalConf as $id) $r[$id]['etapa'] = 'conference_final';
        $campeoesConf[] = $serie($finalConf[0], $finalConf[1]);
    }
    if (count($campeoesConf) >= 2) {
        $campeao = $serie($campeoesConf[0], $campeoesConf[1]);
        foreach (array_slice($campeoesConf, 0, 2) as $id) $r[$id]['etapa'] = $id === $campeao ? 'champion' : 'runner_up';
    } elseif ($campeoesConf) {
        $r[$campeoesConf[0]]['etapa'] = 'champion';
    }
    return $r;
}

/** Os pontos de ranking de uma temporada sorteada, com a régua oficial. */
function projPontosDaTemporada(array $temporada): array
{
    $p = [];
    foreach ($temporada as $id => $t) {
        $p[$id] = pontosPorPosicao($t['pos']) + ($t['etapa'] ? pontosDePlayoff($t['etapa']) : 0);
    }
    return $p;
}

/* ── Os playoffs de agora ──────────────────────────────────────────────── */

/**
 * O CHAVEAMENTO DE AGORA, das mesmas três fontes do /playoffs e na mesma
 * ordem: o rascunho do admin, as séries registradas e, sem nenhum dos dois, os
 * confrontos de abertura pela classificação. Duas respostas diferentes pra
 * "quem está nos playoffs" — uma no comando, outra na projeção — seriam piores
 * que nenhuma.
 *
 * @return array|null ['temporada' => linha de seasons, 'chave' => array, 'fonte' => string]
 */
function projChaveAtual(PDO $pdo, string $liga): ?array
{
    projComandosDoBot();
    $temp = wcTemporadaAtiva($pdo, $liga);
    if (!$temp) return null;
    $sid = (int)$temp['id'];

    $chave = null; $fonte = '';
    try {
        $st = $pdo->prepare('SELECT dados FROM season_registro_rascunho WHERE season_id = ?');
        $st->execute([$sid]);
        $d = json_decode((string)$st->fetchColumn(), true);
        if (is_array($d) && !empty($d['bracket'])) {
            $chave = $d['bracket'];
            $fonte = 'o chaveamento que o admin está preenchendo';
        }
    } catch (Throwable $e) { /* sem a tabela do rascunho: segue pras outras fontes */ }
    if (!$chave && ($chave = wcChaveDasSeries($pdo, $sid))) $fonte = 'as séries já registradas';
    if (!$chave && ($chave = wcChaveDaClassificacao($pdo, $sid))) $fonte = 'a classificação lançada (confrontos de abertura pelas seeds)';
    return $chave ? ['temporada' => $temp, 'chave' => $chave, 'fonte' => $fonte] : null;
}

/** As séries com vencedor marcado, pelo par de times: ["menor-maior" => ['w' => id, 'g' => jogos]]. */
function projSeriesDecididas(array $chave): array
{
    $todas = [];
    foreach (['leste', 'oeste'] as $lado) {
        foreach (['r1', 'r2'] as $rodada) foreach ((array)($chave[$lado][$rodada] ?? []) as $m) $todas[] = $m;
        $todas[] = $chave[$lado]['cf'] ?? null;
    }
    $todas[] = $chave['final'] ?? null;

    $d = [];
    foreach ($todas as $m) {
        if (!is_array($m)) continue;
        $a = (int)($m['t1']['id'] ?? 0); $b = (int)($m['t2']['id'] ?? 0); $w = (int)($m['w'] ?? 0);
        if ($a > 0 && $b > 0 && ($w === $a || $w === $b)) {
            $d[min($a, $b) . '-' . max($a, $b)] = ['w' => $w, 'g' => (int)($m['g'] ?? 0)];
        }
    }
    return $d;
}

/**
 * Roda a chave uma vez. Série já decidida fica como foi; em aberto, sorteia com
 * $serie. Com $serie nulo não sorteia nada: devolve só o que já é FATO e as
 * séries sendo jogadas agora (os dois lados definidos, sem vencedor).
 *
 * O vencedor marcado é procurado pelo PAR de times, e não pela posição na
 * chave: nas séries registradas a 2ª rodada vem na ordem de gravação, e casar
 * por índice tomaria uma série decidida por aberta.
 *
 * @return array ['etapa' => [id => etapa], 'final' => [a, b], 'campeao' => id, 'abertas' => [[a, b, fase]]]
 */
function projRodarChave(array $chave, ?callable $serie, ?array $decididas = null): array
{
    $decididas ??= projSeriesDecididas($chave);
    $etapa = []; $abertas = [];
    $jogar = function (int $a, int $b, string $fase, ?string $etapaDoVencedor) use (&$etapa, &$abertas, $decididas, $serie): int {
        if ($a <= 0 || $b <= 0) return 0;   // um dos lados ainda não saiu da rodada anterior
        $w = $decididas[min($a, $b) . '-' . max($a, $b)]['w'] ?? 0;
        if (!$w) {
            if (!$serie) { $abertas[] = [$a, $b, $fase]; return 0; }
            $w = $serie($a, $b);
        }
        if ($etapaDoVencedor) $etapa[$w] = $etapaDoVencedor;
        return $w;
    };

    $finalistas = [];
    foreach (['leste' => 'Leste', 'oeste' => 'Oeste'] as $lado => $rotulo) {
        $vivos = [];
        foreach (array_values((array)($chave[$lado]['r1'] ?? [])) as $m) {
            $a = (int)($m['t1']['id'] ?? 0); $b = (int)($m['t2']['id'] ?? 0);
            if ($a <= 0 && $b <= 0) continue;
            foreach ([$a, $b] as $id) if ($id > 0) $etapa[$id] = 'first_round';
            $vivos[] = $jogar($a, $b, "1ª rodada do {$rotulo}", 'second_round');
        }
        $semi = [];
        for ($i = 0; $i + 1 < count($vivos); $i += 2) {
            $semi[] = $jogar($vivos[$i], $vivos[$i + 1], "semifinal do {$rotulo}", 'conference_final');
        }
        if (count($semi) >= 2)      $finalistas[] = $jogar($semi[0], $semi[1], "final do {$rotulo}", null);
        elseif (count($semi) === 1) $finalistas[] = $semi[0];
    }

    $campeao = 0; $final = [];
    if (count($finalistas) === 2 && $finalistas[0] > 0 && $finalistas[1] > 0) {
        $final = $finalistas;
        $campeao = $jogar($finalistas[0], $finalistas[1], 'grande final', null);
        if ($campeao) foreach ($finalistas as $id) $etapa[$id] = $id === $campeao ? 'champion' : 'runner_up';
    } elseif (count($finalistas) === 1 && $finalistas[0] > 0) {
        $campeao = $finalistas[0];
        $etapa[$campeao] = 'champion';
    }
    return ['etapa' => $etapa, 'final' => $final, 'campeao' => $campeao, 'abertas' => $abertas];
}

/**
 * A chave simulada N vezes a partir de agora.
 *
 * @return array ['cont' => [id => [second_round, conference_final, final, champion]], 'finais' => ["a-b" => n], 'chance' => callable]
 */
function projChancesDaChave(array $chave, array $z, string $semente, int $N = PROJ_SIMULACOES_CHAVE): array
{
    $cache = [];
    $chance = function (int $a, int $b) use (&$cache, $z): float {
        return $cache["{$a}-{$b}"] ??= projChanceSerie($z[$a] ?? 0.0, $z[$b] ?? 0.0);
    };
    mt_srand(crc32($semente));
    $serie = fn(int $a, int $b): int => (mt_rand() / mt_getrandmax()) < $chance($a, $b) ? $a : $b;
    $decididas = projSeriesDecididas($chave);

    $degrau = ['first_round' => 0, 'second_round' => 1, 'conference_final' => 2, 'runner_up' => 3, 'champion' => 4];
    $cont = []; $finais = [];
    for ($n = 0; $n < $N; $n++) {
        $r = projRodarChave($chave, $serie, $decididas);
        foreach ($r['etapa'] as $id => $e) {
            $cont[$id] ??= ['second_round' => 0, 'conference_final' => 0, 'final' => 0, 'champion' => 0];
            $d = $degrau[$e] ?? 0;
            if ($d >= 1) $cont[$id]['second_round']++;
            if ($d >= 2) $cont[$id]['conference_final']++;
            if ($d >= 3) $cont[$id]['final']++;
            if ($d >= 4) $cont[$id]['champion']++;
        }
        if ($r['final']) {
            $par = $r['final']; sort($par);
            $k = implode('-', $par);
            $finais[$k] = ($finais[$k] ?? 0) + 1;
        }
    }
    return ['cont' => $cont, 'finais' => $finais, 'chance' => $chance, 'N' => $N];
}

/** Os playoffs de agora, série a série e até o título, pronto pro bot. */
function projPlayoffsTexto(PDO $pdo, string $ligaPedida, string $ligaGrupo): string
{
    $liga = projLiga($ligaPedida, $ligaGrupo);
    if (!$liga) return 'Qual liga? ELITE, NEXT, RISE ou ROOKIE.';
    $ch = projChaveAtual($pdo, $liga);
    if (!$ch) {
        return "A {$liga} NÃO está nos playoffs agora: a temporada em curso ainda não tem classificação lançada, "
             . 'então não há chave. Pra palpite de quem ganha a temporada, use projetar_campeoes.';
    }
    $chave = $ch['chave'];
    $T = 'T' . (int)$ch['temporada']['season_number'];
    $nomes = projNomesDaLiga($pdo, $liga);
    $nome = fn(int $id): string => $nomes[$id] ?? 'time que saiu da liga';

    $seed = []; $confDe = [];
    foreach (['leste' => 'Leste', 'oeste' => 'Oeste'] as $lado => $rotulo) {
        foreach ((array)($chave[$lado]['r1'] ?? []) as $m) {
            foreach ([['t1', 's1'], ['t2', 's2']] as [$t, $s]) {
                $id = (int)($m[$t]['id'] ?? 0);
                if ($id <= 0) continue;
                $seed[$id] = isset($m[$s]) ? (int)$m[$s] : null;
                $confDe[$id] = $rotulo;
            }
        }
    }
    $comSeed = fn(int $id): string => (isset($seed[$id]) ? "({$seed[$id]}) " : '') . $nome($id);

    $decididas = projSeriesDecididas($chave);
    $fato = projRodarChave($chave, null, $decididas);
    $eliminados = [];
    foreach ($decididas as $par => $d) {
        foreach (array_map('intval', explode('-', $par)) as $id) if ($id !== $d['w']) $eliminados[$id] = true;
    }

    $l = [];
    if ($fato['campeao']) {
        $l[] = "PLAYOFFS DA {$liga} ({$T}) — JÁ ACABARAM. Fonte: {$ch['fonte']}.";
        $vice = array_values(array_diff($fato['final'], [$fato['campeao']]))[0] ?? 0;
        $l[] = '- Campeão: ' . $nome($fato['campeao']) . ($vice ? ', em cima do ' . $nome($vice) : '') . '.';
        $l[] = 'Não há o que projetar nesta temporada. Pra próxima, use projetar_campeoes.';
        return implode("\n", $l);
    }

    $times = projTimesDaLiga($pdo, $liga);
    $z = [];
    foreach ($seed as $id => $_) $z[$id] = $times[$id]['z'] ?? 0.0;
    $sim = projChancesDaChave($chave, $z, $liga . '|playoffs|' . date('Y-m-d'));
    $N = $sim['N'];

    $l[] = "PLAYOFFS DA {$liga} ({$T}) — EM ANDAMENTO. Fonte da chave: {$ch['fonte']}. "
         . "{$N} simulações do que falta, pela força do elenco de hoje.";

    if ($decididas) {
        $txt = [];
        foreach ($decididas as $par => $d) {
            [$a, $b] = array_map('intval', explode('-', $par));
            $placar = $d['g'] >= 4 && $d['g'] <= 7 ? ' (4-' . ($d['g'] - 4) . ')' : '';
            $txt[] = $nome($d['w']) . ' passou por ' . $nome($d['w'] === $a ? $b : $a) . $placar;
        }
        $l[] = '- Séries já decididas: ' . implode('; ', $txt) . '.';
    } else {
        $l[] = '- Nenhuma série decidida ainda.';
    }

    if ($fato['abertas']) {
        $txt = [];
        foreach ($fato['abertas'] as [$a, $b, $fase]) {
            $p = ($sim['chance'])($a, $b);
            $placares = projSerie(projJogoDaSerie($p))['placares'];
            arsort($placares);
            [$ga, $gb] = array_map('intval', explode('-', (string)array_key_first($placares)));
            $txt[] = "{$fase}: " . $comSeed($a) . ' ' . projPct($p) . ' x ' . projPct(1 - $p) . ' ' . $comSeed($b)
                   . ', placar mais provável 4-' . ($ga === 4 ? $gb : $ga) . ' pro ' . $nome($ga === 4 ? $a : $b);
        }
        $l[] = '- Séries sendo jogadas agora (chance de passar): ' . implode(' · ', $txt) . '.';
    }

    foreach (['Leste', 'Oeste'] as $rotulo) {
        $ids = array_keys(array_filter($confDe, fn($c) => $c === $rotulo));
        if (!$ids) continue;
        usort($ids, fn($x, $y) => ($seed[$x] ?? 99) <=> ($seed[$y] ?? 99));
        $txt = [];
        foreach ($ids as $id) {
            if (isset($eliminados[$id])) { $txt[] = $comSeed($id) . ': eliminado'; continue; }
            $c = $sim['cont'][$id] ?? ['second_round' => 0, 'conference_final' => 0, 'final' => 0, 'champion' => 0];
            $txt[] = $comSeed($id) . ': ' . projPct($c['second_round'] / $N) . ' / ' . projPct($c['conference_final'] / $N)
                   . ' / ' . projPct($c['final'] / $N) . ' / ' . projPct($c['champion'] / $N);
        }
        $l[] = "- {$rotulo} (passar da 1ª rodada / chegar à final de conferência / chegar à final / título): " . implode(' · ', $txt) . '.';
    }

    $titulos = array_map(fn($c) => $c['champion'], $sim['cont']);
    arsort($titulos);
    $fav = (int)array_key_first($titulos);
    $l[] = '- Favoritos ao título: ' . implode(', ', array_map(fn($id) => $nome((int)$id) . ' ' . projPct($titulos[$id] / $N),
        array_slice(array_keys($titulos), 0, 5))) . '.';
    $finais = $sim['finais'];
    arsort($finais);
    $fm = array_key_first($finais);
    $l[] = '- Palpite do modelo: campeão ' . $nome($fav) . ' (' . projPct($titulos[$fav] / $N) . ')'
         . ($fm !== null ? '; final mais provável ' . implode(' x ', array_map(fn($id) => $nome((int)$id), explode('-', (string)$fm)))
            . ' (' . projPct($finais[$fm] / $N) . ')' : '') . '.';
    $l[] = 'COMO LER: chance pela força do elenco de hoje (OVR ponderado dos 8 melhores), a mesma régua do projetar_confronto. '
         . 'Série decidida fica travada; série em andamento conta do zero, porque o sistema guarda quem passou e não o placar parcial. '
         . 'Mando de quadra e tática não entram. Pediram palpite? Crave o nome, com a chance do lado.';
    return implode("\n", $l);
}

/* ── Próximos campeões ─────────────────────────────────────────────────── */

/** A temporada já tem classificação lançada (a regular acabou)? */
function projTemClassificacao(PDO $pdo, int $seasonId): bool
{
    if ($seasonId <= 0) return false;
    $st = $pdo->prepare('SELECT COUNT(*) FROM season_standings WHERE season_id = ? AND COALESCE(position, 0) > 0');
    $st->execute([$seasonId]);
    return (int)$st->fetchColumn() > 0;
}

/** Os campeões das próximas temporadas, um palpite por temporada, pronto pro bot. */
function projCampeoesTexto(PDO $pdo, string $ligaPedida, int $temporadas, string $ligaGrupo): string
{
    $liga = projLiga($ligaPedida, $ligaGrupo);
    if (!$liga) return 'Qual liga? ELITE, NEXT, RISE ou ROOKIE.';
    $temporadas = max(1, min(5, $temporadas ?: 3));
    projComandosDoBot();
    $temp = wcTemporadaAtiva($pdo, $liga);
    if (!$temp) return "A {$liga} não tem temporada cadastrada.";
    $n = (int)$temp['season_number'];

    // Com a regular da temporada atual encerrada, o título dela sai da chave, e
    // as projeções começam na seguinte (elenco um ano mais velho).
    $ch = projChaveAtual($pdo, $liga);
    $regularAcabou = $ch !== null || projTemClassificacao($pdo, (int)$temp['id']);
    $inicio = $regularAcabou ? 1 : 0;
    $ultimo = $inicio + $temporadas - 1;
    $times = projLigaNoFuturo($pdo, $liga, $ultimo);
    if (!$times) return "A {$liga} está sem elencos — não dá pra projetar.";
    $N = PROJ_SIMULACOES;

    $l = ["PRÓXIMOS CAMPEÕES — {$liga}. Base: elencos de hoje, envelhecidos pela curva de idade medida na FBA; {$N} temporadas simuladas por ano."];

    if ($ch) {
        $decididas = projSeriesDecididas($ch['chave']);
        $fato = projRodarChave($ch['chave'], null, $decididas);
        if ($fato['campeao']) {
            $l[] = "- T{$n}: já decidida — campeão " . ($times[$fato['campeao']]['nome'] ?? '?') . '.';
        } else {
            $z = array_map(fn($t) => $t['z'][0], $times);
            $sim = projChancesDaChave($ch['chave'], $z, $liga . '|playoffs|' . date('Y-m-d'));
            $titulos = array_map(fn($c) => $c['champion'], $sim['cont']);
            arsort($titulos);
            $top = array_slice($titulos, 0, 4, true);
            $fav = (int)array_key_first($top);
            $l[] = "- T{$n} (PLAYOFFS EM ANDAMENTO, na chave de agora): palpite " . ($times[$fav]['nome'] ?? '?') . ' (' . projPct($top[$fav] / $sim['N'])
                 . '). Favoritos: ' . implode(', ', array_map(fn($id) => ($times[$id]['nome'] ?? '?') . ' ' . projPct($top[$id] / $sim['N']), array_keys($top))) . '.';
        }
    } elseif ($regularAcabou) {
        $l[] = "- T{$n}: regular encerrada, mas a chave dos playoffs não está montada — sem palpite pra ela.";
    }

    mt_srand(crc32($liga . '|campeoes|' . date('Y-m-d')));
    for ($k = $inicio; $k <= $ultimo; $k++) {
        $titulos = [];
        for ($s = 0; $s < $N; $s++) {
            foreach (projSortearTemporada($times, projForcasSorteadas($times, $k)) as $id => $x) {
                if ($x['etapa'] === 'champion') $titulos[$id] = ($titulos[$id] ?? 0) + 1;
            }
        }
        arsort($titulos);
        $top = array_slice($titulos, 0, 4, true);
        $fav = (int)array_key_first($top);
        $l[] = '- T' . ($n + $k) . ($k === 0 ? ' (a atual)' : '') . ': palpite ' . $times[$fav]['nome'] . ' (' . projPct($top[$fav] / $N)
             . '). Favoritos: ' . implode(', ', array_map(fn($id) => $times[$id]['nome'] . ' ' . projPct($top[$id] / $N), array_keys($top))) . '.';
    }

    // Quem o tempo favorece: a força padronizada no começo e no fim da janela.
    if ($ultimo > $inicio || $inicio > 0) {
        $delta = array_map(fn($t) => $t['z'][$ultimo] - $t['z'][0], $times);
        arsort($delta);
        $sobe = array_slice(array_keys($delta), 0, 2);
        $cai = array_slice(array_reverse(array_keys($delta)), 0, 2);
        $l[] = '- O tempo favorece (elenco jovem, sobe na liga até a T' . ($n + $ultimo) . '): ' . implode(', ', array_map(fn($id) => $times[$id]['nome'], $sobe))
             . '. O tempo pesa contra (elenco velho): ' . implode(', ', array_map(fn($id) => $times[$id]['nome'], $cai)) . '.';
    }
    $l[] = 'COMO LER: chute com base. A força é o OVR ponderado dos 8 melhores, com cada jogador envelhecendo pela curva média da liga '
         . '(até 24 anos sobe, 25–29 estável, 30+ cai) e o potencial ajustando os jovens. Troca, draft e leilão não dá pra prever: '
         . 'entram como um sorteio de mudança de elenco que cresce a cada temporada, por isso as chances vão achatando. '
         . 'Pediram palpite? Crave um nome por temporada, com a chance do lado.';
    return implode("\n", $l);
}

/* ── O ranking daqui a N temporadas ────────────────────────────────────── */

/** O ranking projetado ao fim de N temporadas, pronto pro bot. */
function projRankingTexto(PDO $pdo, string $ligaPedida, int $temporadas, string $ligaGrupo): string
{
    $liga = projLiga($ligaPedida, $ligaGrupo);
    if (!$liga) return 'Qual liga? ELITE, NEXT, RISE ou ROOKIE.';
    $temporadas = max(1, min(PROJ_MAX_TEMPORADAS, $temporadas ?: 4));
    projComandosDoBot();
    $temp = wcTemporadaAtiva($pdo, $liga);
    if (!$temp) return "A {$liga} não tem temporada cadastrada.";
    $sid = (int)$temp['id'];
    $n = (int)$temp['season_number'];

    // O ponto de partida é o do /ranking: teams.ranking_points.
    $base = [];
    $st = $pdo->prepare('SELECT id, COALESCE(ranking_points, 0) pts FROM teams WHERE league = ?');
    $st->execute([$liga]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $base[(int)$r['id']] = (int)$r['pts'];
    if (!$base) return "A {$liga} não tem times cadastrados.";

    /* O QUE A TEMPORADA EM CURSO AINDA VAI SOMAR.
       O /ranking (teams.ranking_points) só recebe a temporada no REGISTRO
       FINAL da pontuação — regular, playoffs e prêmios de uma vez. Salvar a
       classificação no card grava a regular em team_ranking_points, mas não
       no ranking. A primeira versão tomava essa linha como "já somada" e
       deixava a regular em andamento de fora; a auditoria de 17/09 mostrou a
       ELITE T4 exatamente assim, com a regular lançada e fora do ranking.
       Então: temporada sem playoff registrado ainda deve a regular inteira e
       os playoffs; registrada, já está toda no ranking. */
    $st = $pdo->prepare('SELECT COUNT(*) FROM playoff_results WHERE season_id = ?');
    $st->execute([$sid]);
    $registrada = (int)$st->fetchColumn() > 0;
    $st = $pdo->prepare('SELECT team_id, position FROM season_standings WHERE season_id = ? AND COALESCE(position, 0) > 0');
    $st->execute([$sid]);
    $posAtual = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $posAtual[(int)$r['team_id']] = (int)$r['position'];

    $regularAcabou = (bool)$posAtual;
    $fixo = array_fill_keys(array_keys($base), 0);
    $chave = null;
    $notaAtual = '';
    if ($regularAcabou && $registrada) {
        $notaAtual = "(a T{$n} já está toda somada)";
    } elseif ($regularAcabou) {
        foreach ($posAtual as $id => $p) if (isset($fixo[$id])) $fixo[$id] += pontosPorPosicao($p);
        $notaAtual = "a regular da T{$n} (lançada, entra no ranking no registro final)";
        $ch = projChaveAtual($pdo, $liga);
        if ($ch && (int)$ch['temporada']['id'] === $sid && !projRodarChave($ch['chave'], null)['campeao']) $chave = $ch['chave'];
        $notaAtual .= $chave ? " + os playoffs da T{$n}, simulados na chave de agora" : '';
    }
    $inicio = $regularAcabou ? 1 : 0;
    $ultimo = $inicio + $temporadas - 1;
    $alvo = $n + $ultimo;
    $times = projLigaNoFuturo($pdo, $liga, $ultimo);
    if (!$times) return "A {$liga} está sem elencos — não dá pra projetar.";
    $nomes = projNomesDaLiga($pdo, $liga);

    $N = PROJ_SIMULACOES_RANKING;
    mt_srand(crc32($liga . '|ranking|' . date('Y-m-d')));
    $z0 = array_map(fn($t) => $t['z'][0], $times);
    $cacheChance = [];
    $serieAtual = function (int $a, int $b) use (&$cacheChance, $z0): int {
        $p = $cacheChance["{$a}-{$b}"] ??= projChanceSerie($z0[$a] ?? 0.0, $z0[$b] ?? 0.0);
        return (mt_rand() / mt_getrandmax()) < $p ? $a : $b;
    };
    $decididas = $chave ? projSeriesDecididas($chave) : [];

    $totais = array_fill_keys(array_keys($base), []);
    $lider = array_fill_keys(array_keys($base), 0);
    $somaPos = array_fill_keys(array_keys($base), 0);
    for ($s = 0; $s < $N; $s++) {
        $pts = [];
        foreach ($base as $id => $b) $pts[$id] = $b + $fixo[$id];
        if ($chave) {
            foreach (projRodarChave($chave, $serieAtual, $decididas)['etapa'] as $id => $e) {
                if (isset($pts[$id])) $pts[$id] += pontosDePlayoff($e);
            }
        }
        for ($k = $inicio; $k <= $ultimo; $k++) {
            foreach (projPontosDaTemporada(projSortearTemporada($times, projForcasSorteadas($times, $k))) as $id => $p) {
                if (isset($pts[$id])) $pts[$id] += $p;
            }
        }
        // Empate de pontos se resolve no sorteio: ninguém ganha posição pela ordem do banco.
        $ordem = [];
        foreach ($pts as $id => $p) $ordem[$id] = $p + mt_rand() / mt_getrandmax() * 0.5;
        arsort($ordem);
        $i = 0;
        foreach ($ordem as $id => $_) {
            $i++;
            $somaPos[$id] += $i;
            if ($i === 1) $lider[$id]++;
        }
        foreach ($pts as $id => $p) $totais[$id][] = $p;
    }

    $media = [];
    foreach ($totais as $id => $xs) $media[$id] = array_sum($xs) / $N;
    arsort($media);
    $posHoje = fn(int $id): int => 1 + count(array_filter($base, fn($p) => $p > $base[$id]));

    $janela = 'T' . ($n + $inicio) . ($ultimo > $inicio ? " a T{$alvo}" : '');
    $l = [];
    $l[] = "RANKING PROJETADO — {$liga}, ao fim da T{$alvo} ({$N} simulações).";
    $l[] = '- Conta: o ranking de hoje (o mesmo do /ranking)' . ($notaAtual !== '' ? ' ' . (str_starts_with($notaAtual, '(') ? $notaAtual : '+ ' . $notaAtual) : '')
         . " + {$janela} inteira" . ($ultimo > $inicio ? 's' : '') . ', com os elencos de hoje envelhecendo pela curva de idade da FBA.';
    $l[] = '- Régua por temporada: posição na conferência (1º-2º 5, 3º-4º 4, 5º-6º 3, 7º-8º 2, 9º-10º 1) + playoffs '
         . '(passou da 1ª rodada 1, final de conferência 3, vice 7, campeão 10). Prêmios individuais e NBA Cup não entram.';
    $l[] = '- Posição projetada · time · hoje (posição, pontos) → pontos projetados (faixa de 80%) · chance de terminar em 1º:';
    $i = 0;
    foreach (array_keys($media) as $id) {
        $i++;
        $xs = $totais[$id];
        sort($xs);
        $l[] = "  {$i}. " . ($nomes[$id] ?? '?') . ' — hoje ' . $posHoje($id) . "º com {$base[$id]} → " . (int)round($media[$id])
             . ' (' . (int)projQuantil($xs, 0.10) . '–' . (int)projQuantil($xs, 0.90) . ') · 1º em ' . projPct($lider[$id] / $N);
    }
    $ganho = [];
    $i = 0;
    foreach (array_keys($media) as $id) { $i++; $ganho[$id] = $posHoje($id) - $i; }
    arsort($ganho);
    $sobe = (int)array_key_first($ganho);
    $cai = (int)array_key_last($ganho);
    $favLider = $lider; arsort($favLider);
    $fl = (int)array_key_first($favLider);
    $l[] = '- Palpite de líder ao fim da T' . $alvo . ': ' . ($nomes[$fl] ?? '?') . ' (' . projPct($lider[$fl] / $N) . ').'
         . ($ganho[$sobe] > 0 ? ' Quem mais sobe: ' . ($nomes[$sobe] ?? '?') . " ({$ganho[$sobe]} posições)." : '')
         . ($ganho[$cai] < 0 ? ' Quem mais cai: ' . ($nomes[$cai] ?? '?') . ' (' . abs($ganho[$cai]) . ' posições).' : '');
    $l[] = 'COMO LER: chute com base, e quanto mais longe mais chute. Troca, draft e leilão entram só como um sorteio de mudança de elenco '
         . 'que cresce a cada temporada. Prêmio individual não entra, então time com MVP tende a fazer um pouco mais. '
         . 'Responda com o que perguntaram (o top, ou o time da pessoa), não com a lista inteira.';
    return implode("\n", $l);
}

/* ── O jogador daqui a N temporadas ────────────────────────────────────── */

/** A trajetória de OVR de um jogador nas próximas N temporadas, pronto pro bot. */
function projFuturoJogadorTexto(PDO $pdo, string $textoJogador, int $temporadas, string $ligaGrupo): string
{
    $achado = projAcharJogador($pdo, $textoJogador, $ligaGrupo);
    if (isset($achado['erro'])) return $achado['erro'];
    $j = $achado['jogador'];
    $temporadas = max(1, min(PROJ_MAX_TEMPORADAS, $temporadas ?: 5));
    $ovr0 = (int)$j['ovr'];
    $idade0 = (int)$j['age'];
    $pot = trim((string)($j['skill_pot'] ?? ''));

    $N = 4000;
    mt_srand(crc32($j['id'] . '|carreira|' . date('Y-m-d')));
    $amostras = array_fill(1, $temporadas, []);
    for ($s = 0; $s < $N; $s++) {
        $v = (float)$ovr0;
        for ($k = 1; $k <= $temporadas; $k++) {
            [$m, $dp] = projPassoIdade($idade0 > 0 ? $idade0 + $k - 1 : 0, $pot, $v);
            $v = max(40.0, min(99.0, $v + $m + $dp * projGauss()));
            $amostras[$k][] = $v;
        }
    }

    $idadeEm = fn(int $k): string => $idade0 > 0 ? ($idade0 + $k) . ' anos' : 'idade sem cadastro';
    $linhas = []; $mediana = [];
    foreach ($amostras as $k => $xs) {
        sort($xs);
        $mediana[$k] = projQuantil($xs, 0.5);
        $linhas[] = "+{$k}: " . $idadeEm($k) . ', OVR ' . (int)round($mediana[$k])
                  . ' (' . (int)round(projQuantil($xs, 0.10)) . '–' . (int)round(projQuantil($xs, 0.90)) . ')';
    }
    $fim = $amostras[$temporadas];
    sort($fim);
    $acimaDeHoje = count(array_filter($fim, fn($v) => $v > $ovr0 + 0.5)) / $N;
    $pico = array_search(max($mediana), $mediana, true);

    $liga = strtoupper((string)$j['league']);
    $st = $pdo->prepare('SELECT COUNT(*) FROM players p JOIN teams t ON t.id = p.team_id WHERE t.league = ? AND p.ovr > ?');
    $st->execute([$liga, (int)round($mediana[$temporadas])]);
    $rankNaLiga = (int)$st->fetchColumn() + 1;

    $l = [];
    $l[] = "PROJEÇÃO DE CARREIRA — {$j['name']} ({$j['position']}, {$j['time']}, {$liga}): hoje "
         . ($idade0 > 0 ? "{$idade0} anos" : 'sem idade cadastrada') . ", OVR {$ovr0}" . ($pot !== '' && $pot !== '-' ? ", potencial {$pot}" : '') . '.';
    $l[] = '- Temporada a temporada (OVR mais provável, faixa de 80%): ' . implode(' · ', $linhas) . '.';
    $l[] = "- Daqui a {$temporadas}: " . $idadeEm($temporadas) . ', OVR ~' . (int)round($mediana[$temporadas])
         . '. Chance de estar acima dos ' . $ovr0 . ' de hoje: ' . projPct($acimaDeHoje) . '.'
         . ($mediana[$pico] > $ovr0 + 0.5
             ? " Pico provável: +{$pico}, perto de " . (int)round($mediana[$pico]) . '.'
             : ($mediana[$temporadas] < $ovr0 - 0.5
                 ? ' O pico provável é agora: daqui pra frente a curva desce.'
                 : ' Deve ficar no platô, perto do OVR de hoje.'));
    $l[] = "- Com esse OVR, hoje ele seria o {$rankNaLiga}º maior da {$liga}.";
    $l[] = '- Base: curva medida em 22 mil temporadas de jogador da FBA — aos 20 sobe ~2,7 por ano, aos 23 ~1, de 25 a 27 fica '
         . 'parado, aos 30 cai ~1 e de 32 em diante ~1,7. Até os 24 o potencial ajusta a subida (A+ ~0,9 a mais por ano, C ~1 a menos), '
         . 'e perto do teto ela freia: de 96 pra cima ninguém sobe.';
    $l[] = 'COMO LER: é o jogador médio da liga com essa idade e esse potencial — chute com base. Não entra minutagem, lesão, '
         . 'aposentadoria nem nada que o GM faça com ele. Pediram chute? Crave o OVR mais provável e dê a faixa do lado.';
    return implode("\n", $l);
}

/* ── A loteria ─────────────────────────────────────────────────────────── */

/** A loteria do draft da temporada em curso, com palpite de top 1, pronto pro bot. */
function projLoteriaTexto(PDO $pdo, string $ligaPedida, string $ligaGrupo): string
{
    $liga = projLiga($ligaPedida, $ligaGrupo);
    if (!$liga) return 'Qual liga? ELITE, NEXT, RISE ou ROOKIE.';
    projComandosDoBot();
    $temp = wcTemporadaAtiva($pdo, $liga);
    if (!$temp) return "A {$liga} não tem temporada cadastrada.";
    $sid = (int)$temp['id'];
    $T = 'T' . (int)$temp['season_number'];
    $nomes = projNomesDaLiga($pdo, $liga);
    $pct = fn(float $v): string => number_format($v, 1, ',', '') . '%';
    mt_srand(crc32($liga . '|loteria|' . date('Y-m-d')));

    /* 1) Já sorteada? Então a ordem é fato, e chance nenhuma vale mais que ela.
       O sinal é a 1ª ESCOLHA gravada, e não qualquer linha: na NEXT de 15/09
       o admin já tinha lançado as picks 6 a 14 com a loteria ainda por rodar,
       e "tem ordem" dava a loteria por sorteada começando na 6ª. */
    $st = $pdo->prepare('SELECT id FROM draft_sessions WHERE season_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$sid]);
    $sessao = (int)$st->fetchColumn();
    if ($sessao > 0) {
        /* Cerimônia rolando: o FBAbot não conta o que a urna não entregou.
           A ordem parcial fica no banco conforme cada bolinha sai, e sem este
           corte ele responderia com as primeiras escolhas no meio do sorteio. */
        require_once __DIR__ . '/draft_swaps.php';
        $naUrnaProj = draftPosicoesNaUrna($pdo, $sessao);
        $st = $pdo->prepare('SELECT pick_position, team_id FROM draft_order WHERE draft_session_id = ? AND round = 1 ORDER BY pick_position LIMIT 5');
        $st->execute([$sessao]);
        $ordem = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($naUrnaProj) $ordem = [];
        if ($ordem && (int)$ordem[0]['pick_position'] === 1) {
            return "LOTERIA DA {$liga} (draft da {$T}) — JÁ SORTEADA. Primeiras escolhas: "
                 . implode(', ', array_map(fn($o) => (int)$o['pick_position'] . 'ª ' . ($nomes[(int)$o['team_id']] ?? '?'), $ordem))
                 . '. Não há o que projetar: responda com a ordem (a lista completa está no /loteria).';
        }
    }

    // 2) Classificação lançada: a urna é a de verdade, com a mesma conta do /loteria.
    $sql = "SELECT ss.team_id, ss.position, COALESCE(ss.conference, t.conference) AS conference,
                   ss.wins, ss.points_for, ss.points_against, ss.overall_position, ss.lottery_group, t.name AS team_name
              FROM season_standings ss JOIN teams t ON t.id = ss.team_id WHERE ss.season_id = ?";
    try {
        $st = $pdo->prepare($sql);
        $st->execute([$sid]);
    } catch (Throwable $e) {
        loteriaGarantirColunas($pdo);   // banco antigo sem as colunas da loteria
        $st = $pdo->prepare($sql);
        $st->execute([$sid]);
    }
    $standings = $st->fetchAll(PDO::FETCH_ASSOC);

    $l = [];
    if ($standings && loteriaTemporadaFoiJogada($pdo, $sid)) {
        $g = loteriaMontarGrupos($standings, false);
        if (!$g['elegiveis']) return "Ninguém ficou fora do playoff na {$liga}: não há loteria.";
        // Nome com a cidade, como nas outras projeções: a urna vem só com a alcunha.
        foreach ($g['elegiveis'] as $t) $g['nomes'][$t] = $nomes[$t] ?? $g['nomes'][$t] ?? '?';
        $ordem = $g['elegiveis'];
        usort($ordem, $g['pior_primeiro']);
        $max = max(array_map(fn($t) => $g['top1'][$t], $ordem));
        $empatados = array_values(array_filter($ordem, fn($t) => $g['top1'][$t] == $max));
        $palpite = $empatados[mt_rand(0, count($empatados) - 1)];

        $l[] = "LOTERIA DA {$liga} — draft da {$T}, com a urna de verdade (classificação lançada). Chances exatas, bola a bola.";
        $l[] = '- Palpite pro top 1: ' . ($g['nomes'][$palpite] ?? '?') . ' (' . $pct($max) . ' de pegar a 1ª)'
             . (count($empatados) > 1
                ? ' — empatado com ' . implode(', ', array_map(fn($t) => $g['nomes'][$t] ?? '?', array_diff($empatados, [$palpite])))
                  . ', que têm a mesma urna; entre iguais o nome é chute puro'
                : '') . '.';
        $l[] = '- Por time, da pior campanha pra melhor (grupo, bolinhas: 1ª escolha / top 3 / top 5):';
        foreach ($ordem as $t) {
            $l[] = '  ' . ($g['nomes'][$t] ?? '?') . ' (G' . ($g['grupo_de'][$t] ?? '?') . ', ' . ($g['bolinhas'][$t] ?? 0) . '): '
                 . $pct($g['top1'][$t]) . ' / ' . $pct($g['top3'][$t]) . ' / ' . $pct($g['top5'][$t]);
        }
        $l[] = '- Grupos: G1 os 3 piores (2 bolinhas), G2 os melhores fora do play-in (3), G3 eliminados no play-in (2), G4 quem perdeu o Jogo 2 do Play-in (1).'
             . (!$g['declarado'] ? ' O admin ainda não marcou quem caiu no play-in: por enquanto o 9º e o 10º de cada conferência estão no G3 e o G4 está vazio — as chances mudam quando ele marcar.' : '');
        $l[] = 'COMO LER: no desenho da FBA os 3 piores têm MENOS chance que o miolo (2 bolinhas contra 3) — é regra, não erro. '
             . 'Palpite de top 1 é chute: a urna decide.';
        return implode("\n", $l);
    }

    // 3) Sem classificação: simula a temporada e monta a urna de cada simulação.
    $times = projTimesDaLiga($pdo, $liga);
    if (!$times) return "A {$liga} está sem elencos — não dá pra projetar a loteria.";
    $z = array_map(fn($t) => $t['z'], $times);
    $M = PROJ_SIMULACOES_LOTERIA;
    $soma1 = $soma3 = $fora = array_fill_keys(array_keys($times), 0);
    for ($s = 0; $s < $M; $s++) {
        $linhas = [];
        foreach (projSortearTemporada($times, $z) as $id => $x) {
            $linhas[] = ['team_id' => $id, 'position' => $x['pos'], 'conference' => $times[$id]['conf'], 'wins' => 0,
                         'points_for' => 0, 'points_against' => 0, 'overall_position' => null, 'lottery_group' => null,
                         'team_name' => $times[$id]['nome']];
        }
        $g = loteriaMontarGrupos($linhas, false);
        foreach ($g['elegiveis'] as $t) {
            $fora[$t]++;
            $soma1[$t] += $g['top1'][$t];
            $soma3[$t] += $g['top3'][$t];
        }
    }
    arsort($soma1);
    $top = array_slice(array_keys($soma1), 0, 8);
    $l[] = "LOTERIA DA {$liga} — PROJEÇÃO. A {$T} ainda não tem classificação, então não existe urna: simulei {$M} temporadas "
         . 'com os elencos de hoje e montei a urna de cada uma com a regra da FBA.';
    $l[] = '- Palpite pro top 1: ' . $times[$top[0]]['nome'] . ' (' . $pct($soma1[$top[0]] / $M) . ' de pegar a 1ª, somando a chance de ficar fora dos playoffs).';
    $l[] = '- Mais cotados (fica fora dos playoffs · 1ª escolha · top 3): ' . implode(' · ', array_map(
        fn($t) => $times[$t]['nome'] . ' ' . projPct($fora[$t] / $M) . ' · ' . $pct($soma1[$t] / $M) . ' · ' . $pct($soma3[$t] / $M), $top)) . '.';
    $l[] = 'COMO LER: projeção, não a urna. Na FBA os 3 piores levam 2 bolinhas e o miolo 3, então o favorito ao top 1 costuma ser '
         . 'um time fraco que NÃO é dos 3 piores. Quem cai no play-in e no Jogo 2 depende de jogo e não entra. Palpite é chute.';
    return implode("\n", $l);
}
