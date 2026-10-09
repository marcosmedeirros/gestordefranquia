<?php
/**
 * ── FBA HOOPS: O "ULTIMATE TEAM" DO BASQUETE ─────────────────────────
 *
 * Pedido do Victor (08/10/2026): um mini Ultimate Team de basquete. Doze
 * vagas, e a cada uma um pacotinho abre com cinco cartas — você fica com
 * uma. Com os doze, o jogo simula uma temporada da NBA inteira, 82 jogos e
 * playoffs, e paga em moedas conforme a fase a que o time chegou.
 *
 * As cartas vêm de games/data/hoops_cartas.php (NBA, WNBA, EuroLeague e
 * EuroCup, @see hoops_importar_cli.php). A temporada mora em
 * hoops_temporada.php. Aqui fica o que é do draft: o pacote, a química e
 * a força do time.
 *
 * ── DIFÍCIL, MAS NÃO IMPOSSÍVEL ──────────────────────────────────────
 *
 * Como o prêmio cresce com a fase, o jogo PRECISA ser difícil — pedido
 * explícito do Victor. As alavancas estão todas aqui, e foram medidas com
 * milhares de drafts simulados (@see hoops_calibrar_cli.php), não chutadas:
 *
 *   1. HOOPS_RARIDADE_*: o pacote é quase todo prata e bronze. Carta de 90
 *      é evento, não rotina.
 *   2. Os 29 adversários são os elencos REAIS da NBA. E quem você draftou
 *      sai do time de verdade dele — pegar o Jokić enfraquece Denver, mas
 *      o resto da liga continua inteira.
 *   3. A química e o equilíbrio do quinteto mexem na força. Doze bons
 *      jogadores de quatro ligas diferentes e sem ninguém pra proteger o
 *      aro perdem pra um time pior e bem montado.
 */

require_once __DIR__ . '/hoops_notas.php';

/** Cartas por pacote. */
const HOOPS_OPCOES = 5;

/** Os cinco titulares, na ordem da quadra. As outras sete vagas são o banco. */
const HOOPS_TITULARES = ['PG', 'SG', 'SF', 'PF', 'C'];
const HOOPS_VAGAS     = 12;

/**
 * Os minutos de cada vaga num jogo de 240. Titular joga 32 a 34; o sexto
 * homem, 22; o décimo segundo quase não sai do banco. É por isso que a
 * ordem do banco importa: quem está na vaga 6 joga dez vezes mais que quem
 * está na 12.
 */
const HOOPS_MINUTOS = [34, 34, 33, 33, 32, 22, 18, 14, 10, 6, 3, 1];

/**
 * A chance de cada raridade aparecer em cada carta do pacote, POR MIL.
 * Titular e banco têm pacotes diferentes: o banco vem mais fraco, que é o
 * que faz reserva parecer reserva.
 *
 *   ícone ≥ 90 · ouro 80–89 · prata 70–79 · bronze < 70
 *
 * A primeira versão (3% de ícone e 22% de ouro no titular) dava título em
 * 37% das temporadas de quem escolhe bem — o pacote entregava um quinteto
 * melhor que o do Cleveland. Os números de agora saíram da calibração.
 */
const HOOPS_RARIDADE_TITULAR = ['icone' => 10, 'ouro' => 100, 'prata' => 450, 'bronze' => 440];
const HOOPS_RARIDADE_BANCO   = ['icone' => 5, 'ouro' => 60, 'prata' => 435, 'bronze' => 500];

/** Conferências da NBA, pelas siglas da ESPN. */
const HOOPS_LESTE = ['ATL', 'BKN', 'BOS', 'CHA', 'CHI', 'CLE', 'DET', 'IND', 'MIA', 'MIL', 'NY', 'ORL', 'PHI', 'TOR', 'WSH'];
const HOOPS_OESTE = ['DAL', 'DEN', 'GS', 'HOU', 'LAC', 'LAL', 'MEM', 'MIN', 'NO', 'OKC', 'PHX', 'POR', 'SA', 'SAC', 'UTAH'];

// ════════════════════════════ O BARALHO ═════════════════════════════

/** Todas as cartas e times. Lido uma vez por request. */
function hoopsDados(): array
{
    static $dados = null;
    if ($dados === null) {
        $arq = __DIR__ . '/../data/hoops_cartas.php';
        $dados = is_file($arq) ? require $arq : ['cartas' => [], 'times' => []];
        foreach ($dados['cartas'] as &$c) $c['rar'] = hoopsRaridade($c['ovr']);
        unset($c);
    }
    return $dados;
}

/** Cartas por id. */
function hoopsCartasPorId(): array
{
    static $porId = null;
    if ($porId === null) {
        $porId = [];
        foreach (hoopsDados()['cartas'] as $c) $porId[$c['id']] = $c;
    }
    return $porId;
}

/** O time (escudo, cores) de uma carta. */
function hoopsTimeDaCarta(array $c): array
{
    return hoopsDados()['times'][$c['liga'] . '|' . $c['time_sigla']]
        ?? ['sigla' => $c['time_sigla'], 'nome' => $c['time'], 'curto' => $c['time'], 'logo' => $c['time_logo'], 'cor' => '#444', 'cor2' => '#aaa'];
}

/** A posição da vaga, ou null pro banco (que aceita qualquer uma). */
function hoopsPosDaVaga(int $vaga): ?string
{
    return HOOPS_TITULARES[$vaga] ?? null;
}

/** A carta joga na posição da vaga? (a segunda posição também vale) */
function hoopsNaPosicao(array $carta, int $vaga): bool
{
    $pos = hoopsPosDaVaga($vaga);
    return $pos === null || $carta['pos'] === $pos || ($carta['pos2'] ?? null) === $pos;
}

/**
 * Abre um pacote: cinco cartas pra uma vaga.
 *
 * Cada carta sorteia primeiro a RARIDADE (pelas chances da tabela) e depois
 * uma carta daquela raridade que sirva na vaga. Se não houver nenhuma na
 * raridade sorteada (os ícones de pivô, por exemplo, são poucos), desce pra
 * próxima — nunca sobe, senão a falta de carta viraria presente.
 *
 * As cinco voltam em ordem crescente de OVR: a melhor é a última a virar,
 * como no pacote do FIFA.
 *
 * @param string[] $usados ids que já estão no time
 */
function hoopsAbrirPacote(int $vaga, array $usados): array
{
    $pos = hoopsPosDaVaga($vaga);
    $chances = $pos === null ? HOOPS_RARIDADE_BANCO : HOOPS_RARIDADE_TITULAR;

    $porRar = ['icone' => [], 'ouro' => [], 'prata' => [], 'bronze' => []];
    $fora = array_flip($usados);
    foreach (hoopsDados()['cartas'] as $c) {
        if (isset($fora[$c['id']])) continue;
        if ($pos !== null && $c['pos'] !== $pos && ($c['pos2'] ?? null) !== $pos) continue;
        $porRar[$c['rar']][] = $c;
    }

    $ordem = array_keys($porRar);
    $saida = [];
    $pegos = [];
    for ($n = 0, $tent = 0; $n < HOOPS_OPCOES && $tent < 200; $tent++) {
        $r = random_int(1, array_sum($chances));
        $rar = 'bronze';
        foreach ($chances as $k => $peso) { if ($r <= $peso) { $rar = $k; break; } $r -= $peso; }
        for ($i = array_search($rar, $ordem, true); $i < count($ordem); $i++) {
            $lista = $porRar[$ordem[$i]];
            if (!$lista) continue;
            $c = $lista[random_int(0, count($lista) - 1)];
            if (isset($pegos[$c['id']])) break;
            $pegos[$c['id']] = true;
            $saida[] = $c;
            $n++;
            break;
        }
    }
    /* PACOTE DE TITULAR TRAZ AO MENOS UMA PRATA. Cinco bronzes pro quinteto
       acontece em ~1,6% dos pacotes, e no teste apareceu logo no primeiro:
       é o tipo de azar que não ensina nada, só frustra. A pior carta dá lugar
       a uma prata — e a calibração foi refeita já contando com isso. */
    if ($pos !== null && $saida && $porRar['prata'] && !array_filter($saida, fn($c) => $c['rar'] !== 'bronze')) {
        $livres = array_values(array_filter($porRar['prata'], fn($c) => !isset($pegos[$c['id']])));
        if ($livres) {
            usort($saida, fn($a, $b) => $a['ovr'] <=> $b['ovr']);
            $saida[0] = $livres[random_int(0, count($livres) - 1)];
        }
    }

    /* A CHANCE DO NBB — pedido do Victor (09/10/2026): 25% a mais de vir pelo
       menos um jogador do NBB em todo pacote — 25%, e depois 15%, porque com
       25 o NBB vinha em quase um terço dos pacotes. São só vinte cartas num baralho
       de quase 1.300; sem o empurrão, um draft inteiro passava sem ver
       nenhuma. Se o pacote ainda não tem ninguém do NBB, a pior carta dá
       lugar a um deles — dos que jogam na vaga (no banco, qualquer um). */
    if ($saida && random_int(1, 100) <= HOOPS_CHANCE_NBB
        && !array_filter($saida, fn($c) => $c['liga'] === 'NBB')) {
        $nbb = [];
        foreach ($porRar as $lista) foreach ($lista as $c) {
            if ($c['liga'] === 'NBB' && !isset($pegos[$c['id']])) $nbb[] = $c;
        }
        if ($nbb) {
            usort($saida, fn($a, $b) => $a['ovr'] <=> $b['ovr']);
            $saida[0] = $nbb[random_int(0, count($nbb) - 1)];
        }
    }

    usort($saida, fn($a, $b) => $a['ovr'] <=> $b['ovr']);
    return $saida;
}

/** A chance extra, em %, de um pacote trazer pelo menos um jogador do NBB. */
const HOOPS_CHANCE_NBB = 15;

// ════════════════════════════ A QUÍMICA ═════════════════════════════

/**
 * ── A QUÍMICA DO BASQUETE ────────────────────────────────────────────
 *
 * A mesma ideia do FBA Draft (que é a do EA FC): o que conta é QUANTOS no
 * elenco inteiro dividem time ou liga — não quem está do lado de quem.
 * Cada jogador vale de 0 a 3; o elenco, de 0 a 36.
 *
 *   mesmo time ...  2 jogadores = 1 ponto · 3 = 2 · 4 = 3
 *   mesma liga ...  3 jogadores = 1 ponto · 5 = 2 · 8 = 3
 *
 * Nação ficou de fora de propósito: com NBA e WNBA no baralho, quase todo
 * mundo é americano, e química que todo mundo tem de graça não é escolha.
 *
 * Titular fora de posição fica com 0 e SAI da contagem dos outros — é o
 * preço de enfiar o craque no lugar errado. O banco não tem posição, então
 * ninguém do banco está fora dela.
 */
const HOOPS_LIMIAR_TIME = [2, 3, 4];
const HOOPS_LIMIAR_LIGA = [3, 5, 8];

function hoopsPontosLimiar(int $quantos, array $limiar): int
{
    $p = 0;
    foreach ($limiar as $l) if ($quantos >= $l) $p++;
    return $p;
}

/**
 * @param array<int,?array> $time as doze vagas (null = vazia)
 * @return array{total:int, jogadores:array<int,int>}
 */
function hoopsQuimica(array $time): array
{
    $contaTime = [];
    $contaLiga = [];
    foreach ($time as $v => $c) {
        if (!$c || !hoopsNaPosicao($c, $v)) continue;
        $kt = $c['liga'] . '|' . $c['time_sigla'];
        $contaTime[$kt] = ($contaTime[$kt] ?? 0) + 1;
        $contaLiga[$c['liga']] = ($contaLiga[$c['liga']] ?? 0) + 1;
    }
    $porJogador = [];
    $total = 0;
    foreach ($time as $v => $c) {
        if (!$c) continue;
        if (!hoopsNaPosicao($c, $v)) { $porJogador[$v] = 0; continue; }
        $q = hoopsPontosLimiar($contaTime[$c['liga'] . '|' . $c['time_sigla']], HOOPS_LIMIAR_TIME)
           + hoopsPontosLimiar($contaLiga[$c['liga']], HOOPS_LIMIAR_LIGA);
        $porJogador[$v] = min(3, $q);
        $total += $porJogador[$v];
    }
    return ['total' => $total, 'jogadores' => $porJogador];
}

// ═════════════════════════ A FORÇA DO TIME ══════════════════════════

/**
 * O que a química de um jogador faz com o OVR dele em quadra.
 *
 * Era -3/-1/+0,5/+1,5, e um time sem química nenhuma chegou à final no
 * teste do Victor (08/10/2026): "mesmo sem química, não era pra isso
 * acontecer". Agora o jogador isolado joga como um reserva de si mesmo.
 * Os times reais da NBA têm química cheia (3) — é contra +1,5 que o time
 * montado no pacote disputa.
 */
const HOOPS_QUIMICA_AJUSTE = [0 => -5.0, 1 => -2.5, 2 => 0.0, 3 => 1.5];

/** Titular fora de posição joga como se fosse este tanto pior. */
const HOOPS_FORA_DE_POSICAO = 6;

/**
 * ── O EQUILÍBRIO DO QUINTETO ─────────────────────────────────────────
 *
 * Basquete não é soma de OVR. Um quinteto sem ninguém que arme, sem
 * ninguém que proteja o aro ou sem arremesso de fora trava, e o jogo cobra
 * isso. São três perguntas sobre os titulares, e cada uma dá bônus ou
 * pênalti. É o que faz valer a pena pegar o pivô 82 que dá toco no lugar
 * do ala 85 que não defende.
 */
function hoopsEquilibrio(array $titulares): array
{
    $maxAt = fn(string $at) => $titulares ? max(array_map(fn($c) => $c['at'][$at], $titulares)) : 0;
    $quantos = fn(string $at, int $min) => count(array_filter($titulares, fn($c) => $c['at'][$at] >= $min));

    $itens = [];
    $pas = $maxAt('pas');
    $itens['armacao'] = ['label' => 'Armação', 'valor' => $pas >= 80 ? 1.5 : ($pas < 72 ? -2.5 : 0.0),
                         'dica' => 'um titular com Passe 80+'];
    $dga = $maxAt('dgar');
    $itens['aro'] = ['label' => 'Proteção do aro', 'valor' => $dga >= 80 ? 1.5 : ($dga < 72 ? -2.5 : 0.0),
                     'dica' => 'um titular com Defesa de garrafão 80+'];
    $chutadores = $quantos('arr3', 75);
    $itens['espaco'] = ['label' => 'Espaçamento', 'valor' => $chutadores >= 3 ? 1.5 : ($chutadores <= 1 ? -2.5 : 0.0),
                        'dica' => 'três titulares com Arremesso de 3 75+'];
    $reb = $titulares ? array_sum(array_map(fn($c) => $c['at']['reb'], $titulares)) / count($titulares) : 0;
    $itens['rebote'] = ['label' => 'Rebote', 'valor' => $reb >= 70 ? 1.0 : ($reb < 58 ? -1.0 : 0.0),
                        'dica' => 'média de Rebote 70+ no quinteto (abaixo de 58 pesa contra)'];

    return ['total' => array_sum(array_column($itens, 'valor')), 'itens' => $itens];
}

/**
 * A força do time: o OVR de cada um, mexido pela química e pela posição,
 * pesado pelos minutos que ele joga, mais o equilíbrio do quinteto.
 *
 * @param array<int,?array> $time       as doze vagas, na ordem
 * @param array<int,int>|null $quimica  química por vaga (null = calcula)
 */
function hoopsForca(array $time, ?array $quimica = null): array
{
    $quimica ??= hoopsQuimica($time)['jogadores'];
    $soma = 0.0;
    $minutos = 0;
    $titulares = [];
    foreach ($time as $v => $c) {
        if (!$c) continue;
        $ovr = $c['ovr'] + HOOPS_QUIMICA_AJUSTE[$quimica[$v] ?? 0];
        if (!hoopsNaPosicao($c, $v)) $ovr -= HOOPS_FORA_DE_POSICAO;
        $soma += $ovr * HOOPS_MINUTOS[$v];
        $minutos += HOOPS_MINUTOS[$v];
        if ($v < count(HOOPS_TITULARES)) $titulares[] = $c;
    }
    $eq = hoopsEquilibrio($titulares);
    $base = $minutos > 0 ? $soma / $minutos : 0;
    return ['forca' => round($base + $eq['total'], 1), 'base' => round($base, 1), 'equilibrio' => $eq];
}

// ═══════════════════════════ OS ADVERSÁRIOS ═════════════════════════

/**
 * Os 30 times da NBA, montados com as cartas do elenco real.
 *
 * Quem o GM draftou SAI do time de verdade. Os doze que sobram entram por
 * OVR; o quinteto é escolhido pra cobrir as cinco posições sempre que o
 * elenco permite (é o que um técnico faria), e a química de um time real
 * é a de quem joga junto: cheia.
 *
 * @param string[] $foraIds ids das cartas que estão no time do GM
 * @return array<string,array> sigla → [sigla, nome, curto, logo, cor, conf, forca, elenco]
 */
function hoopsTimesNba(array $foraIds = []): array
{
    $fora = array_flip($foraIds);
    $porTime = [];
    foreach (hoopsDados()['cartas'] as $c) {
        if ($c['liga'] !== 'NBA' || isset($fora[$c['id']])) continue;
        $porTime[$c['time_sigla']][] = $c; // já vêm ordenadas por OVR
    }

    $times = [];
    foreach (array_merge(HOOPS_LESTE, HOOPS_OESTE) as $sigla) {
        $elenco = array_slice($porTime[$sigla] ?? [], 0, HOOPS_VAGAS);
        $time = hoopsEscalarMelhor($elenco);
        $quim = array_fill(0, HOOPS_VAGAS, 3);
        $f = hoopsForca($time, $quim);
        $info = hoopsDados()['times']['NBA|' . $sigla] ?? ['nome' => $sigla, 'curto' => $sigla, 'logo' => '', 'cor' => '#444'];
        $times[$sigla] = [
            'sigla'  => $sigla,
            'nome'   => $info['nome'],
            'curto'  => $info['curto'],
            'logo'   => $info['logo'],
            'cor'    => $info['cor'],
            'conf'   => in_array($sigla, HOOPS_LESTE, true) ? 'L' : 'O',
            'forca'  => $f['forca'],
            'elenco' => $time,
        ];
    }
    return $times;
}

/**
 * Põe doze cartas nas doze vagas: pra cada posição do quinteto, o melhor
 * disponível que joga nela; se ninguém joga, o melhor que sobrou. O banco
 * é o resto, por OVR.
 */
function hoopsEscalarMelhor(array $cartas): array
{
    $time = array_fill(0, HOOPS_VAGAS, null);
    $livres = $cartas;
    foreach (HOOPS_TITULARES as $v => $pos) {
        $escolhido = null;
        foreach ($livres as $i => $c) {
            if ($c['pos'] === $pos || ($c['pos2'] ?? null) === $pos) { $escolhido = $i; break; }
        }
        if ($escolhido === null && $livres) $escolhido = array_key_first($livres);
        if ($escolhido === null) break;
        $time[$v] = $livres[$escolhido];
        unset($livres[$escolhido]);
    }
    $v = count(HOOPS_TITULARES);
    foreach ($livres as $c) { if ($v >= HOOPS_VAGAS) break; $time[$v++] = $c; }
    return $time;
}

// ═══════════════════════════ PARA A TELA ════════════════════════════

/** O pedaço da carta que vai pro navegador. */
function hoopsCartaParaTela(array $c): array
{
    $t = hoopsTimeDaCarta($c);
    return [
        'id' => $c['id'], 'nome' => $c['nome'], 'ovr' => $c['ovr'], 'rar' => $c['rar'] ?? hoopsRaridade($c['ovr']),
        'pos' => $c['pos'], 'pos2' => $c['pos2'] ?? null, 'liga' => $c['liga'],
        'time' => $t['curto'] ?? $c['time'], 'time_sigla' => $c['time_sigla'], 'logo' => $c['time_logo'],
        'cor' => $t['cor'] ?? '#444', 'foto' => $c['foto'], 'pais' => $c['pais'], 'idade' => $c['idade'],
        'altura' => $c['altura'], 'at' => $c['at'], 'linha' => $c['linha'],
    ];
}
