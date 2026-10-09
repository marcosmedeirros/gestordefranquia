<?php
/**
 * ── LENDAS DA QUADRA: O MOTOR DE REGRAS ──────────────────────────────
 *
 * A partida inteira é UM estado (um array, guardado em JSON no banco), e
 * só este arquivo o modifica. Quem joga manda uma intenção — "jogar a 3ª
 * carta da mão na casa (2,1)", "atacar a Fortaleza com tal herói" — e o
 * motor confere se pode, aplica, e devolve os eventos pra tela animar.
 * O navegador nunca decide nada: nem o dado, nem a carta comprada, nem se
 * o ataque alcança.
 *
 * ── O TABULEIRO ──────────────────────────────────────────────────────
 *
 *     x: 0 1 2 3 4 5 6
 *   y 7  . . B B B . .     B = Fortaleza do jogador B (três casas)
 *   y 6  . . . . . . .     y 6 e 7: zona de entrada de B
 *   ...
 *   y 1  . . . . . . .     y 0 e 1: zona de entrada de A
 *   y 0  . . A A A . .
 *
 * A Fortaleza tem TRÊS casas, e não uma: com uma só, três defensores
 * tampavam todo acesso a ela, e na simulação 195 de 200 partidas acabavam
 * no limite de turnos sem ninguém derrubar nada.
 *
 * Distância é em casas, sem diagonal (Manhattan). Andar contorna quem
 * estiver no caminho; ninguém atravessa herói nem Fortaleza.
 *
 * ── UMA AÇÃO DE CADA, POR TURNO ──────────────────────────────────────
 *
 * Regra pedida pelo Victor (09/10/2026): em cada turno o jogador faz no
 * máximo UM movimento (de um herói que já estava em quadra), UM ataque e
 * UMA carta da mão. A energia continua limitando o que a carta custa; o
 * 1+1+1 limita quantas coisas acontecem. É o que transforma o turno numa
 * escolha — qual herói anda, qual bate — em vez de "faça tudo que der".
 *
 * ── O SORTEIO É DO ESTADO ────────────────────────────────────────────
 *
 * O baralho embaralhado e cada d20 saem de uma semente guardada no próprio
 * estado (`rng`), que avança a cada uso. A mesma partida, com as mesmas
 * jogadas, dá os mesmos dados — é o que deixa reproduzir uma partida pra
 * investigar uma reclamação, e o que deixa a simulação ser repetível.
 */

require_once __DIR__ . '/lendas_cartas.php';

const LENDAS_LARG = 7;
const LENDAS_ALT  = 8;
const LENDAS_FORTALEZA_VIDA = 20;
const LENDAS_MAO = 5;
/**
 * Heróis em quadra por lado, no máximo — os cinco titulares. Sem teto, o
 * jeito de ganhar era entupir o tabuleiro de cartas baratas.
 */
const LENDAS_EM_QUADRA = 5;
/** Dano na Fortaleza rival por herói seu dentro da zona dela, por turno. */
const LENDAS_PRESSAO = 2;
/** Energia do primeiro turno (depois cresce +1 por turno, até o teto). */
const LENDAS_ENERGIA_INICIAL = 2;
const LENDAS_ENERGIA_MAX = 8;
/** O jogador que começa em segundo ganha isto de energia no primeiro turno dele. */
const LENDAS_COMPENSACAO_B = 2;
/**
 * Turnos somados dos dois (18 de cada). Chegou aqui sem Fortaleza no chão,
 * vence quem a tiver mais inteira — dois jogadores só se defendendo não
 * podem prender a partida a noite inteira.
 */
const LENDAS_TURNOS_MAX = 36;

/** O centro da Fortaleza de cada lado. */
function lendasFortalezaPos(string $lado): array
{
    return $lado === 'A' ? [3, 0] : [3, LENDAS_ALT - 1];
}

/** As três casas da Fortaleza. */
function lendasFortalezaCasas(string $lado): array
{
    [$x, $y] = lendasFortalezaPos($lado);
    return [[$x - 1, $y], [$x, $y], [$x + 1, $y]];
}

/** Distância de uma casa até a Fortaleza (a casa dela mais perto). */
function lendasDistFortaleza(array $c, string $lado): int
{
    return min(array_map(fn($f) => lendasDist($c, $f), lendasFortalezaCasas($lado)));
}

function lendasOutro(string $lado): string { return $lado === 'A' ? 'B' : 'A'; }

function lendasNaZona(string $lado, int $y): bool
{
    return $lado === 'A' ? $y <= 1 : $y >= LENDAS_ALT - 2;
}

function lendasDist(array $a, array $b): int { return abs($a[0] - $b[0]) + abs($a[1] - $b[1]); }

// ─────────────────────────────── sorteio ───────────────────────────────

/** Um inteiro de $min a $max com a semente do estado, que avança. */
function lendasRand(array &$e, int $min, int $max): int
{
    mt_srand($e['rng']);
    $v = mt_rand($min, $max);
    $e['rng'] = mt_rand(1, 2000000000);
    mt_srand();
    return $v;
}

function lendasEmbaralhar(array &$e, array $lista): array
{
    for ($i = count($lista) - 1; $i > 0; $i--) {
        $j = lendasRand($e, 0, $i);
        [$lista[$i], $lista[$j]] = [$lista[$j], $lista[$i]];
    }
    return $lista;
}

// ─────────────────────────────── eventos ───────────────────────────────

/** Anota o que aconteceu, pra tela contar (e animar) na ordem certa. */
function lendasEvento(array &$e, string $tipo, array $dados = []): void
{
    $e['seq']++;
    $e['eventos'][] = ['seq' => $e['seq'], 'tipo' => $tipo] + $dados;
    // Só os últimos 80: quem está assistindo pede "desde o seq tal", e
    // ninguém fica 80 eventos atrás sem recarregar a página.
    if (count($e['eventos']) > 80) $e['eventos'] = array_slice($e['eventos'], -80);
}

// ─────────────────────────────── a partida ─────────────────────────────

/**
 * Uma partida nova.
 *
 * @param array $a ['uid' => int, 'nome' => string, 'baralho' => string[30]]
 * @param array $b o mesmo, pro outro lado
 */
function lendasNovaPartida(array $a, array $b, int $semente): array
{
    $e = ['rng' => $semente, 'seq' => 0, 'eventos' => [], 'turno' => 0, 'vez' => 'A',
          'fim' => false, 'vencedor' => null, 'motivo' => null, 'unidades' => [], 'proxima_unidade' => 1,
          'jogadores' => []];
    foreach (['A' => $a, 'B' => $b] as $lado => $j) {
        $e['jogadores'][$lado] = [
            'uid' => $j['uid'], 'nome' => $j['nome'], 'ia' => !empty($j['ia']),
            'fortaleza' => LENDAS_FORTALEZA_VIDA,
            'energia' => 0, 'energia_max' => 0, 'penalidade' => 0, 'timeouts' => 0,
            'baralho' => lendasEmbaralhar($e, $j['baralho']), 'mao' => [], 'descarte' => [],
            'turnos' => 0, 'feito' => ['mover' => false, 'atacar' => false, 'carta' => false],
            'baralho_original' => array_values($j['baralho']),   // pros desafios; nunca vai pra tela
        ];
        for ($i = 0; $i < LENDAS_MAO; $i++) lendasComprar($e, $lado);
    }
    lendasEvento($e, 'inicio');
    lendasComecarTurno($e, 'A');
    return $e;
}

/** Compra uma carta. Baralho vazio: o descarte é embaralhado de volta. */
function lendasComprar(array &$e, string $lado): void
{
    $j = &$e['jogadores'][$lado];
    if (!$j['baralho'] && $j['descarte']) {
        $j['baralho'] = lendasEmbaralhar($e, $j['descarte']);
        $j['descarte'] = [];
        lendasEvento($e, 'reembaralhou', ['lado' => $lado]);
    }
    if ($j['baralho'] && count($j['mao']) < LENDAS_MAO) $j['mao'][] = array_shift($j['baralho']);
}

function lendasComecarTurno(array &$e, string $lado): void
{
    if ($e['turno'] >= LENDAS_TURNOS_MAX) {
        $fa = $e['jogadores']['A']['fortaleza']; $fb = $e['jogadores']['B']['fortaleza'];
        // Empate de Fortaleza: quem derrubou mais heróis do outro.
        $vence = $fa !== $fb ? ($fa > $fb ? 'A' : 'B')
               : (($e['jogadores']['B']['caidos'] ?? 0) >= ($e['jogadores']['A']['caidos'] ?? 0) ? 'A' : 'B');
        lendasEncerrar($e, $vence, 'tempo');
        return;
    }
    $e['vez'] = $lado;
    $e['turno']++;
    $j = &$e['jogadores'][$lado];
    $j['turnos']++;
    $j['energia_max'] = $j['turnos'] === 1 ? LENDAS_ENERGIA_INICIAL : min(LENDAS_ENERGIA_MAX, $j['energia_max'] + 1);
    $bonus = ($lado === 'B' && $j['turnos'] === 1) ? LENDAS_COMPENSACAO_B : 0;
    $j['energia'] = max(0, $j['energia_max'] + $bonus - $j['penalidade']);
    $j['penalidade'] = 0;
    $j['feito'] = ['mover' => false, 'atacar' => false, 'carta' => false];
    while (count($j['mao']) < LENDAS_MAO && ($j['baralho'] || $j['descarte'])) lendasComprar($e, $lado);
    unset($j);

    /* PRESSÃO NO GARRAFÃO: cada herói seu dentro da zona de entrada do
       rival tira LENDAS_PRESSAO da Fortaleza dele, no começo do seu turno.
       Sem isto a luta toda acontecia numa coluna do meio, ninguém tinha
       motivo pra furar pelos lados, e quem defendia vivia repondo heróis
       na frente da própria Fortaleza — as partidas acabavam no limite de
       turnos sem ninguém derrubar nada. */
    $inimigo = lendasOutro($lado);
    $pressao = LENDAS_PRESSAO * count(array_filter($e['unidades'], fn($u) => $u['dono'] === $lado && lendasNaZona($inimigo, $u['y'])));
    if ($pressao > 0) {
        lendasEvento($e, 'pressao', ['lado' => $lado, 'n' => $pressao]);
        lendasFerirFortaleza($e, $inimigo, $pressao, $lado);
        if ($e['fim']) return;
    }

    foreach ($e['unidades'] as &$u) {
        $u['buff'] = 0;
        if ($u['dono'] !== $lado) continue;
        $u['moveu'] = false;
        $u['atacou'] = false;
        $u['travado'] = $u['atordoado'] > 0;   // não anda nem ataca neste turno
        $u['preso_agora'] = $u['preso'] > 0;   // não anda neste turno
        if ($u['atordoado'] > 0) $u['atordoado']--;
        if ($u['preso'] > 0) $u['preso']--;
        }
    unset($u);
    // Assistência: o estrategista dá +1 ATQ aos aliados vizinhos neste turno.
    foreach ($e['unidades'] as $est) {
        if ($est['dono'] !== $lado || $est['hab'] !== 'Assistência') continue;
        foreach ($e['unidades'] as &$u) {
            if ($u['dono'] === $lado && $u['id'] !== $est['id'] && lendasDist([$u['x'], $u['y']], [$est['x'], $est['y']]) === 1) $u['buff']++;
        }
        unset($u);
    }
    lendasEvento($e, 'turno', ['lado' => $lado, 'turno' => $e['turno']]);
}

function lendasTerminarTurno(array &$e): void
{
    $lado = $e['vez'];
    foreach ($e['unidades'] as &$u) {
        if ($u['dono'] !== $lado) continue;
        foreach ($u['maldicoes'] as $k => $m) {
            if (--$u['maldicoes'][$k]['turnos'] <= 0) unset($u['maldicoes'][$k]);
        }
        $u['maldicoes'] = array_values($u['maldicoes']);
    }
    unset($u);
    lendasComecarTurno($e, lendasOutro($lado));
}

// ─────────────────────────────── consultas ─────────────────────────────

function &lendasUnidade(array &$e, int $id)
{
    foreach ($e['unidades'] as $k => &$u) if ($u['id'] === $id) return $u;
    $nulo = null;
    return $nulo;
}

function lendasOcupada(array $e, int $x, int $y): bool
{
    if ($x < 0 || $y < 0 || $x >= LENDAS_LARG || $y >= LENDAS_ALT) return true;
    foreach (['A', 'B'] as $l) if (in_array([$x, $y], lendasFortalezaCasas($l), true)) return true;
    foreach ($e['unidades'] as $u) if ($u['x'] === $x && $u['y'] === $y) return true;
    return false;
}

/** O ATQ de agora: o da carta, mais o empurrão do turno, menos as maldições. */
function lendasAtq(array $u): int
{
    return max(0, $u['atq'] + $u['buff'] - array_sum(array_column($u['maldicoes'], 'n')));
}

/** As casas aonde a unidade chega andando neste turno (busca em largura). */
function lendasAlcanceMov(array $e, array $u): array
{
    if ($e['jogadores'][$u['dono']]['feito']['mover'] ?? false) return [];
    if ($u['moveu'] || $u['atacou'] || $u['travado'] || $u['preso_agora'] || $u['entrou'] === $e['turno']) return [];
    $passos = [[$u['x'], $u['y'], 0]];
    $visto = ["{$u['x']},{$u['y']}" => true];
    $saida = [];
    while ($passos) {
        [$x, $y, $d] = array_shift($passos);
        if ($d >= $u['mov']) continue;
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $nx = $x + $dx; $ny = $y + $dy;
            if (isset($visto["$nx,$ny"]) || lendasOcupada($e, $nx, $ny)) continue;
            $visto["$nx,$ny"] = true;
            $saida[] = [$nx, $ny];
            $passos[] = [$nx, $ny, $d + 1];
        }
    }
    return $saida;
}

/**
 * Quem essa unidade consegue atacar daqui: ids de inimigos e/ou 'fortaleza'.
 *
 * ARREMESSO CONTESTADO: quem ataca de longe e tem um inimigo colado só
 * consegue bater nesse inimigo. Sem isto o arqueiro atirava de três casas
 * sem risco nenhum, e um baralho só de arqueiros vencia 95% das vezes.
 */
function lendasAlvos(array $e, array $u): array
{
    if ($e['jogadores'][$u['dono']]['feito']['atacar'] ?? false) return [];
    if ($u['atacou'] || $u['travado'] || $u['entrou'] === $e['turno']) return [];
    $colados = [];
    $alvos = [];
    foreach ($e['unidades'] as $v) {
        if ($v['dono'] === $u['dono']) continue;
        $d = lendasDist([$u['x'], $u['y']], [$v['x'], $v['y']]);
        if ($d === 1) $colados[] = $v['id'];
        if ($d <= $u['alc']) $alvos[] = $v['id'];
    }
    if ($u['alc'] >= 2 && $colados) return $colados;
    if (lendasDistFortaleza([$u['x'], $u['y']], lendasOutro($u['dono'])) <= $u['alc']) $alvos[] = 'fortaleza';
    return $alvos;
}

// ─────────────────────────────── ações ─────────────────────────────────

/**
 * Aplica uma ação de quem está com a vez. Devolve null se deu certo, ou o
 * motivo (texto) se não pôde — e aí o estado fica como estava.
 *
 * Ações: ['tipo' => 'carta', 'mao' => i, 'x' => .., 'y' => .., 'alvo' => id]
 *        ['tipo' => 'mover', 'unidade' => id, 'x' => .., 'y' => ..]
 *        ['tipo' => 'atacar', 'unidade' => id, 'alvo' => id | 'fortaleza']
 *        ['tipo' => 'passar']   ['tipo' => 'desistir']
 */
function lendasAgir(array &$e, string $lado, array $a): ?string
{
    if ($e['fim']) return 'A partida já acabou.';
    if ($a['tipo'] === 'desistir') { lendasEncerrar($e, lendasOutro($lado), 'desistencia'); return null; }
    if ($e['vez'] !== $lado) return 'Não é a sua vez.';

    switch ($a['tipo']) {
        case 'passar':
            lendasTerminarTurno($e);
            return null;
        case 'carta':
            return lendasJogarCarta($e, $lado, (int)($a['mao'] ?? -1), $a);
        case 'mover':
            return lendasMover($e, $lado, (int)($a['unidade'] ?? 0), (int)($a['x'] ?? -1), (int)($a['y'] ?? -1));
        case 'atacar':
            return lendasAtacar($e, $lado, (int)($a['unidade'] ?? 0), $a['alvo'] ?? null);
    }
    return 'Ação desconhecida.';
}

function lendasJogarCarta(array &$e, string $lado, int $i, array $a): ?string
{
    $j = &$e['jogadores'][$lado];
    if ($j['feito']['carta']) return 'Você já usou uma carta neste turno.';
    if (!isset($j['mao'][$i])) return 'Carta inválida.';
    $c = lendasCatalogo()[$j['mao'][$i]] ?? null;
    if (!$c) return 'Carta desconhecida.';
    if ($c['custo'] > $j['energia']) return 'Energia insuficiente.';
    $inimigo = lendasOutro($lado);

    if ($c['classe'] === 'heroi') {
        $emQuadra = count(array_filter($e['unidades'], fn($u) => $u['dono'] === $lado));
        if ($emQuadra >= LENDAS_EM_QUADRA) return 'Você já tem ' . LENDAS_EM_QUADRA . ' heróis em quadra.';
        $x = (int)($a['x'] ?? -1); $y = (int)($a['y'] ?? -1);
        if (!lendasNaZona($lado, $y) || lendasOcupada($e, $x, $y)) return 'O herói entra numa casa livre da sua zona de entrada.';
        $id = $e['proxima_unidade']++;
        $e['unidades'][] = [
            'id' => $id, 'dono' => $lado, 'carta' => $c['id'], 'x' => $x, 'y' => $y,
            'atq' => $c['atq'], 'vida' => $c['vida'], 'vida_max' => $c['vida'], 'mov' => $c['mov'], 'alc' => $c['alc'],
            'tipo' => $c['tipo'], 'hab' => $c['hab'], 'crit' => $c['hab'] === 'Chuva de 3' ? 19 : 20,
            'escudo' => 0, 'buff' => 0, 'maldicoes' => [], 'atordoado' => 0, 'preso' => 0,
            'travado' => false, 'preso_agora' => false, 'moveu' => false, 'atacou' => false,
            'entrou' => $e['turno'], 'equipamentos' => [],
        ];
        lendasEvento($e, 'entrou', ['lado' => $lado, 'unidade' => $id, 'carta' => $c['id'], 'x' => $x, 'y' => $y]);
        // Pro desafio "3 lendárias em quadra ao mesmo tempo".
        $cat = lendasCatalogo();
        $lendarias = count(array_filter($e['unidades'], fn($u) => $u['dono'] === $lado && ($cat[$u['carta']]['rar'] ?? '') === 'lendaria'));
        $e['jogadores'][$lado]['max_lendarias'] = max($e['jogadores'][$lado]['max_lendarias'] ?? 0, $lendarias);
    } else {
        $alvoId = (int)($a['alvo'] ?? 0);
        $semAlvo = in_array($c['efeito'], ['roubo', 'torcida', 'apagao'], true);
        if (!$semAlvo) {
            $alvo = &lendasUnidade($e, $alvoId);
            if (!$alvo) return 'Escolha um herói.';
            $donoCerto = $c['classe'] === 'ferreiro' ? $lado : $inimigo;
            if ($alvo['dono'] !== $donoCerto) {
                return $c['classe'] === 'ferreiro' ? 'Ferreiros equipam heróis seus.' : 'Vilões atacam heróis do rival.';
            }
        }
        $n = $c['forca'];
        switch ($c['efeito']) {
            case 'luvas':    $alvo['atq'] += $n; break;
            case 'armadura': $alvo['vida_max'] += $n; $alvo['vida'] += $n; break;
            case 'tenis':    $alvo['mov'] += $n; break;
            case 'arco':     $alvo['alc'] += $n; break;
            case 'escudo':   $alvo['escudo'] += $n; break;
            case 'amuleto':  $alvo['crit'] = min($alvo['crit'], $n); break;
            case 'bandagem': $alvo['vida'] = min($alvo['vida_max'], $alvo['vida'] + $n); break;
            case 'poster':   lendasFerir($e, $alvoId, $n, $lado, false); break;
            case 'falta':    $alvo['atordoado'] = max($alvo['atordoado'], $n); break;
            case 'maldicao': $alvo['maldicoes'][] = ['n' => $n, 'turnos' => 2]; break;
            case 'marcacao':
                $alvo['preso'] = max($alvo['preso'], 1);
                $extra = $n - 1;
                foreach ($e['unidades'] as &$v) {
                    if ($extra <= 0) break;
                    if ($v['dono'] === $inimigo && $v['id'] !== $alvoId && lendasDist([$v['x'], $v['y']], [$alvo['x'], $alvo['y']]) === 1) {
                        $v['preso'] = max($v['preso'], 1);
                        $extra--;
                    }
                }
                unset($v);
                break;
            case 'roubo':
                for ($k = 0; $k < $n && $e['jogadores'][$inimigo]['mao']; $k++) {
                    $mao = &$e['jogadores'][$inimigo]['mao'];
                    $sorteada = lendasRand($e, 0, count($mao) - 1);
                    $e['jogadores'][$inimigo]['descarte'][] = $mao[$sorteada];
                    array_splice($mao, $sorteada, 1);
                    unset($mao);
                }
                break;
            case 'torcida':  lendasFerirFortaleza($e, $inimigo, $n, $lado); break;
            case 'apagao':   $e['jogadores'][$inimigo]['penalidade'] += $n; break;
        }
        if ($c['classe'] === 'ferreiro' && $c['efeito'] !== 'bandagem' && isset($alvo)) $alvo['equipamentos'][] = $c['id'];
        unset($alvo);
        lendasEvento($e, 'efeito', ['lado' => $lado, 'carta' => $c['id'], 'alvo' => $semAlvo ? null : $alvoId]);
    }

    $j['energia'] -= $c['custo'];
    $j['feito']['carta'] = true;
    $j['descarte'][] = $c['id'];
    array_splice($j['mao'], $i, 1);
    unset($j);
    lendasComprar($e, $lado);   // usou, comprou outra na hora
    return null;
}

function lendasMover(array &$e, string $lado, int $id, int $x, int $y): ?string
{
    $u = &lendasUnidade($e, $id);
    if (!$u || $u['dono'] !== $lado) return 'Herói inválido.';
    if (!in_array([$x, $y], lendasAlcanceMov($e, $u), true)) return 'Esse herói não chega lá neste turno.';
    $de = [$u['x'], $u['y']];
    $u['x'] = $x; $u['y'] = $y; $u['moveu'] = true;
    $e['jogadores'][$lado]['feito']['mover'] = true;
    lendasEvento($e, 'moveu', ['unidade' => $id, 'de' => $de, 'para' => [$x, $y]]);
    return null;
}

function lendasAtacar(array &$e, string $lado, int $id, $alvo): ?string
{
    $u = &lendasUnidade($e, $id);
    if (!$u || $u['dono'] !== $lado) return 'Herói inválido.';
    $alvos = lendasAlvos($e, $u);
    $alvo = $alvo === 'fortaleza' ? 'fortaleza' : (int)$alvo;
    if (!in_array($alvo, $alvos, true)) return 'Esse alvo está fora do alcance.';
    $andou = $u['moveu'];
    $u['atacou'] = true;
    $u['moveu'] = true;
    $e['jogadores'][$lado]['feito']['atacar'] = true;

    $d20 = lendasRand($e, 1, 20);
    $atq = lendasAtq($u);
    // Investida: o guerreiro que andou neste turno bate mais forte.
    if ($u['hab'] === 'Investida' && $andou) $atq++;
    $dano = $d20 === 1 ? 0 : ($d20 >= $u['crit'] ? $atq * 2 : $atq);
    $resultado = $d20 === 1 ? 'erro' : ($d20 >= $u['crit'] ? 'critico' : 'acerto');
    lendasEvento($e, 'ataque', ['unidade' => $id, 'alvo' => $alvo, 'd20' => $d20, 'resultado' => $resultado]);
    $atacante = $u;
    unset($u);

    if ($alvo === 'fortaleza') {
        lendasFerirFortaleza($e, lendasOutro($lado), $dano, $lado);
        return null;
    }
    $alvoAntes = lendasUnidade($e, $alvo);
    $feriu = lendasFerir($e, $alvo, $dano, $lado, true);


    // Revide: quem leva um golpe corpo a corpo e sobrevive devolve metade do
    // ATQ. O Ladino (Furtivo) bate e sai sem levar o troco.
    $vivo = lendasUnidade($e, $alvo);
    if ($vivo && !$vivo['travado'] && $atacante['hab'] !== 'Furtivo' && lendasDist([$atacante['x'], $atacante['y']], [$alvoAntes['x'], $alvoAntes['y']]) === 1) {
        $revide = intdiv(lendasAtq($vivo), 2);
        if ($revide > 0) {
            lendasEvento($e, 'revide', ['unidade' => $alvo, 'alvo' => $id]);
            lendasFerir($e, $id, $revide, lendasOutro($lado), true);
        }
    }
    return null;
}

/**
 * Dano num herói. Escudo anula ataques (não efeitos de vilão), e o Tanque
 * vizinho (Muralha) tira 1 de cada golpe. Devolve o dano que entrou.
 */
function lendasFerir(array &$e, int $id, int $dano, string $quemFez, bool $ehAtaque): int
{
    $u = &lendasUnidade($e, $id);
    if (!$u || $dano <= 0) return 0;
    if ($ehAtaque && $u['escudo'] > 0) {
        $u['escudo']--;
        lendasEvento($e, 'escudo', ['unidade' => $id]);
        return 0;
    }
    foreach ($e['unidades'] as $v) {
        if ($v['dono'] === $u['dono'] && $v['id'] !== $id && $v['hab'] === 'Muralha'
            && lendasDist([$v['x'], $v['y']], [$u['x'], $u['y']]) === 1) { $dano = max(0, $dano - 1); break; }
    }
    $u['vida'] -= $dano;
    lendasEvento($e, 'dano', ['unidade' => $id, 'n' => $dano, 'vida' => max(0, $u['vida'])]);
    if ($u['vida'] <= 0) {
        $dono = $u['dono'];
        $carta = $u['carta'];
        unset($u);
        $e['unidades'] = array_values(array_filter($e['unidades'], fn($x) => $x['id'] !== $id));
        $e['jogadores'][$dono]['descarte'][] = $carta;
        $e['jogadores'][$dono]['caidos'] = ($e['jogadores'][$dono]['caidos'] ?? 0) + 1;
        lendasEvento($e, 'caiu', ['unidade' => $id, 'lado' => $dono]);
    }
    return $dano;
}

function lendasFerirFortaleza(array &$e, string $lado, int $dano, string $quemFez): void
{
    if ($dano <= 0) return;
    $e['jogadores'][$lado]['fortaleza'] = max(0, $e['jogadores'][$lado]['fortaleza'] - $dano);
    lendasEvento($e, 'fortaleza', ['lado' => $lado, 'n' => $dano, 'vida' => $e['jogadores'][$lado]['fortaleza']]);
    if ($e['jogadores'][$lado]['fortaleza'] <= 0) lendasEncerrar($e, $quemFez, 'fortaleza');
}

function lendasEncerrar(array &$e, string $vencedor, string $motivo): void
{
    if ($e['fim']) return;
    $e['fim'] = true;
    $e['vencedor'] = $vencedor;
    $e['motivo'] = $motivo;
    lendasEvento($e, 'fim', ['vencedor' => $vencedor, 'motivo' => $motivo]);
}

/**
 * O que cada lado pode ver. A mão e o baralho do rival são segredo: dele,
 * só se sabe quantas cartas tem.
 */
function lendasVisao(array $e, string $lado): array
{
    $v = $e;
    unset($v['rng']);
    $outro = lendasOutro($lado);
    $v['jogadores'][$outro]['mao'] = count($e['jogadores'][$outro]['mao']);
    foreach (['A', 'B'] as $l) {
        unset($v['jogadores'][$l]['baralho_original']);
        $v['jogadores'][$l]['baralho'] = count($e['jogadores'][$l]['baralho']);
        $v['jogadores'][$l]['descarte'] = count($e['jogadores'][$l]['descarte']);
    }
    return $v;
}
