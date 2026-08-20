<?php
/**
 * A RÉGUA DE PONTOS DO RANKING — a fonte única.
 *
 * Ela estava escrita em cinco lugares, e em dois deles com valores
 * DIFERENTES: registrar a temporada pelo wizard dava 11 pontos ao campeão e
 * finalizar o bracket dava 5. A mesma temporada valia coisas diferentes
 * dependendo do caminho por onde o admin passasse. Aqui é o único lugar onde
 * esses números moram; quem precisa deles chama as funções.
 *
 * O JS tem a sua própria cópia (js/seasons.js e js/admin.js), porque o
 * cálculo aparece na tela antes de ir pro servidor. Mudou aqui, muda lá.
 */

/**
 * Pontos pela posição na conferência ao fim da temporada regular.
 *
 * Uma dupla de posições por degrau, do 1º ao 10º. Do 11º em diante não
 * pontua — a temporada regular premia quem brigou em cima.
 */
function pontosPorPosicao(int $pos): int
{
    if ($pos <= 0)  return 0;
    if ($pos <= 2)  return 5;   // 1º e 2º
    if ($pos <= 4)  return 4;   // 3º e 4º
    if ($pos <= 6)  return 3;   // 5º e 6º
    if ($pos <= 8)  return 2;   // 7º e 8º
    if ($pos <= 10) return 1;   // 9º e 10º
    return 0;
}

/**
 * Pontos de playoff por onde o time PAROU.
 *
 * Os valores são ACUMULADOS: cada etapa vencida soma à anterior, e o número
 * aqui já é o total de quem chegou até ali.
 *
 *   passou da 1ª rodada  +1  → 1
 *   passou do 2º turno   +2  → 3   (chegou às finais de conferência)
 *   chegou à final       +1  → 4   (vice)
 *   ganhou a final       +4  → 7   (campeão, sem o ponto de vice)
 *
 * Entrar nos playoffs e cair na 1ª rodada não pontua.
 */
const PONTOS_PLAYOFF = [
    'champion'         => 7,
    'runner_up'        => 4,
    'conference_final' => 3,
    'second_round'     => 1,
    'first_round'      => 0,
];

/** Cada prêmio individual do time vale isto. Técnico e GM do Ano ficam de fora. */
const PONTOS_POR_PREMIO = 1;

/** Os prêmios que contam ponto. Técnico e executivo do ano NÃO entram. */
const PREMIOS_QUE_PONTUAM = ['mvp', 'dpoy', 'mip', 'sixth_man', 'roy'];

/** A NBA Cup, que só a ELITE disputa. */
const PONTOS_NBA_CUP = 2;

/** O total de playoff de quem parou naquela etapa. Etapa desconhecida = 0. */
function pontosDePlayoff(string $etapa): int
{
    return PONTOS_PLAYOFF[$etapa] ?? 0;
}

/**
 * A régua inteira, pronta pra desenhar na tela.
 *
 * Existe pra que o card do dashboard e o painel do admin não voltem a
 * escrever os números na mão — foi assim que eles se separaram do código.
 */
function reguaDePontos(bool $comNbaCup = false): array
{
    $regua = [
        'Temporada regular' => [
            ['1º e 2º',   5],
            ['3º e 4º',   4],
            ['5º e 6º',   3],
            ['7º e 8º',   2],
            ['9º e 10º',  1],
        ],
        'Playoffs' => [
            ['Avançou pro 2º turno',        1],
            ['Avançou pra final de conf.',  3],
            ['Vice-campeão',                4],
            ['Campeão',                     7],
        ],
        'Prêmios individuais' => [
            ['MVP, DPOY, MIP, 6º Homem, ROY', 1],
        ],
    ];
    if ($comNbaCup) {
        $regua['NBA Cup'] = [['Campeão da NBA Cup', PONTOS_NBA_CUP]];
    }
    return $regua;
}
