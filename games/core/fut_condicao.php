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

        $j['moral'] = max(5, min(100, $j['moral'] + $porResultado));
    }
    unset($j);

    return ['elenco' => $elenco, 'noticias' => $noticias];
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
