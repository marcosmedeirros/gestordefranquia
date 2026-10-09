<?php
/**
 * ── LENDAS DA QUADRA: A IA ───────────────────────────────────────────
 *
 * Joga um turno inteiro do lado que tem a vez. Serve pra três coisas:
 * o TREINO (partida offline contra a máquina, que nunca dá moeda), a
 * SIMULAÇÃO de equilíbrio (máquina contra máquina, milhares de vezes) e,
 * se um dia quisermos, assumir quem cair de uma partida online.
 *
 * É gananciosa e de propósito simples — sem árvore de busca. Um turno:
 *
 *   1. CARTAS: herói mais caro que cabe (numa casa da zona perto do
 *      caminho), vilão quando derruba alguém ou trava a maior ameaça,
 *      ferreiro no herói que mais aproveita, bandagem em quem está ferido.
 *   2. ATAQUES de quem já alcança: Fortaleza primeiro; depois quem cai
 *      com o golpe; depois o inimigo mais perigoso.
 *   3. MOVIMENTO de quem sobrou: rumo à Fortaleza rival — ou de volta pra
 *      defender a própria, quando tem inimigo chegando nela — e ataca se
 *      chegou ao alcance.
 *
 * `$nivel` (1 a 3) só mexe numa coisa: com que frequência ela erra de
 * propósito (deixa de usar uma carta boa, ataca o alvo errado). Nível 3 não
 * erra; o 1 é pra quem está aprendendo.
 */

require_once __DIR__ . '/lendas_motor.php';

function lendasIaTurno(array &$e, int $nivel = 3): void
{
    $lado = $e['vez'];
    $guarda = 0;
    while (!$e['fim'] && $e['vez'] === $lado && $guarda++ < 40) {
        if (!lendasIaUmaCarta($e, $lado, $nivel)) break;
    }
    if ($e['fim'] || $e['vez'] !== $lado) return;
    lendasIaUnidades($e, $lado, $nivel);
    if (!$e['fim'] && $e['vez'] === $lado) lendasAgir($e, $lado, ['tipo' => 'passar']);
}

/** Erro de propósito, pros níveis baixos. */
function lendasIaVacila(array &$e, int $nivel): bool
{
    $chance = [1 => 35, 2 => 15, 3 => 0][$nivel] ?? 0;
    return $chance > 0 && lendasRand($e, 1, 100) <= $chance;
}

/** Quanto um herói inimigo ameaça: ATQ, mais se já está perto da minha Fortaleza. */
function lendasIaAmeaca(array $u, string $meuLado): float
{
    $d = lendasDistFortaleza([$u['x'], $u['y']], $meuLado);
    return lendasAtq($u) * 2 + $u['vida'] * 0.4 + max(0, 8 - $d) * 1.5;
}

/** Joga UMA carta da mão, a melhor que achar. Devolve false se não jogou nada. */
function lendasIaUmaCarta(array &$e, string $lado, int $nivel): bool
{
    $j = $e['jogadores'][$lado];
    $inimigo = lendasOutro($lado);
    $cat = lendasCatalogo();
    $minhas = array_values(array_filter($e['unidades'], fn($u) => $u['dono'] === $lado));
    $deles  = array_values(array_filter($e['unidades'], fn($u) => $u['dono'] === $inimigo));

    $melhor = null; // [nota, acao]
    foreach ($j['mao'] as $i => $id) {
        $c = $cat[$id];
        if ($c['custo'] > $j['energia']) continue;
        $nota = null; $acao = null;

        if ($c['classe'] === 'heroi') {
            if (count($minhas) >= LENDAS_EM_QUADRA) continue;
            $casa = lendasIaCasaDeEntrada($e, $lado);
            if (!$casa) continue;
            $nota = 10 + $c['custo'] * 3;
            $acao = ['tipo' => 'carta', 'mao' => $i, 'x' => $casa[0], 'y' => $casa[1]];
        } elseif ($c['classe'] === 'vilao') {
            switch ($c['efeito']) {
                case 'poster':
                    foreach ($deles as $u) {
                        $v = $u['vida'] <= $c['forca'] ? 30 + $u['atq'] * 2 : $c['forca'] * 1.5;
                        if ($v > ($nota ?? 0)) { $nota = $v; $acao = ['tipo' => 'carta', 'mao' => $i, 'alvo' => $u['id']]; }
                    }
                    break;
                case 'falta': case 'maldicao': case 'marcacao':
                    foreach ($deles as $u) {
                        $v = lendasIaAmeaca($u, $lado) * ($c['efeito'] === 'falta' ? 1.0 : 0.6);
                        if ($v > 12 && $v > ($nota ?? 0)) { $nota = $v; $acao = ['tipo' => 'carta', 'mao' => $i, 'alvo' => $u['id']]; }
                    }
                    break;
                case 'torcida':
                    $nota = $c['forca'] * 2 + ($e['jogadores'][$inimigo]['fortaleza'] <= $c['forca'] ? 100 : 0);
                    $acao = ['tipo' => 'carta', 'mao' => $i];
                    break;
                case 'roubo': case 'apagao':
                    $nota = 6 + $c['forca'] * 3;
                    $acao = ['tipo' => 'carta', 'mao' => $i];
                    break;
            }
        } else { // ferreiro
            foreach ($minhas as $u) {
                $v = null;
                switch ($c['efeito']) {
                    case 'bandagem': $falta = $u['vida_max'] - $u['vida']; if ($falta >= min(3, $c['forca'])) $v = min($falta, $c['forca']) * 2; break;
                    case 'luvas':    $v = 4 + $c['forca'] * 3 + $u['alc']; break;
                    case 'armadura': $v = 3 + $c['forca'] * 1.5; break;
                    case 'tenis':    $v = $u['mov'] <= 1 ? 9 : 5; break;
                    case 'arco':     $v = $u['alc'] >= 2 ? 10 : 6; break;
                    case 'escudo':   $v = 4 + $c['forca'] * 3 + $u['atq']; break;
                    case 'amuleto':  $v = 3 + $u['atq'] * 1.5; break;
                }
                if ($v !== null && $v > ($nota ?? 0)) { $nota = $v; $acao = ['tipo' => 'carta', 'mao' => $i, 'alvo' => $u['id']]; }
            }
        }
        if ($nota !== null && (!$melhor || $nota > $melhor[0])) $melhor = [$nota, $acao];
    }
    if (!$melhor || lendasIaVacila($e, $nivel)) return false;
    return lendasAgir($e, $lado, $melhor[1]) === null;
}

/**
 * A casa livre da zona de entrada pra um herói novo: na frente (mais perto
 * do rival), e na COLUNA MENOS POVOADA — espalhar é o que abre os flancos.
 */
function lendasIaCasaDeEntrada(array $e, string $lado): ?array
{
    $porColuna = array_fill(0, LENDAS_LARG, 0);
    foreach ($e['unidades'] as $u) if ($u['dono'] === $lado) $porColuna[$u['x']]++;
    $frente = $lado === 'A' ? 1 : LENDAS_ALT - 2;
    $melhor = null; $md = PHP_INT_MAX;
    for ($y = 0; $y < LENDAS_ALT; $y++) {
        if (!lendasNaZona($lado, $y)) continue;
        for ($x = 0; $x < LENDAS_LARG; $x++) {
            if (lendasOcupada($e, $x, $y)) continue;
            $nota = $porColuna[$x] * 10 + abs($y - $frente) * 4 + abs($x - 3) + lendasRand($e, 0, 3);
            if ($nota < $md) { $md = $nota; $melhor = [$x, $y]; }
        }
    }
    return $melhor;
}

/**
 * Com UM movimento e UM ataque por turno, a IA escolhe:
 *   1. se já dá pra bater na Fortaleza ou derrubar alguém, bate antes;
 *   2. o melhor movimento — quem chega ao alcance de um alvo bom, ou entra
 *      na zona do rival (Pressão), ou avança mais;
 *   3. se ainda não atacou, o melhor ataque que sobrou.
 */
function lendasIaUnidades(array &$e, string $lado, int $nivel): void
{
    $fortDeles = lendasFortalezaPos(lendasOutro($lado));
    $inimigo = lendasOutro($lado);
    $melhorAtaque = function () use (&$e, $lado) {
        $melhor = null;
        foreach ($e['unidades'] as $u) {
            if ($u['dono'] !== $lado) continue;
            foreach (lendasAlvos($e, $u) as $a) {
                $v = lendasIaValorAlvo($e, $u, $a);
                if (!$melhor || $v > $melhor[0]) $melhor = [$v, $u['id'], $a];
            }
        }
        return $melhor;
    };

    // 1. ataque decisivo primeiro
    $m = $melhorAtaque();
    if ($m && $m[0] >= 60 && !lendasIaVacila($e, $nivel)) {
        lendasAgir($e, $lado, ['tipo' => 'atacar', 'unidade' => $m[1], 'alvo' => $m[2]]);
        if ($e['fim']) return;
    }

    // 2. o melhor movimento
    $perigo = null;
    foreach ($e['unidades'] as $v) {
        if ($v['dono'] === $inimigo && lendasDistFortaleza([$v['x'], $v['y']], $lado) <= 3) { $perigo = [$v['x'], $v['y']]; break; }
    }
    $mov = null;
    foreach ($e['unidades'] as $u) {
        if ($u['dono'] !== $lado) continue;
        foreach (lendasAlcanceMov($e, $u) as $c) {
            $d0 = lendasDistFortaleza([$u['x'], $u['y']], $inimigo);
            $d1 = lendasDistFortaleza($c, $inimigo);
            $nota = ($d0 - $d1) * 3;
            if (lendasNaZona($inimigo, $c[1]) && !lendasNaZona($inimigo, $u['y'])) $nota += 14;   // entra pra Pressão
            if ($d1 <= $u['alc']) $nota += 18;                                                    // chega na Fortaleza
            if ($perigo && lendasDist($c, $perigo) <= $u['alc'] && lendasDistFortaleza([$u['x'], $u['y']], $lado) <= 5) $nota += 16; // defende
            foreach ($e['unidades'] as $v) {
                if ($v['dono'] !== $inimigo) continue;
                $dv = lendasDist($c, [$v['x'], $v['y']]);
                if ($dv <= $u['alc']) $nota += $v['vida'] <= lendasAtq($u) ? 12 : 5;            // fica com alvo
                if ($dv === 1 && $u['alc'] >= 2) $nota -= 8;                                      // arqueiro colado é ruim
            }
            if (!$mov || $nota > $mov[0]) $mov = [$nota, $u['id'], $c];
        }
    }
    if ($mov && $mov[0] > 0) lendasAgir($e, $lado, ['tipo' => 'mover', 'unidade' => $mov[1], 'x' => $mov[2][0], 'y' => $mov[2][1]]);

    // 3. o melhor ataque que sobrou
    $m = $melhorAtaque();
    if ($m) {
        if (lendasIaVacila($e, $nivel)) {
            $u = lendasUnidade($e, $m[1]);
            $alvos = lendasAlvos($e, $u);
            $m[2] = $alvos[lendasRand($e, 0, count($alvos) - 1)];
        }
        lendasAgir($e, $lado, ['tipo' => 'atacar', 'unidade' => $m[1], 'alvo' => $m[2]]);
    }
}

/** Quanto vale atacar esse alvo com essa unidade. */
function lendasIaValorAlvo(array $e, array $u, $a): float
{
    if ($a === 'fortaleza') return 75;
    $alvo = lendasUnidade($e, $a);
    $mata = $alvo['vida'] <= lendasAtq($u);
    $revide = $u['hab'] !== 'Furtivo' && lendasDist([$u['x'], $u['y']], [$alvo['x'], $alvo['y']]) === 1 ? intdiv(lendasAtq($alvo), 2) : 0;
    return ($mata ? 60 : 10) + lendasIaAmeaca($alvo, $u['dono']) - ($revide >= $u['vida'] ? 40 : $revide * 2);
}
