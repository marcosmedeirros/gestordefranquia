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
 *   passou do 2º turno   +2  → 3   (chegou à final de conferência)
 *   ganhou a de conf.    +4  → 7   (vice)
 *   ganhou a final       +3  → 10  (campeão)
 *
 * QUEM CHEGA NA FINAL LEVA A FINAL DE CONFERÊNCIA JUNTO. Antes o campeão
 * valia 7 e o vice 4 — os dois passavam pela final de conferência e nenhum
 * dos dois recebia por ela, o que fazia o campeão empatar com o próprio
 * valor de "vice + o que o vice não ganhou". Decisão da liga em 27/08/2026:
 * a etapa conta pra quem passou por ela, então vice = 4+3 e campeão = 7+3.
 *
 * Entrar nos playoffs e cair na 1ª rodada não pontua.
 */
const PONTOS_PLAYOFF = [
    'champion'         => 10,
    'runner_up'        => 7,
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
        // Sai da própria régua: repetir os números aqui é como eles se
        // soltam do cálculo e a tela passa a prometer o que não paga.
        'Playoffs' => [
            ['Avançou pro 2º turno',        PONTOS_PLAYOFF['second_round']],
            ['Avançou pra final de conf.',  PONTOS_PLAYOFF['conference_final']],
            ['Vice-campeão',                PONTOS_PLAYOFF['runner_up']],
            ['Campeão',                     PONTOS_PLAYOFF['champion']],
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

/**
 * A régua em texto, pro /pontuacao do WhatsApp.
 *
 * Mora aqui junto da régua, e não no arquivo de comandos, pelo mesmo motivo
 * que ela existe: quem quiser mudar um valor mexe num lugar só e a mensagem
 * do bot acompanha. Escrever os números no comando seria recriar a sexta
 * cópia — a que causou o problema que este arquivo veio resolver.
 *
 * A NBA Cup só entra na ELITE porque só a ELITE a disputa. Mostrá-la nas
 * outras seria prometer 2 pontos por um torneio que elas não jogam.
 */
function pontuacaoTextoBot(string $liga): string
{
    $liga = strtoupper(trim($liga));
    if (!in_array($liga, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
        return 'Liga não reconhecida. Use ELITE, NEXT, RISE ou ROOKIE.';
    }

    $ehElite = $liga === 'ELITE';
    $txt = "🏆 *Pontuação · {$liga}*\n_Quanto vale cada conquista na temporada._\n";

    $icone = [
        'Temporada regular'   => '📊',
        'Playoffs'            => '🥊',
        'Prêmios individuais' => '⭐',
        'NBA Cup'            => '🏅',
    ];

    foreach (reguaDePontos($ehElite) as $bloco => $linhas) {
        $txt .= "\n" . ($icone[$bloco] ?? '•') . " *{$bloco}*\n";
        foreach ($linhas as [$rotulo, $pontos]) {
            $txt .= '· ' . $rotulo . ' — *' . $pontos . '* pt' . ($pontos === 1 ? '' : 's') . "\n";
        }
    }

    /* O acumulado precisa estar escrito: lendo a lista solta, "Campeão 10"
       parece somar com "Vice 7" e o campeão viraria 17. */
    $txt .= "\n_Os pontos de playoff são acumulados: o número já é o total de"
          . " quem chegou até ali. Do 11º em diante a temporada regular não pontua._";

    if (!$ehElite) $txt .= "\n_A NBA Cup vale só na ELITE._";

    /* O /ranking já existia e responde a outra pergunta: quem está na
       frente. Como os dois falam de ponto, sem esta linha eles viram o
       mesmo comando na cabeça de quem lê. */
    $txt .= "\n\n_Pra ver quem está na frente, use */ranking " . $liga . "*._";

    return $txt;
}
