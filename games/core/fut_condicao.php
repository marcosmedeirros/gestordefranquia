<?php
/**
 * CONDIÇÃO — energia, moral e lesão.
 *
 * É o que obriga a rodar o elenco. Sem isso, o jogo tem uma resposta certa
 * para toda partida: escalar os onze melhores, sempre, os 50 jogos do ano. Com
 * energia caindo e lesão acontecendo, o reserva existe por um motivo, e o
 * elenco de 25 deixa de ser enfeite.
 *
 * ── O EQUILÍBRIO QUE ESTE ARQUIVO PERSEGUE ───────────────────────────
 *
 * Cansaço tem que doer o suficiente pra o jogador notar, e não tanto que o
 * titular vire reserva a cada três jogos. Um time que joga com os mesmos onze
 * a temporada inteira deve terminar visivelmente pior que um que reveza — mas
 * não a ponto de o revezamento ser obrigatório, porque aí não é escolha.
 */

/** Quanto de energia uma partida consome, e quanto o descanso devolve. */
const FUT_ENERGIA_POR_JOGO = 17;
const FUT_ENERGIA_DESCANSO = 26;
const FUT_ENERGIA_MINIMA = 25;

/**
 * O QUANTO A MORAL VOLTA AO NORMAL a cada partida, e onde ela para de cair.
 *
 * SEM ISSO A MORAL ERA UMA SENTENÇA. Derrota tirava 7, goleada sofrida 12, e
 * nada devolvia além de vencer — então o time que começava mal afundava: moral
 * baixa rende menos, render menos é perder de novo, e a temporada virava um
 * poço. Medindo 20 temporadas num clube da Série D, a moral média do time em
 * campo era 23 de 100; no Palmeiras, 87. O clube pequeno levava uma punição
 * que o grande nunca via, e os clubes simulados do mundo não levavam nenhuma.
 *
 * Puxar de volta pro normal transforma a moral no que ela deve ser — o momento
 * do time, que passa — em vez de uma dívida que não se paga. Quem perde sempre
 * estaciona perto de 45, e não em 5.
 */
const FUT_MORAL_NORMAL = 75;
const FUT_MORAL_VOLTA = 0.25;
const FUT_MORAL_MINIMA = 20;

/** A faixa de energia em que o jogador rende 100%. */
const FUT_ENERGIA_PLENA = 80;

/** Chance de um jogador se machucar por partida, em %. */
const FUT_CHANCE_LESAO = 2.2;

/**
 * O QUANTO A CONDIÇÃO MEXE NO OVR do jogador naquela partida.
 *
 * Energia acima de 80 não dá bônus — ninguém joga acima do que é. Abaixo
 * disso, cai proporcionalmente até o piso: um jogador com 25 de energia perde
 * uns 11 pontos, que é muito e é o ponto.
 *
 * A moral mexe menos, e para os dois lados: time embalado rende um pouco mais,
 * time desmoralizado um pouco menos.
 */
function futAjusteDeCondicao(array $j): int
{
    $energia = (int)($j['energia'] ?? 100);
    $moral = (int)($j['moral'] ?? 75);

    $porEnergia = $energia >= FUT_ENERGIA_PLENA
        ? 0
        : -(int)round((FUT_ENERGIA_PLENA - $energia) * 0.20);

    // Moral 75 é o normal; 100 dá +2, 10 tira -3.
    $porMoral = (int)round(($moral - 75) / 12);

    return $porEnergia + $porMoral;
}

/** O OVR do jogador levando em conta a condição dele hoje. */
function futOvrComCondicao(array $j): int
{
    return (int)max(20, (int)$j['ovr'] + futAjusteDeCondicao($j));
}

/** O jogador pode entrar em campo? */
function futEstaDisponivel(array $j, array $suspensos = []): bool
{
    if ((int)($j['lesao'] ?? 0) > 0) return false;
    if (isset($suspensos[$j['nome']])) return false;
    return true;
}

/** Quem não pode jogar, e por quê. */
function futIndisponiveis(array $elenco, array $suspensos = []): array
{
    $out = [];
    foreach ($elenco as $j) {
        if ((int)($j['lesao'] ?? 0) > 0) {
            $out[$j['nome']] = ['motivo' => 'lesão', 'jogos' => (int)$j['lesao']];
        } elseif (isset($suspensos[$j['nome']])) {
            $out[$j['nome']] = ['motivo' => 'suspensão', 'jogos' => (int)$suspensos[$j['nome']]];
        }
    }
    return $out;
}

/**
 * APLICA O QUE A PARTIDA FEZ NO ELENCO: cansaço, descanso, moral e lesão.
 *
 * Quem jogou perde energia, quem ficou de fora recupera, e o contador de lesão
 * anda um jogo. A moral do elenco inteiro segue o resultado — futebol é humor
 * coletivo, e quem não jogou também sente.
 *
 * @param array $escalados quem entrou em campo
 * @return array ['elenco'=>array, 'noticias'=>array]
 */
function futAplicarDesgaste(array $elenco, array $escalados, int $golsPro, int $golsContra): array
{
    $jogaram = [];
    foreach ($escalados as $j) $jogaram[$j['nome']] = true;

    // O resultado move a moral de todo mundo.
    $porResultado = $golsPro > $golsContra ? 6 : ($golsPro < $golsContra ? -7 : -1);
    if ($golsPro - $golsContra >= 3) $porResultado = 10;   // goleada anima
    if ($golsContra - $golsPro >= 3) $porResultado = -12;  // e levar goleada desmonta

    $noticias = [];
    foreach ($elenco as &$j) {
        $nome = $j['nome'];
        $j['energia'] = (int)($j['energia'] ?? 100);
        $j['moral'] = (int)($j['moral'] ?? 75);
        $j['lesao'] = (int)($j['lesao'] ?? 0);

        if ($j['lesao'] > 0) {
            $j['lesao']--;
            /* Machucado não treina forte, então recupera energia mais devagar.
               Sem isso o lesionado voltaria com 100 e o jogador seria premiado
               por perder jogador. */
            $j['energia'] = min(100, $j['energia'] + 8);
            if ($j['lesao'] === 0) $noticias[] = $nome . ' se recuperou e está à disposição.';
            continue;
        }

        if (isset($jogaram[$nome])) {
            $j['energia'] = max(FUT_ENERGIA_MINIMA, $j['energia'] - FUT_ENERGIA_POR_JOGO);

            // ── A lesão acontece pra quem está em campo ──────────────
            if ((mt_rand(0, 1000) / 10) < FUT_CHANCE_LESAO) {
                /* Cansado machuca mais: a chance já passou, mas a GRAVIDADE
                   cresce quando a energia está baixa, que é o jeito de o
                   cansaço cobrar sem virar loteria. */
                $base = mt_rand(1, 4);
                $extra = $j['energia'] < 45 ? mt_rand(1, 3) : 0;
                $j['lesao'] = $base + $extra;
                $j['moral'] = max(10, $j['moral'] - 10);
                $noticias[] = sprintf('%s se machucou e fica fora por %d jogo(s).', $nome, $j['lesao']);
            }
        } else {
            $j['energia'] = min(100, $j['energia'] + FUT_ENERGIA_DESCANSO);
        }

        $j['moral'] += $porResultado;
        // E a volta ao normal: o que o resultado fez, o tempo desfaz em parte.
        $j['moral'] += (int)round((FUT_MORAL_NORMAL - $j['moral']) * FUT_MORAL_VOLTA);
        $j['moral'] = max(FUT_MORAL_MINIMA, min(100, $j['moral']));
    }
    unset($j);

    return ['elenco' => $elenco, 'noticias' => $noticias];
}

/**
 * A CONDIÇÃO SIMULADA de um elenco que ninguém gerencia.
 *
 * O ADVERSÁRIO TAMBÉM TEM QUE CANSAR. Os clubes do mundo não têm técnico
 * rodando o elenco jogo a jogo, então o elenco deles chegava sempre inteiro:
 * energia 100, moral 75, ninguém machucado. O time do jogador passa pelo
 * desgaste de verdade e entrava em campo uns seis pontos de força abaixo do
 * que o catálogo dele dizia — em TODAS as partidas do ano.
 *
 * O efeito era grande e silencioso: medindo 20 temporadas paradas no mesmo
 * clube, o time mais forte da Série A terminava em 10º de média e o da Série D
 * em 41º de 42. Quem jogava mais competições cansava mais, então o clube
 * grande era o mais castigado — o contrário do que deveria.
 *
 * Aqui o rival recebe o mesmo tipo de desgaste: o titular habitual chega
 * cansado, o reserva está fresco, e de vez em quando alguém está machucado.
 * É DETERMINÍSTICO por clube e rodada, porque a mesma partida não pode dar
 * resultado diferente se a tela for recarregada.
 */
function futCondicaoSimulada(array $elenco, string $clube, int $rodada): array
{
    if (!$elenco) return $elenco;

    /* Quem são os titulares dele: o elenco não guarda isso, então é o OVR que
       diz — é o mesmo critério que o escalador automático usaria. */
    $ordem = $elenco;
    usort($ordem, fn($a, $b) => (int)$b['ovr'] <=> (int)$a['ovr']);
    $titular = [];
    foreach (array_slice($ordem, 0, 11) as $j) $titular[$j['nome']] = true;

    $estado = mt_rand();               // guarda o sorteio do jogo
    mt_srand(crc32($clube . '|cond|r' . $rodada));

    foreach ($elenco as &$j) {
        $ehTitular = isset($titular[$j['nome']]);
        $j['energia'] = $ehTitular ? mt_rand(58, 88) : mt_rand(84, 100);
        // A faixa é larga de propósito: o mundo tem time embalado e time em crise.
        $j['moral']   = mt_rand(48, 92);
        // Um elenco tem quase sempre alguém no departamento médico.
        $j['lesao']   = ($ehTitular && mt_rand(1, 100) <= 7) ? mt_rand(1, 3) : 0;
    }
    unset($j);

    mt_srand($estado);
    return $elenco;
}

/** Descanso de fim de temporada: todo mundo volta inteiro. */
function futDescansoDeFimDeAno(array $elenco): array
{
    foreach ($elenco as &$j) {
        $j['energia'] = 100;
        $j['moral'] = 75;
        $j['lesao'] = 0;
    }
    unset($j);
    return $elenco;
}

/** Garante que todo jogador do elenco tenha os campos de condição. */
function futGarantirCondicao(array $elenco): array
{
    foreach ($elenco as &$j) {
        if (!isset($j['energia'])) $j['energia'] = 100;
        if (!isset($j['moral']))   $j['moral'] = 75;
        if (!isset($j['lesao']))   $j['lesao'] = 0;
    }
    unset($j);
    return $elenco;
}

/** Uma palavra pro estado de energia, pra tela não mostrar só um número. */
function futTextoEnergia(int $e): array
{
    if ($e >= 85) return ['txt' => 'inteiro', 'cor' => 'verde'];
    if ($e >= 65) return ['txt' => 'bem',     'cor' => 'verde'];
    if ($e >= 45) return ['txt' => 'cansado', 'cor' => 'amarelo'];
    return ['txt' => 'exausto', 'cor' => 'vermelho'];
}
