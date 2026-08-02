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

/**
 * Atributos, agrupados como no jogo. 'peso_g' e 'peso_b' são o quanto cada
 * atributo importa pra um Guard e pra um Big — é o que faz um build de armador
 * com A+ em enterrada não virar o melhor jogador da liga.
 */
function buildAtributos(): array
{
    return [
        // chave, rótulo, grupo, peso pra Guard, peso pra Big
        ['arremesso_perto',  'Finalização',      'Ataque',    1.15, 1.35],
        ['arremesso_medio',  'Meia-distância',   'Ataque',    1.05, 0.85],
        ['arremesso_3',      'Bola de 3',        'Ataque',    1.30, 0.80],
        ['lance_livre',      'Lance livre',      'Ataque',    0.45, 0.40],
        ['pos_baixo',        'Jogo de costas',   'Ataque',    0.35, 1.10],
        ['drible',           'Drible',           'Criação',   1.25, 0.55],
        ['passe',            'Passe',            'Criação',   1.20, 0.70],
        ['visao',            'Visão de jogo',    'Criação',   1.15, 0.60],
        ['defesa_perim',     'Defesa de perím.', 'Defesa',    1.20, 0.65],
        ['defesa_interior',  'Defesa de garrafão','Defesa',   0.45, 1.30],
        ['roubo',            'Roubo de bola',    'Defesa',    1.00, 0.60],
        ['toco',             'Toco',             'Defesa',    0.40, 1.20],
        ['rebote_of',        'Rebote ofensivo',  'Físico',    0.35, 1.05],
        ['rebote_def',       'Rebote defensivo', 'Físico',    0.70, 1.35],
        ['velocidade',       'Velocidade',       'Físico',    1.20, 0.70],
        ['forca',            'Força',            'Físico',    0.55, 1.15],
        ['salto',            'Impulsão',         'Físico',    0.85, 1.00],
        ['resistencia',      'Resistência',      'Físico',    0.90, 0.90],
        ['qi_basquete',      'QI de basquete',   'Mental',    1.10, 1.10],
        ['clutch',           'Sangue frio',      'Mental',    0.95, 0.85],
    ];
}

/** Arquétipos: cada um dá bônus se o build respeitar a identidade. */
function buildArquetipos(): array
{
    return [
        'armador'   => ['nome' => 'Armador',        'tipo' => 'G', 'chaves' => ['passe', 'visao', 'drible'],                'bonus' => 0.06],
        'ala_camisa'=> ['nome' => 'Ala-arremessador','tipo' => 'G', 'chaves' => ['arremesso_3', 'arremesso_medio'],         'bonus' => 0.06],
        'defensor'  => ['nome' => 'Trava',          'tipo' => 'G', 'chaves' => ['defesa_perim', 'roubo', 'velocidade'],     'bonus' => 0.06],
        'ala_pivo'  => ['nome' => 'Ala-pivô móvel', 'tipo' => 'B', 'chaves' => ['arremesso_3', 'velocidade'],               'bonus' => 0.06],
        'pivo_forca'=> ['nome' => 'Pivô de força',  'tipo' => 'B', 'chaves' => ['pos_baixo', 'forca', 'rebote_of'],         'bonus' => 0.06],
        'protetor'  => ['nome' => 'Protetor do aro','tipo' => 'B', 'chaves' => ['toco', 'defesa_interior', 'rebote_def'],   'bonus' => 0.06],
    ];
}

/** Posições por tipo. */
function buildPosicoesDoTipo(string $tipo): array
{
    return $tipo === 'G' ? ['PG', 'SG', 'SF'] : ['SF', 'PF', 'C'];
}
