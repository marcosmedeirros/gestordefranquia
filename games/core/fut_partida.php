<?php
/**
 * A PARTIDA MINUTO A MINUTO — quem fez, quem deu, quem levou cartão.
 *
 * O fut_motor.php entrega um placar. Um placar não é uma partida: ninguém se
 * lembra de "2 a 1", as pessoas se lembram do gol aos 89. Este arquivo pega o
 * mesmo placar que o motor produziria e o desdobra em acontecimentos com hora
 * marcada e nome — gol, assistência, cartão, expulsão — e daí saem a
 * artilharia, as notas e as suspensões.
 *
 * ── POR QUE O PLACAR VEM PRIMEIRO, E NÃO AO CONTRÁRIO ────────────────
 *
 * Seria mais "natural" sortear lance a lance e deixar o placar emergir. Não é
 * o que este arquivo faz, de propósito: o motor já está calibrado contra o
 * Brasileirão de verdade (47% de vitórias do mandante, 2,5 gols por jogo), e
 * jogar essa calibragem fora pra recomeçar lance a lance significaria
 * recalibrar tudo e provavelmente chegar num resultado pior. Então o placar sai
 * do motor e AQUI ele ganha autores e minutos. O jogador vê uma partida; a
 * estatística da liga continua batendo.
 */

require_once __DIR__ . '/fut_motor.php';
require_once __DIR__ . '/fut_escalacao.php';

/**
 * A chance de cada posição marcar, antes do OVR entrar na conta.
 *
 * É o que faz o artilheiro do campeonato ser um atacante e não um zagueiro. Os
 * números vêm da distribuição real de gols por posição no futebol: o ataque
 * faz a maioria, o meio ajuda, a defesa marca de bola parada e o goleiro
 * quase nunca.
 */
const FUT_PESO_GOL = ['ATA' => 100, 'PON' => 62, 'MEI' => 38, 'VOL' => 14, 'LAT' => 9, 'ZAG' => 8, 'GOL' => 1];

/** A chance de dar a assistência. O meio domina, como na vida. */
const FUT_PESO_ASSIST = ['MEI' => 100, 'PON' => 84, 'LAT' => 58, 'ATA' => 44, 'VOL' => 34, 'ZAG' => 10, 'GOL' => 2];

/** Quem leva cartão: quem desarma. */
const FUT_PESO_CARTAO = ['VOL' => 100, 'ZAG' => 88, 'LAT' => 72, 'MEI' => 55, 'ATA' => 40, 'PON' => 36, 'GOL' => 10];

/** Cartões por time por jogo, na média, e a fatia deles que vira vermelho. */
const FUT_CARTOES_MEDIA = 1.9;
const FUT_CHANCE_VERMELHO = 0.045;

/** Amarelos acumulados que suspendem, e quantos jogos cada punição custa. */
const FUT_AMARELOS_PARA_SUSPENDER = 3;
const FUT_JOGOS_SUSPENSO_VERMELHO = 1;

/**
 * Sorteia um item de uma lista com pesos.
 *
 * @param array $itens lista de [item, peso]
 */
function futSorteioPonderado(array $itens)
{
    $total = 0.0;
    foreach ($itens as [$_, $peso]) $total += max(0, $peso);
    if ($total <= 0) return $itens[0][0] ?? null;

    $alvo = (mt_rand() / mt_getrandmax()) * $total;
    $acum = 0.0;
    foreach ($itens as [$item, $peso]) {
        $acum += max(0, $peso);
        if ($acum >= $alvo) return $item;
    }
    return $itens[count($itens) - 1][0] ?? null;
}

/**
 * O peso de um jogador para um tipo de evento: a posição manda, o OVR ajusta.
 *
 * O OVR entra AO QUADRADO porque a diferença entre um atacante de 90 e um de
 * 70 na hora de fazer gol é muito maior que 20/70. Linear demais e o reserva
 * marcaria quase tanto quanto o craque.
 */
function futPesoDoJogador(array $j, array $tabela): float
{
    $base = $tabela[$j['pos']] ?? 10;
    $fator = pow(max(20, (int)$j['ovr']) / 70, 2.0);
    return $base * $fator;
}

/**
 * Sorteia os minutos dos gols de uma partida.
 *
 * Gols são um pouco mais comuns no fim de cada tempo — time cansado, espaço
 * aberto. Minutos repetidos são evitados pra não sair "dois gols aos 34".
 */
function futMinutosDeGol(int $quantos, int $de = 1, int $ate = 90): array
{
    if ($ate < $de) $ate = $de;
    $minutos = [];
    $tentativas = 0;
    while (count($minutos) < $quantos && $tentativas < $quantos * 20) {
        $tentativas++;
        $m = mt_rand($de, $ate);
        // Um empurrãozinho pro fim dos tempos: sorteia de novo e fica com o maior.
        if (mt_rand(0, 100) < 35) $m = max($m, mt_rand($de, $ate));
        if (in_array($m, $minutos, true)) continue;
        $minutos[] = $m;
    }
    sort($minutos);
    return $minutos;
}

/**
 * Distribui os gols de um time entre os jogadores escalados, com assistências.
 *
 * @return array lista de ['autor','assistente'|null]
 */
function futAutoresDosGols(array $escalados, int $gols): array
{
    if ($gols <= 0 || !$escalados) return [];

    $pesoGol = [];
    foreach ($escalados as $j) $pesoGol[] = [$j, futPesoDoJogador($j, FUT_PESO_GOL)];

    $saida = [];
    for ($g = 0; $g < $gols; $g++) {
        $autor = futSorteioPonderado($pesoGol);
        if (!$autor) continue;

        /* NEM TODO GOL TEM ASSISTÊNCIA — pênalti, chute de fora, jogada
           individual. Dar assistência a 100% dos gols encheria a tabela de
           números que não existem no futebol. */
        $assistente = null;
        if (mt_rand(0, 100) < 68) {
            $pesoAss = [];
            foreach ($escalados as $j) {
                if ($j['nome'] === $autor['nome']) continue;   // ninguém assiste a si mesmo
                $pesoAss[] = [$j, futPesoDoJogador($j, FUT_PESO_ASSIST)];
            }
            if ($pesoAss) $assistente = futSorteioPonderado($pesoAss);
        }
        $saida[] = ['autor' => $autor, 'assistente' => $assistente];
    }
    return $saida;
}

/** Sorteia os cartões de um time. */
/**
 * @param array $jaAmarelados nomes que JÁ levaram amarelo nesta partida. A
 *        partida ao vivo é simulada em pedaços, e sem passar isto adiante cada
 *        pedaço começava com a súmula limpa: o mesmo jogador levava amarelo aos
 *        10 e aos 66 e seguia em campo, quando o segundo amarelo é vermelho.
 */
function futCartoesDoTime(array $escalados, float $fracao = 1.0, int $de = 8, int $ate = 90,
                          array $jaAmarelados = []): array
{
    if (!$escalados) return [];
    if ($ate < $de) $ate = $de;

    $quantos = futPoisson(FUT_CARTOES_MEDIA * max(0.0, $fracao));
    $pesos = [];
    foreach ($escalados as $j) {
        $peso = futPesoDoJogador($j, FUT_PESO_CARTAO);
        // Pendurado se cuida, inclusive o que se pendurou num pedaço anterior.
        if (in_array($j['nome'], $jaAmarelados, true)) $peso *= 0.10;
        $pesos[] = [$j, $peso];
    }

    $cartoes = [];
    $amarelosDe = [];
    foreach ($jaAmarelados as $n) $amarelosDe[$n] = true;
    for ($i = 0; $i < $quantos; $i++) {
        $j = futSorteioPonderado($pesos);
        if (!$j) continue;

        $vermelhoDireto = (mt_rand() / mt_getrandmax()) < FUT_CHANCE_VERMELHO;
        $nome = $j['nome'];

        /* SEGUNDO AMARELO É VERMELHO. Sem isto o jogo mostraria "três amarelos
           pro mesmo jogador", que não existe e denunciaria na hora que os
           cartões são sorteados soltos. */
        $segundo = isset($amarelosDe[$nome]);
        $tipo = ($vermelhoDireto || $segundo) ? 'vermelho' : 'amarelo';
        if ($tipo === 'amarelo') $amarelosDe[$nome] = true;

        $cartoes[] = ['jogador' => $j, 'tipo' => $tipo, 'segundo' => $segundo, 'minuto' => mt_rand($de, $ate)];

        if ($tipo === 'vermelho') {
            // Expulso não leva mais cartão: sai do sorteio.
            $pesos = array_values(array_filter($pesos, fn($x) => $x[0]['nome'] !== $nome));
            if (!$pesos) break;
        } else {
            /* QUEM ESTÁ PENDURADO SE CUIDA — e o técnico costuma tirá-lo. Sem
               esta linha o mesmo jogador voltava ao sorteio com o peso cheio, e
               como os pesos são concentrados (volante e zagueiro levam quase
               tudo) o segundo amarelo saía a cada três jogos: numa temporada de
               50 partidas deram 16 expulsões, quando o normal é 2 ou 3. */
            foreach ($pesos as $k => $x) {
                if ($x[0]['nome'] === $nome) $pesos[$k][1] = $x[1] * 0.10;
            }
        }
    }
    return $cartoes;
}

/**
 * A NOTA de cada jogador na partida, de 3,0 a 10,0.
 *
 * Sai de 6,0 e sobe ou desce pelo que ele fez e pelo que o time fez. A nota é
 * o número que o jogador olha pra decidir quem vende e quem mantém, então ela
 * precisa premiar o que é visível: gol, assistência, não levar gol.
 */
function futNotasDaPartida(array $escalados, array $gols, array $cartoes, int $golsPro, int $golsContra): array
{
    $notas = [];
    foreach ($escalados as $j) $notas[$j['nome']] = 6.0;

    foreach ($gols as $g) {
        $n = $g['autor']['nome'];
        /* Gol de zagueiro vale MAIS na nota que gol de atacante: fazer gol é o
           trabalho do atacante e é façanha do zagueiro. */
        $bonus = in_array($g['autor']['pos'], ['ZAG', 'GOL', 'LAT'], true) ? 1.6 : 1.1;
        if (isset($notas[$n])) $notas[$n] += $bonus;
        if ($g['assistente'] && isset($notas[$g['assistente']['nome']])) {
            $notas[$g['assistente']['nome']] += 0.8;
        }
    }

    foreach ($cartoes as $c) {
        $n = $c['jogador']['nome'];
        if (!isset($notas[$n])) continue;
        $notas[$n] -= $c['tipo'] === 'vermelho' ? 2.5 : 0.6;
    }

    // O resultado mexe com todo mundo, e a defesa responde pelos gols sofridos.
    $resultado = $golsPro > $golsContra ? 0.5 : ($golsPro < $golsContra ? -0.5 : 0.0);
    foreach ($escalados as $j) {
        $n = $j['nome'];
        $notas[$n] += $resultado;
        if (in_array($j['pos'], ['GOL', 'ZAG', 'LAT'], true)) {
            if ($golsContra === 0)      $notas[$n] += 0.7;   // não sofreu: mérito da defesa
            elseif ($golsContra >= 3)   $notas[$n] -= 0.8;
        }
        // Um tiquinho de acaso, pra dois jogos iguais não darem notas idênticas.
        $notas[$n] += (mt_rand(-30, 30) / 100);
        $notas[$n] = round(max(3.0, min(10.0, $notas[$n])), 1);
    }
    return $notas;
}

/**
 * SIMULA A PARTIDA INTEIRA e devolve tudo que aconteceu.
 *
 * @param array $meus      jogadores escalados do clube do jogador
 * @param array $deles     escalados do adversário
 * @param int   $forcaMeu  força do time do jogador em campo
 * @param int   $forcaDele força do adversário
 * @param bool  $casa      o jogador está em casa?
 *
 * @return array ['meus'=>int,'deles'=>int,'eventos'=>[...],'notas'=>[...],
 *                'gols'=>[...],'cartoes'=>[...]]
 */
/**
 * QUANTAS FINALIZAÇÕES UM JOGO TEM, somando os dois times.
 *
 * O placar sai de futPlacar e não muda por causa disto — as finalizações são
 * DERIVADAS dele: dos chutes de cada lado, alguns já viraram gol, e o resto é
 * o que a narração conta. Um jogo de futebol tem umas duas dúzias de
 * finalizações e dois gols, e era justamente o meio disso que faltava.
 */
const FUT_FINALIZACOES_JOGO = 23;

/** O que acontece com um chute que não foi gol, em partes de cem. */
/* A trave é rara de propósito: com sete em cem dos chutes sem gol dava
   uma e meia por partida, e bola na trave que acontece todo jogo deixa de ser
   o lance que faz o estádio inteiro levar a mão à cabeça. */
const FUT_DESTINO_DO_CHUTE = ['fora' => 57, 'defendeu' => 36, 'trave' => 2, 'bloqueou' => 5];

/** Faltas por jogo, de cada lado — as que valem menção. */
const FUT_FALTAS_PERIGOSAS_JOGO = 3.2;

/**
 * OS LANCES QUE NÃO MUDAM O PLACAR.
 *
 * A partida ao vivo tinha dezoito atualizações de relógio e só quatro eventos
 * no jogo inteiro: os gols e os cartões. Em catorze delas o relógio andava e
 * não acontecia nada, o que faz uma tela de cronômetro, não de futebol.
 *
 * Aqui saem a chance perdida, a defesa do goleiro, a bola na trave, o chute
 * bloqueado e a falta perigosa. Nenhum deles mexe no resultado — quem decide
 * o placar continua sendo futPlacar, e isso é de propósito: a narração conta
 * o jogo que o motor já decidiu, em vez de virar um segundo motor com regra
 * própria.
 *
 * @param int $gols os gols que o time fez no trecho: eles JÁ são finalização,
 *        então saem da conta pra não dobrar o número de chutes.
 * @return array lista de eventos e o total de finalizações do trecho
 */
function futLancesDoTrecho(array $escalados, float $fracao, float $fatia,
                           int $gols, bool $meu, int $de, int $ate): array
{
    if (!$escalados || $fracao <= 0) return ['eventos' => [], 'chutes' => $gols, 'no_alvo' => $gols];

    $esperado = FUT_FINALIZACOES_JOGO * $fracao * $fatia;
    $chutes = max($gols, futPoisson($esperado));
    $semGol = $chutes - $gols;

    $pesos = [];
    foreach ($escalados as $j) $pesos[] = [$j, futPesoDoJogador($j, FUT_PESO_GOL)];

    $eventos = [];
    $noAlvo = $gols;
    $sorteio = [];
    foreach (FUT_DESTINO_DO_CHUTE as $tipo => $peso) {
        for ($i = 0; $i < $peso; $i++) $sorteio[] = $tipo;
    }

    for ($i = 0; $i < $semGol; $i++) {
        $j = futSorteioPonderado($pesos);
        if (!$j) break;
        $tipo = $sorteio[mt_rand(0, count($sorteio) - 1)];
        if ($tipo === 'defendeu') $noAlvo++;
        $eventos[] = ['minuto' => mt_rand($de, $ate), 'tipo' => $tipo, 'meu' => $meu,
                      'jogador' => $j['nome'], 'pos' => $j['pos']];
    }

    /* A FALTA PERIGOSA É DO OUTRO LADO: quem comete é o adversário, e quem
       cobra é o time. Como só temos o elenco de um dos lados por vez, ela
       entra como lance do time que vai bater. */
    $faltas = futPoisson(FUT_FALTAS_PERIGOSAS_JOGO * $fracao * $fatia);
    for ($i = 0; $i < $faltas; $i++) {
        $j = futSorteioPonderado($pesos);
        if (!$j) break;
        $eventos[] = ['minuto' => mt_rand($de, $ate), 'tipo' => 'falta', 'meu' => $meu,
                      'jogador' => $j['nome'], 'pos' => $j['pos']];
    }

    return ['eventos' => $eventos, 'chutes' => $chutes, 'no_alvo' => $noAlvo];
}

/**
 * AS DUAS ALAVANCAS DO TÉCNICO DURANTE O JOGO.
 *
 * POSTURA mexe no quanto o jogo abre. Ofensiva sobe o nível dos dois lados —
 * você cria mais e concede mais, o que favorece quem já é melhor e é a aposta
 * certa quando você precisa do gol. Defensiva faz o contrário: trava o jogo,
 * que é o que o time pior quer, porque num jogo de poucos gols o azar pesa
 * menos que a diferença de elenco.
 *
 * MARCAÇÃO troca cartão por qualidade do adversário. Marcar forte tira dois
 * pontos da força dele e traz mais cartão pro seu lado; marcar leve devolve o
 * jogo pra ele e limpa a súmula. Pendurado em véspera de decisão é exatamente
 * onde isso decide.
 *
 * Os números são pequenos de propósito: estratégia deve inclinar a partida,
 * não decidi-la. Quem ganha jogo é elenco.
 *
 * @return array ['meu','dele','cartoes'] — ajuste de força dos dois lados e o
 *         multiplicador de cartões do seu time.
 */
function futEfeitoDaEstrategia(array $estrategia): array
{
    $postura  = (string)($estrategia['postura'] ?? 'neutro');
    $marcacao = (string)($estrategia['marcacao'] ?? 'normal');

    /* O QUE MUDA É O VOLUME DE JOGO, não a força. Somar pontos nos dois lados
       não fazia nada: futPlacar tira a fatia de cada um da RAZÃO entre as
       forças, e o total de gols do nível médio, que é limitado a uma faixa
       estreita — os dois ficavam quase iguais. Mexer no total esperado é o que
       abre e fecha a partida de verdade. */
    [$meu, $dele, $gols] = match ($postura) {
        'ofensiva'  => [2, 3, 1.25],
        'defensiva' => [-2, -4, 0.78],
        default     => [0, 0, 1.0],
    };

    $cartoes = 1.0;
    switch ($marcacao) {
        case 'forte': $dele -= 2; $cartoes = 1.6; break;
        case 'leve':  $dele += 1; $cartoes = 0.6; break;
    }

    return ['meu' => $meu, 'dele' => $dele, 'cartoes' => $cartoes, 'gols' => $gols];
}

/** As posturas e marcações que a tela oferece, com o nome que o técnico lê. */
const FUT_POSTURAS = [
    'ofensiva'  => ['nome' => 'Ofensiva',  'desc' => 'Joga aberto: cria mais e concede mais.'],
    'neutro'    => ['nome' => 'Equilibrada', 'desc' => 'Sem inclinar o jogo pra nenhum lado.'],
    'defensiva' => ['nome' => 'Defensiva', 'desc' => 'Trava o jogo. É a arma de quem é pior.'],
];
const FUT_MARCACOES = [
    'forte'  => ['nome' => 'Forte',  'desc' => 'Pressiona: tira qualidade dele e enche a súmula.'],
    'normal' => ['nome' => 'Normal', 'desc' => 'Marcação padrão.'],
    'leve'   => ['nome' => 'Leve',   'desc' => 'Evita cartão, dá mais espaço pra ele.'],
];

/**
 * SIMULA UM PEDAÇO DA PARTIDA, do minuto $de ao minuto $ate.
 *
 * É o que permite a partida ao vivo: o relógio anda, o técnico pausa, mexe no
 * time, e o pedaço seguinte é simulado com a força nova. Sem isto a pausa era
 * decorativa — a partida inteira já estava decidida antes do apito inicial, e
 * trocar o time no intervalo não mudava nada.
 *
 * Cada trecho recebe a fatia de gols que lhe cabe pelo tempo (@see futPlacar),
 * então a soma dos pedaços tem a mesma calibração do jogo inteiro.
 *
 * As notas NÃO saem daqui: elas dependem do jogo todo (gols, cartões e o
 * resultado final), e quem as calcula no fim é futNotasDaPartida.
 *
 * @return array ['meus','deles','eventos','gols','cartoes']
 */
function futSimularTrecho(array $meus, array $deles, int $forcaMeu, int $forcaDele,
                          bool $casa, int $de, int $ate, array $estrategia = [],
                          array $jaAmarelados = []): array
{
    $de = max(1, $de);
    $ate = min(90, max($de, $ate));
    $fracao = ($ate - $de + 1) / 90;

    $ef = futEfeitoDaEstrategia($estrategia);
    $forcaMeu  = max(1, $forcaMeu + $ef['meu']);
    $forcaDele = max(1, $forcaDele + $ef['dele']);

    $fGols = $fracao * $ef['gols'];
    $p = $casa ? futPlacar($forcaMeu, $forcaDele, true, $fGols)
               : futPlacar($forcaDele, $forcaMeu, true, $fGols);
    $golsMeus  = $casa ? $p['casa'] : $p['fora'];
    $golsDeles = $casa ? $p['fora'] : $p['casa'];

    $meusGols  = futAutoresDosGols($meus, $golsMeus);
    $delesGols = futAutoresDosGols($deles, $golsDeles);
    $meusCart  = futCartoesDoTime($meus, $fracao * $ef['cartoes'], max(8, $de), $ate, $jaAmarelados);

    $eventos = [];
    $minutosMeus  = futMinutosDeGol(count($meusGols), $de, $ate);
    $minutosDeles = futMinutosDeGol(count($delesGols), $de, $ate);

    foreach ($meusGols as $i => $g) {
        $eventos[] = ['minuto' => $minutosMeus[$i] ?? mt_rand($de, $ate),
                      'tipo' => 'gol', 'meu' => true,
                      'jogador' => $g['autor']['nome'], 'pos' => $g['autor']['pos'],
                      'assistente' => $g['assistente']['nome'] ?? null];
    }
    foreach ($delesGols as $i => $g) {
        $eventos[] = ['minuto' => $minutosDeles[$i] ?? mt_rand($de, $ate),
                      'tipo' => 'gol', 'meu' => false,
                      'jogador' => $g['autor']['nome'], 'pos' => $g['autor']['pos'],
                      'assistente' => $g['assistente']['nome'] ?? null];
    }
    foreach ($meusCart as $c) {
        $eventos[] = ['minuto' => $c['minuto'],
                      'tipo' => $c['tipo'] === 'vermelho' ? 'vermelho' : 'amarelo',
                      'meu' => true, 'jogador' => $c['jogador']['nome'],
                      'pos' => $c['jogador']['pos'], 'segundo' => $c['segundo']];
    }

    /* E O RESTO DO JOGO. A fatia de cada lado sai da força: quem é melhor
       chuta mais, como já acontece com o gol. */
    $fMeu  = max(1, $forcaMeu);
    $fDele = max(1, $forcaDele);
    $fatiaMinha = $fMeu / ($fMeu + $fDele);

    $lancesMeus  = futLancesDoTrecho($meus,  $fGols, $fatiaMinha,      $golsMeus,  true,  $de, $ate);
    $lancesDeles = futLancesDoTrecho($deles, $fGols, 1 - $fatiaMinha,  $golsDeles, false, $de, $ate);
    $eventos = array_merge($eventos, $lancesMeus['eventos'], $lancesDeles['eventos']);

    usort($eventos, fn($a, $b) => $a['minuto'] <=> $b['minuto']);

    return ['meus' => $golsMeus, 'deles' => $golsDeles, 'eventos' => $eventos,
            'gols' => $meusGols, 'cartoes' => $meusCart,
            'numeros' => [
                'posse'         => (int)round($fatiaMinha * 100),
                'chutes'        => $lancesMeus['chutes'],
                'chutes_deles'  => $lancesDeles['chutes'],
                'no_alvo'       => $lancesMeus['no_alvo'],
                'no_alvo_deles' => $lancesDeles['no_alvo'],
            ]];
}

function futSimularPartida(array $meus, array $deles, int $forcaMeu, int $forcaDele, bool $casa): array
{
    // O PLACAR SAI DO MOTOR CALIBRADO — ver a explicação no topo do arquivo.
    $p = $casa ? futPlacar($forcaMeu, $forcaDele) : futPlacar($forcaDele, $forcaMeu);
    $golsMeus  = $casa ? $p['casa'] : $p['fora'];
    $golsDeles = $casa ? $p['fora'] : $p['casa'];

    $meusGols  = futAutoresDosGols($meus, $golsMeus);
    $delesGols = futAutoresDosGols($deles, $golsDeles);
    $meusCart  = futCartoesDoTime($meus);

    // ── Tudo vira uma linha do tempo ─────────────────────────────────
    $eventos = [];
    $minutosMeus  = futMinutosDeGol(count($meusGols));
    $minutosDeles = futMinutosDeGol(count($delesGols));

    foreach ($meusGols as $i => $g) {
        $eventos[] = [
            'minuto' => $minutosMeus[$i] ?? mt_rand(1, 90),
            'tipo'   => 'gol', 'meu' => true,
            'jogador' => $g['autor']['nome'],
            'pos'     => $g['autor']['pos'],
            'assistente' => $g['assistente']['nome'] ?? null,
        ];
    }
    foreach ($delesGols as $i => $g) {
        $eventos[] = [
            'minuto' => $minutosDeles[$i] ?? mt_rand(1, 90),
            'tipo'   => 'gol', 'meu' => false,
            'jogador' => $g['autor']['nome'],
            'pos'     => $g['autor']['pos'],
            'assistente' => $g['assistente']['nome'] ?? null,
        ];
    }
    foreach ($meusCart as $c) {
        $eventos[] = [
            'minuto' => $c['minuto'],
            'tipo'   => $c['tipo'] === 'vermelho' ? 'vermelho' : 'amarelo',
            'meu'    => true,
            'jogador' => $c['jogador']['nome'],
            'pos'     => $c['jogador']['pos'],
            'segundo' => $c['segundo'],
        ];
    }

    usort($eventos, fn($a, $b) => $a['minuto'] <=> $b['minuto']);

    return [
        'meus'    => $golsMeus,
        'deles'   => $golsDeles,
        'eventos' => $eventos,
        'notas'   => futNotasDaPartida($meus, $meusGols, $meusCart, $golsMeus, $golsDeles),
        'gols'    => $meusGols,
        'cartoes' => $meusCart,
    ];
}

/**
 * ACUMULA AS ESTATÍSTICAS da partida no total da temporada.
 *
 * O total é guardado por NOME do jogador. Nome é a chave em todo o jogo (o
 * elenco é gerado a partir dele e não há id), então dois jogadores de mesmo
 * nome no mesmo elenco somariam junto — por isso futNomeGenerico() garante
 * nome único dentro de cada elenco.
 *
 * @param array $stats  o acumulado atual [nome => dados]
 * @return array o acumulado novo
 */
function futAcumularEstatisticas(array $stats, array $escalados, array $partida): array
{
    foreach ($escalados as $j) {
        $n = $j['nome'];
        if (!isset($stats[$n])) {
            /* 'amarelos' é o TOTAL do ano, que a tela mostra e que nunca zera.
               'pendurado' é o contador que leva à suspensão e volta a zero ao
               suspender. Eram a mesma coluna, e o zerar comia o total: uma
               temporada de 50 jogos terminava exibindo 17 amarelos em vez de 95. */
            $stats[$n] = ['jogos' => 0, 'gols' => 0, 'assist' => 0, 'amarelos' => 0,
                          'pendurado' => 0, 'vermelhos' => 0, 'soma_notas' => 0.0,
                          'pos' => $j['pos']];
        }
        $stats[$n]['jogos']++;
        $stats[$n]['soma_notas'] += (float)($partida['notas'][$n] ?? 6.0);
    }

    foreach ($partida['gols'] as $g) {
        $a = $g['autor']['nome'];
        if (isset($stats[$a])) $stats[$a]['gols']++;
        if ($g['assistente']) {
            $s = $g['assistente']['nome'];
            if (isset($stats[$s])) $stats[$s]['assist']++;
        }
    }

    foreach ($partida['cartoes'] as $c) {
        $n = $c['jogador']['nome'];
        if (!isset($stats[$n])) continue;
        if ($c['tipo'] === 'vermelho') {
            $stats[$n]['vermelhos']++;
            $stats[$n]['pendurado'] = 0;   // expulso já cumpre pena; a conta recomeça
        } else {
            $stats[$n]['amarelos']++;
            $stats[$n]['pendurado'] = (int)($stats[$n]['pendurado'] ?? 0) + 1;
        }
    }

    return $stats;
}

/**
 * QUEM ESTÁ SUSPENSO e por quantos jogos, depois de uma partida.
 *
 * Vermelho tira da próxima. Três amarelos também, e aí o contador zera — que é
 * a regra do Brasileirão e a que todo mundo reconhece.
 *
 * @param array $suspensos [nome => jogos restantes]
 * @return array o mapa novo
 */
function futAtualizarSuspensoes(array $suspensos, array $stats, array $partida): array
{
    // Quem estava suspenso cumpriu mais um jogo.
    foreach ($suspensos as $nome => $jogos) {
        $suspensos[$nome] = $jogos - 1;
        if ($suspensos[$nome] <= 0) unset($suspensos[$nome]);
    }

    foreach ($partida['cartoes'] as $c) {
        $n = $c['jogador']['nome'];
        if ($c['tipo'] === 'vermelho') {
            $suspensos[$n] = max($suspensos[$n] ?? 0, FUT_JOGOS_SUSPENSO_VERMELHO);
        }
    }

    // O terceiro amarelo suspende.
    foreach ($stats as $nome => $d) {
        if (($d['pendurado'] ?? 0) >= FUT_AMARELOS_PARA_SUSPENDER) {
            $suspensos[$nome] = max($suspensos[$nome] ?? 0, 1);
        }
    }

    return $suspensos;
}

/**
 * Zera o CONTADOR de quem foi suspenso pelo terceiro amarelo.
 *
 * Mexe só em 'pendurado'. O total de 'amarelos' do ano fica intacto — ele é o
 * número que a tela mostra, e zerá-lo fazia a temporada terminar com uma
 * fração dos cartões que realmente aconteceram.
 */
function futZerarAmarelos(array $stats): array
{
    foreach ($stats as $nome => $d) {
        if (($d['pendurado'] ?? 0) >= FUT_AMARELOS_PARA_SUSPENDER) {
            $stats[$nome]['pendurado'] = 0;
        }
    }
    return $stats;
}

/** A artilharia do elenco: quem mais fez gol. */
function futArtilharia(array $stats, int $limite = 10): array
{
    $l = [];
    foreach ($stats as $nome => $d) {
        if (($d['gols'] ?? 0) <= 0) continue;
        $l[] = ['nome' => $nome] + $d;
    }
    usort($l, fn($a, $b) => [$b['gols'], $b['assist']] <=> [$a['gols'], $a['assist']]);
    return array_slice($l, 0, $limite);
}

/** A nota média de um jogador na temporada. */
function futNotaMedia(array $d): float
{
    $j = (int)($d['jogos'] ?? 0);
    if ($j <= 0) return 0.0;
    return round(((float)($d['soma_notas'] ?? 0)) / $j, 2);
}
