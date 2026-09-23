<?php
/**
 * O TEMPO PASSANDO — evolução, declínio, base e aposentadoria.
 *
 * É o que transforma uma temporada isolada numa carreira. Sem este arquivo o
 * elenco de hoje é o elenco de sempre: ninguém melhora, ninguém envelhece de
 * verdade, ninguém pendura as chuteiras, e a única forma de mudar o time é
 * comprando. Com ele, o garoto de 18 que você segurou vira o craque de 24, o
 * ídolo de 33 vira lembrança, e todo ano sobe alguém da base.
 *
 * ── O POTENCIAL É O QUE SEPARA UM JOVEM DO OUTRO ─────────────────────
 *
 * Dois meias de 19 anos com OVR 62 não são a mesma coisa: um vai chegar a 80 e
 * o outro para nos 68. O jogador não vê esse número — ele vê a idade, o OVR e
 * o preço, e aposta. É essa aposta que faz a base e o mercado de jovens terem
 * graça; mostrar o potencial na tela mataria a decisão.
 *
 * O potencial é DETERMINÍSTICO (semeado no nome), pelo mesmo motivo de todo o
 * resto: o mesmo jogador precisa ser o mesmo jogador toda vez que o jogo abre.
 */

require_once __DIR__ . '/fut_elencos.php';

/** A partir daqui o jogador começa a pensar em parar, e aqui ele para. */
const FUT_IDADE_APOSENTA_INICIO = 33;
const FUT_IDADE_APOSENTA_FIM = 39;

/**
 * Quantos jogadores a base entrega por temporada.
 *
 * O MÍNIMO SOBE QUANDO O ELENCO ENCOLHE. Numa temporada de teste, seis
 * jogadores se aposentaram de uma vez e a base repôs dois: o elenco caiu de
 * 25 pra 21 e continuaria caindo até bater no mínimo pra competir. Clube com
 * elenco curto promove mais, que é o que acontece de verdade.
 */
const FUT_BASE_MIN = 1;
const FUT_BASE_MAX = 3;
const FUT_ELENCO_CONFORTAVEL = 23;

/**
 * O POTENCIAL de um jogador: o teto de OVR que ele pode alcançar.
 *
 * Sai do OVR atual mais uma margem que depende da idade — quanto mais novo,
 * mais espaço pra crescer — e de um sorteio estável no nome. Um jogador de 28
 * já está praticamente no teto dele; um de 17 pode dobrar de valor.
 */
function futPotencialDe(array $j): int
{
    $idade = (int)($j['idade'] ?? 25);
    $ovr = (int)($j['ovr'] ?? 60);

    // Um número estável de 0 a 99 pra este jogador.
    $dado = crc32('pot|' . ($j['nome'] ?? '')) % 100;

    /* A margem máxima por idade. Aos 17 dá pra crescer 25 pontos; aos 26 quase
       nada. Não é linear: a maior parte do crescimento acontece cedo. */
    $margem = match (true) {
        $idade <= 17 => 25,
        $idade <= 19 => 21,
        $idade <= 21 => 16,
        $idade <= 23 => 11,
        $idade <= 25 => 6,
        $idade <= 27 => 3,
        default      => 1,
    };

    /* A maioria não vira craque. A curva é puxada pra baixo de propósito: 60%
       dos jovens usam menos da metade da margem, e só uns 10% chegam perto do
       teto. Se todo garoto virasse estrela, segurar a base não seria uma
       aposta — seria só esperar. */
    $fatia = $dado >= 90 ? 1.0 : ($dado >= 70 ? 0.65 : ($dado >= 40 ? 0.35 : 0.15));

    return (int)min(99, $ovr + round($margem * $fatia));
}

/**
 * QUANTO O JOGADOR MUDA DE OVR NUMA TEMPORADA.
 *
 * Três forças ao mesmo tempo: a curva de idade (que já existia), o quanto
 * falta pro potencial dele, e o desempenho do ano. Jogador jovem com potencial
 * folgado e boa temporada dá um salto; veterano cai independentemente do que
 * faça.
 *
 * @param array $stats estatística da temporada do jogador (pode vir vazia)
 */
function futEvoluirJogador(array $j, array $stats = []): array
{
    $idadeAntes = (int)$j['idade'];
    $idadeDepois = $idadeAntes + 1;
    $ovr = (int)$j['ovr'];
    $potencial = (int)($j['potencial'] ?? futPotencialDe($j));

    // ── 1. O degrau da curva de carreira ─────────────────────────────
    $delta = futCurvaDaIdade($idadeDepois) - futCurvaDaIdade($idadeAntes);

    // ── 2. O espaço que falta até o potencial ────────────────────────
    $espaco = $potencial - $ovr;
    if ($espaco > 0 && $idadeDepois <= 27) {
        /* Quem ainda tem pra onde crescer cresce, e cresce mais rápido quanto
           maior o espaço — mas nunca tudo de uma vez: chegar ao teto leva
           temporadas, e é isso que faz valer a pena segurar o garoto.

           O TETO DE 6 POR ANO é o que segura isso. Sem ele, um garoto de 16
           com potencial folgado saltava 14 pontos numa temporada e chegava
           pronto aos 18 — o que apaga justamente a espera que torna a base
           interessante. */
        $delta += min(6, max(1, (int)round($espaco * 0.25)));
    }

    // ── 3. A temporada que ele fez ───────────────────────────────────
    $jogos = (int)($stats['jogos'] ?? 0);
    if ($jogos >= 10) {
        $nota = futNotaMedia($stats);
        if ($nota >= 7.2)      $delta += 2;
        elseif ($nota >= 6.6)  $delta += 1;
        elseif ($nota < 5.8)   $delta -= 1;
    } elseif ($jogos === 0 && $idadeDepois <= 23) {
        // Garoto que não jogou não evolui: banco não forma ninguém.
        $delta -= 1;
    }

    $novo = $ovr + $delta;

    /* O POTENCIAL É TETO DE VERDADE enquanto o jogador está crescendo. Sem
       esta linha ele passava por cima do próprio limite quando o degrau da
       idade se somava ao crescimento, e o número deixava de significar
       alguma coisa. Depois dos 27 o teto não trava nada: aí só se cai. */
    if ($delta > 0 && $idadeDepois <= 27) $novo = min($novo, $potencial);

    $j['idade'] = $idadeDepois;
    $j['ovr'] = (int)max(25, min(99, $novo));
    $j['potencial'] = $potencial;
    return $j;
}

/**
 * O JOGADOR SE APOSENTA no fim desta temporada?
 *
 * Idade manda, mas não sozinha: quem ainda joga bem segura mais, quem caiu
 * muito para antes. É o que evita tanto o goleiro de 41 quanto a debandada de
 * todo mundo aos 34 no mesmo ano.
 */
function futVaiAposentar(array $j): bool
{
    $idade = (int)$j['idade'];
    if ($idade < FUT_IDADE_APOSENTA_INICIO) return false;
    if ($idade >= FUT_IDADE_APOSENTA_FIM) return true;

    $ovr = (int)$j['ovr'];

    /* A chance cresce com a idade e cai com o OVR. Um 80 aos 35 continua; um
       55 aos 35 vai embora. Goleiro dura mais, como na vida real.

       O passo era 16 e esvaziava elenco: seis jogadores saíam no mesmo ano de
       um clube pequeno, que é onde justamente há mais veterano barato. Com 11
       a saída fica espalhada por duas ou três temporadas. */
    $chance = ($idade - FUT_IDADE_APOSENTA_INICIO + 1) * 11;   // 11%, 22%, 33%...
    $chance -= max(0, $ovr - 58);                              // qualidade segura
    if (($j['pos'] ?? '') === 'GOL') $chance -= 20;

    return mt_rand(0, 99) < max(0, min(100, $chance));
}

/**
 * Um jogador NOVO vindo da base do clube.
 *
 * O OVR sai bem abaixo do nível do clube — ninguém sobe pronto — mas o
 * potencial pode ser alto. É o prêmio de quem tem paciência: às vezes sobe um
 * que em quatro temporadas vale mais que o elenco inteiro do clube pequeno.
 */
function futJogadorDaBase(string $clube, int $forcaClube, int $ano, int $indice): array
{
    // Semente estável: o mesmo clube no mesmo ano entrega os mesmos garotos.
    $semente = crc32('base|' . $clube . '|' . $ano . '|' . $indice) & 0xFFFFFFFF;
    if ($semente === 0) $semente = 1;

    $posicoes = array_keys(FUT_POSICOES);
    $pos = $posicoes[futSorteio($semente, 0, count($posicoes) - 1)];
    $idade = futSorteio($semente, 16, 19);

    /* O garoto entra entre 18 e 8 pontos abaixo do clube. O piso de 32 evita
       um jogador inútil num clube de várzea. */
    $ovr = (int)max(32, $forcaClube - futSorteio($semente, 8, 18));

    $usados = [];
    $nome = futNomeGenerico($semente, $usados);

    $j = ['nome' => $nome, 'pos' => $pos, 'ovr' => $ovr, 'idade' => $idade, 'num' => 0,
          'energia' => 100, 'moral' => 75, 'lesao' => 0];
    $j['potencial'] = futPotencialDe($j);
    return $j;
}

/**
 * PASSA UM ANO NO ELENCO: envelhece, evolui, aposenta e chama a base.
 *
 * @return array ['elenco'=>array, 'aposentados'=>array, 'novos'=>array, 'evolucao'=>array]
 */
function futPassarAnoNoElenco(array $elenco, array $stats, string $clube, int $forcaClube, int $ano): array
{
    $novoElenco = [];
    $aposentados = [];
    $evolucao = [];

    foreach ($elenco as $j) {
        $antes = (int)$j['ovr'];
        $j = futEvoluirJogador($j, $stats[$j['nome']] ?? []);

        if (futVaiAposentar($j)) {
            $aposentados[] = $j;
            continue;
        }
        $evolucao[$j['nome']] = ['antes' => $antes, 'depois' => (int)$j['ovr'],
                                 'delta' => (int)$j['ovr'] - $antes, 'idade' => (int)$j['idade']];
        $novoElenco[] = $j;
    }

    // ── A base entrega os garotos do ano ─────────────────────────────
    $quantos = mt_rand(FUT_BASE_MIN, FUT_BASE_MAX);
    // Elenco curto puxa mais gente da base, até o tamanho confortável.
    $faltando = FUT_ELENCO_CONFORTAVEL - count($novoElenco);
    if ($faltando > 0) $quantos = max($quantos, $faltando);
    $novos = [];
    $existentes = [];
    foreach ($novoElenco as $j) $existentes[$j['nome']] = true;

    for ($i = 0; $i < $quantos; $i++) {
        if (count($novoElenco) >= FUT_ELENCO_MAXIMO) break;
        $g = futJogadorDaBase($clube, $forcaClube, $ano, $i);
        if (isset($existentes[$g['nome']])) continue;   // nome repetido quebra as chaves
        $existentes[$g['nome']] = true;
        $novos[] = $g;
        $novoElenco[] = $g;
    }

    // Renumera: o melhor de cada posição fica com a camisa de sempre.
    $novoElenco = futRenumerar($novoElenco);

    return ['elenco' => $novoElenco, 'aposentados' => $aposentados,
            'novos' => $novos, 'evolucao' => $evolucao];
}

/** Dá as camisas de novo, na mesma régua da geração do elenco. */
function futRenumerar(array $elenco): array
{
    $ordem = array_keys(FUT_POSICOES);
    usort($elenco, function ($a, $b) use ($ordem) {
        return [array_search($a['pos'], $ordem, true), -(int)$a['ovr']]
           <=> [array_search($b['pos'], $ordem, true), -(int)$b['ovr']];
    });
    $n = 1;
    foreach ($elenco as &$j) $j['num'] = $n++;
    unset($j);
    return $elenco;
}
