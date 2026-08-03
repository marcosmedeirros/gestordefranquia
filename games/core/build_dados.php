<?php
/**
 * Build-a-Player — dados de referência.
 *
 * A escala de letras é a mesma que o jogador vê na tela e a que o motor usa
 * pra calcular: cada letra vale um número, e o custo de subir de letra cresce
 * junto. É isso que impede montar um build com tudo em S.
 */

/** Letras da pior pra melhor. O índice é o "nível" do atributo. */
const BUILD_LETRAS = ['F', 'D', 'D+', 'C', 'C+', 'B-', 'B', 'B+', 'A-', 'A', 'A+', 'S'];

/**
 * Valor numérico de cada letra (0–100). Não é linear de propósito: a diferença
 * entre A+ e S vale muito mais do que entre F e D, como no basquete de verdade.
 */
function buildValorDaLetra(int $nivel): int
{
    $tabela = [0 => 25, 1 => 34, 2 => 41, 3 => 48, 4 => 55, 5 => 62,
               6 => 69, 7 => 76, 8 => 83, 9 => 89, 10 => 94, 11 => 99];
    return $tabela[$nivel] ?? 25;
}

/**
 * Custo em pontos de build pra deixar um atributo naquele nível. O salto no
 * fim da escala é o que força a escolha: dá pra ter dois atributos em S ou
 * meia dúzia em B+, nunca as duas coisas.
 */
function buildCustoDoNivel(int $nivel): int
{
    $tabela = [0 => 0, 1 => 2, 2 => 4, 3 => 7, 4 => 11, 5 => 16,
               6 => 22, 7 => 30, 8 => 40, 9 => 54, 10 => 72, 11 => 95];
    return $tabela[$nivel] ?? 0;
}

/** Orçamento de pontos pra distribuir. Apertado de propósito. */
const BUILD_ORCAMENTO = 300;

// O catálogo de atributos, arquétipos e orçamento saiu daqui: pertenciam ao
// desenho antigo (distribuir 300 pontos entre 16 atributos). O jogo agora é
// de roleta com 10 slots — o catálogo vive em build_notas.php.
