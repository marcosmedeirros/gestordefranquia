<?php
/**
 * Build-a-Player — motor de avaliação e simulação.
 *
 * O jogador distribui pontos entre 20 atributos (em letras F..S), escolhe tipo
 * (Guard ou Big), posição e arquétipo. O motor transforma isso em médias de
 * temporada, simula playoffs e diz em que lugar do Top 100 ele ficou.
 *
 * O elenco de 100 rivais (50 Guards, 50 Bigs) é FIXO: gerado por semente
 * constante, então todo mundo disputa contra exatamente os mesmos caras e o
 * ranking é comparável. Tem lixo e tem lenda no meio — o topo é absurdo de
 * propósito, pra ser quase impossível terminar em 1º.
 */

require_once __DIR__ . '/build_dados.php';

const BUILD_SEMENTE_ELENCO = 20260802;

/** Gerador determinístico simples (mesma semente, mesma sequência). */
function buildRandSeq(int &$estado): float
{
    // Park–Miller. Serve de sobra e não depende do rand() do PHP, que muda
    // de comportamento entre versões — o elenco precisa ser sempre igual.
    $estado = ($estado * 16807) % 2147483647;
    return $estado / 2147483647;
}

/**
 * Prepara uma semente pra uso. Sem isso, semente pequena (1, 2, 999...) dava
 * sempre um primeiro sorteio quase zero — o jogador levava o pior azar
 * possível nos playoffs em toda partida, enquanto os rivais, que pegam
 * números lá no meio da sequência, sorteavam normal.
 */
function buildSemear(int $valor): int
{
    $s = abs($valor) % 2147483646 + 1;
    $s = ($s * 2654435761) % 2147483647;
    if ($s <= 0) $s = 1;
    // Descarta os primeiros valores pra sair da região viciada da sequência.
    for ($i = 0; $i < 5; $i++) buildRandSeq($s);
    return $s;
}

/** Média ponderada dos atributos pelo peso da posição, em escala 0–100. */
function buildNotaBase(array $niveis, string $tipo): float
{
    $soma = 0.0;
    $pesos = 0.0;
    foreach (buildAtributos() as [$chave, , , $pesoG, $pesoB]) {
        $peso = $tipo === 'G' ? $pesoG : $pesoB;
        $soma  += buildValorDaLetra((int)($niveis[$chave] ?? 0)) * $peso;
        $pesos += $peso;
    }
    return $pesos > 0 ? $soma / $pesos : 0.0;
}

/** Custo total do build em pontos. */
function buildCustoTotal(array $niveis): int
{
    $total = 0;
    foreach (buildAtributos() as [$chave]) {
        $total += buildCustoDoNivel((int)($niveis[$chave] ?? 0));
    }
    return $total;
}

/**
 * Bônus de arquétipo: só vale se os atributos-chave dele estiverem realmente
 * altos (média >= B). Build "genérico" não ganha nada.
 */
function buildBonusArquetipo(array $niveis, string $arquetipo): float
{
    $arqs = buildArquetipos();
    if (!isset($arqs[$arquetipo])) return 0.0;
    $a = $arqs[$arquetipo];

    $soma = 0;
    foreach ($a['chaves'] as $c) $soma += (int)($niveis[$c] ?? 0);
    $media = $soma / max(1, count($a['chaves']));

    if ($media < 6) return 0.0;                    // abaixo de B, nada
    $forca = min(1.0, ($media - 6) / 5);           // 6 -> 0 ; 11 -> 1
    return $a['bonus'] * $forca;
}

/**
 * Médias de temporada a partir dos atributos. Não é simulação jogo a jogo —
 * é uma conversão direta, com um empurrão de eficiência: quem tenta fazer
 * tudo (build espalhado) rende menos do que quem tem identidade clara.
 */
function buildEstatisticas(array $niveis, string $tipo): array
{
    $v = function (string $c) use ($niveis): float {
        return buildValorDaLetra((int)($niveis[$c] ?? 0));
    };

    $escala = fn(float $x, float $min, float $max) => $min + ($x / 100) * ($max - $min);

    $criacao   = ($v('drible') + $v('passe') + $v('visao')) / 3;
    $arremesso = ($v('arremesso_3') * 1.2 + $v('arremesso_medio') + $v('arremesso_perto') * 1.1) / 3.3;
    $interior  = ($v('pos_baixo') + $v('arremesso_perto') + $v('forca')) / 3;
    $defesa    = ($v('defesa_perim') + $v('defesa_interior') + $v('roubo') + $v('toco')) / 4;

    $usoOfensivo = $tipo === 'G'
        ? ($arremesso * 0.6 + $criacao * 0.4)
        : ($interior * 0.6 + $arremesso * 0.4);

    // Resistência vira minutagem, e minutagem multiplica tudo.
    $minutos = $escala($v('resistencia'), 22, 38);
    $fator   = $minutos / 36;

    $pts = $escala($usoOfensivo, 6, 31) * $fator;
    $ast = $tipo === 'G'
        ? $escala($criacao, 1.5, 10.5) * $fator
        : $escala($criacao, 0.8, 5.5) * $fator;
    $reb = $tipo === 'G'
        ? $escala(($v('rebote_def') + $v('rebote_of') + $v('salto')) / 3, 1.8, 7.5) * $fator
        : $escala(($v('rebote_def') * 1.2 + $v('rebote_of') + $v('salto') * 0.8) / 3, 4.0, 14.5) * $fator;
    $stl = $escala($v('roubo'), 0.2, 2.4) * $fator;
    $blk = $tipo === 'G'
        ? $escala($v('toco'), 0.1, 1.2) * $fator
        : $escala(($v('toco') * 1.3 + $v('defesa_interior')) / 2.3, 0.3, 3.6) * $fator;

    // Aproveitamento: QI e sangue frio puxam pra cima, uso alto sem talento
    // de arremesso puxa pra baixo.
    $fg = $escala(($v('arremesso_perto') + $v('qi_basquete') + $v('arremesso_medio') * 0.6) / 2.6, 38, 61);
    $tp = $escala($v('arremesso_3'), 22, 44);
    $ft = $escala($v('lance_livre'), 52, 93);

    return [
        'min' => round($minutos, 1),
        'pts' => round($pts, 1),
        'reb' => round($reb, 1),
        'ast' => round($ast, 1),
        'stl' => round($stl, 1),
        'blk' => round($blk, 1),
        'fg'  => round($fg, 1),
        'tp'  => round($tp, 1),
        'ft'  => round($ft, 1),
        'defesa' => round($defesa, 1),
    ];
}

/**
 * Nota final de temporada (0–100). Junta impacto estatístico, defesa,
 * eficiência e o bônus de arquétipo.
 */
function buildNotaTemporada(array $niveis, string $tipo, string $arquetipo, array $st): float
{
    $base = buildNotaBase($niveis, $tipo);

    // Impacto: pontos valem, mas assistência e defesa valem junto — build só
    // de pontuador não domina sozinho.
    $impacto = $st['pts'] * 1.00
             + $st['reb'] * 0.75
             + $st['ast'] * 1.05
             + $st['stl'] * 1.90
             + $st['blk'] * 1.70;

    $eficiencia = ($st['fg'] - 45) * 0.35 + ($st['tp'] - 33) * 0.22 + ($st['ft'] - 75) * 0.05;

    $nota = $base * 0.55 + $impacto * 0.95 + $eficiencia + $st['defesa'] * 0.12;
    $nota *= (1 + buildBonusArquetipo($niveis, $arquetipo));

    return $nota;
}

/**
 * Playoffs: pesa sangue frio, QI e resistência. Vale como um multiplicador
 * pequeno — decide desempate no topo, não faz build ruim virar lenda.
 */
function buildNotaPlayoffs(array $niveis, float $notaTemporada, int &$semente): array
{
    $clutch = buildValorDaLetra((int)($niveis['clutch'] ?? 0));
    $qi     = buildValorDaLetra((int)($niveis['qi_basquete'] ?? 0));
    $fis    = buildValorDaLetra((int)($niveis['resistencia'] ?? 0));

    $preparo = ($clutch * 0.5 + $qi * 0.3 + $fis * 0.2) / 100;   // 0.25–0.99

    // A sorte mexe pouco de propósito. Com uma faixa larga, o sorteio do dia
    // decidia o teto do jogador: mesmo com o build perfeito ele não alcançava
    // o top 10 se tivesse azar, e o prêmio virava loteria em vez de mérito.
    $sorte = 0.985 + buildRandSeq($semente) * 0.03;              // 0.985–1.015

    $mult = (0.90 + $preparo * 0.16) * $sorte;
    $rodadas = min(4, (int)floor(($mult - 0.88) * 17));
    $rodadas = max(0, $rodadas);

    return [
        'multiplicador' => $mult,
        'nota'          => $notaTemporada * $mult,
        'rodadas'       => $rodadas,
    ];
}

/**
 * Os 100 rivais. Fixos por semente: metade Guards, metade Bigs, do reserva
 * fraco ao MVP histórico. A curva é proposital — a maioria é medianona, e
 * só um punhado chega perto do teto.
 */
/**
 * Distribui o orçamento entre os atributos como um jogador faria.
 *
 * $pericia (0–1) é o quanto o rival "sabe jogar": em 1.0 ele sempre sobe o
 * atributo de melhor retorno (peso da posição ÷ custo do próximo nível); perto
 * de 0 ele escolhe quase no aleatório e desperdiça pontos em coisa que a
 * posição nem usa. Todos gastam o mesmo total.
 */
function buildGastarOrcamento(array $atributos, string $tipo, array $foco, float $pericia, int &$semente): array
{
    $niveis = [];
    foreach ($atributos as [$chave]) $niveis[$chave] = 0;

    $gasto = 0;
    $limite = BUILD_ORCAMENTO;
    $maxNivel = count(BUILD_LETRAS) - 1;

    for ($passo = 0; $passo < 500; $passo++) {
        $candidatos = [];
        foreach ($atributos as [$chave, , , $pesoG, $pesoB]) {
            $atual = $niveis[$chave];
            if ($atual >= $maxNivel) continue;
            $delta = buildCustoDoNivel($atual + 1) - buildCustoDoNivel($atual);
            if ($gasto + $delta > $limite) continue;

            $peso = $tipo === 'G' ? $pesoG : $pesoB;
            if (in_array($chave, $foco, true)) $peso *= 1.6;   // identidade do arquétipo
            $ganho = buildValorDaLetra($atual + 1) - buildValorDaLetra($atual);

            $candidatos[] = ['chave' => $chave, 'delta' => $delta, 'retorno' => ($ganho * $peso) / max(1, $delta)];
        }
        if (!$candidatos) break;

        // Perícia alta -> pega o melhor retorno. Baixa -> sorteia.
        if (buildRandSeq($semente) < $pericia) {
            usort($candidatos, fn($a, $b) => $b['retorno'] <=> $a['retorno']);
            $escolhido = $candidatos[0];
        } else {
            $escolhido = $candidatos[(int)floor(buildRandSeq($semente) * count($candidatos)) % count($candidatos)];
        }

        $niveis[$escolhido['chave']]++;
        $gasto += $escolhido['delta'];
    }

    return $niveis;
}

function buildElencoRival(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $semente = BUILD_SEMENTE_ELENCO;
    $atributos = buildAtributos();
    $arqs = buildArquetipos();
    $nomesG = ['Marcus', 'Devin', 'Tyrese', 'Caleb', 'Jalen', 'Andre', 'Kobe', 'Damon', 'Elias', 'Rico'];
    $nomesB = ['Boban', 'Kristaps', 'Amir', 'Dwight', 'Serge', 'Rudy', 'Enes', 'Nikola', 'Tyson', 'Bam'];
    $sobren = ['Silva', 'Costa', 'Moreira', 'Duarte', 'Barros', 'Nunes', 'Prado', 'Teixeira', 'Ramos', 'Vieira'];

    $elenco = [];
    for ($i = 0; $i < 100; $i++) {
        $tipo = $i < 50 ? 'G' : 'B';

        // Todo rival gasta o MESMO orçamento do jogador. O que separa a lenda
        // do reserva é como ela gasta: quem distribui bem (concentra no que a
        // posição usa) vira monstro; quem espalha vira mediano. É isso que faz
        // o ranking medir a qualidade do build, e não quem tem mais pontos.
        $p = $i % 50;
        if ($p < 2)       $pericia = 1.00;  // gasta quase perfeito
        elseif ($p < 6)   $pericia = 0.88;
        elseif ($p < 14)  $pericia = 0.74;
        elseif ($p < 26)  $pericia = 0.58;
        elseif ($p < 38)  $pericia = 0.42;
        else              $pericia = 0.26;  // espalha os pontos à toa

        $arqChaves = array_keys(array_filter($arqs, fn($a) => $a['tipo'] === $tipo));
        $arq = $arqChaves[(int)floor(buildRandSeq($semente) * count($arqChaves)) % count($arqChaves)];

        $niveis = buildGastarOrcamento($atributos, $tipo, $arqs[$arq]['chaves'], $pericia, $semente);

        $st = buildEstatisticas($niveis, $tipo);
        $notaTemp = buildNotaTemporada($niveis, $tipo, $arq, $st);
        $po = buildNotaPlayoffs($niveis, $notaTemp, $semente);

        $nome = ($tipo === 'G' ? $nomesG[$i % 10] : $nomesB[$i % 10]) . ' ' . $sobren[(int)floor($i / 10) % 10];

        $elenco[] = [
            'nome'      => $nome,
            'tipo'      => $tipo,
            'arquetipo' => $arqs[$arq]['nome'],
            'stats'     => $st,
            'nota'      => $po['nota'],
            'rival'     => true,
        ];
    }

    usort($elenco, fn($a, $b) => $b['nota'] <=> $a['nota']);
    $cache = $elenco;
    return $elenco;
}

/**
 * Roda a temporada do build do jogador e devolve tudo que a tela precisa:
 * médias, playoffs, posição no Top 100 e recompensa.
 */
function buildSimular(array $niveis, string $tipo, string $arquetipo, int $sementeJogador): array
{
    $st = buildEstatisticas($niveis, $tipo);
    $notaTemp = buildNotaTemporada($niveis, $tipo, $arquetipo, $st);

    $semente = buildSemear($sementeJogador);
    $po = buildNotaPlayoffs($niveis, $notaTemp, $semente);

    $rivais = buildElencoRival();

    $melhores = 0;
    foreach ($rivais as $r) {
        if ($r['nota'] > $po['nota']) $melhores++;
    }
    $posicao = $melhores + 1;   // 1 = melhor de todos

    $moedas = 0;
    if ($posicao === 1)      $moedas = 500;
    elseif ($posicao <= 5)   $moedas = 100;
    elseif ($posicao <= 10)  $moedas = 50;

    return [
        'stats'        => $st,
        'nota_temporada' => round($notaTemp, 2),
        'nota_final'   => round($po['nota'], 2),
        'playoffs'     => $po,
        'posicao'      => $posicao,
        'total'        => count($rivais) + 1,
        'moedas'       => $moedas,
        'rivais'       => $rivais,
    ];
}
