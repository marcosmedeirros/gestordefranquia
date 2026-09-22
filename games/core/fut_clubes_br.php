<?php
/**
 * OS CLUBES BRASILEIROS DO JOGO DE CARREIRA.
 *
 * POR QUE UM CATÁLOGO PRÓPRIO, e não mais linhas no copero_clubes.php: o
 * Copero é uma carreira mundial, onde o jogador passa por alguns clubes de
 * cada país. Encher aquele arquivo com o Nova Mutum e o Águia de Marabá
 * desbalancearia a carreira dele — de repente metade dos clubes do mundo é do
 * interior do Brasil. Aqui, esses clubes são o ponto: sem eles não existe
 * Paranaense nem Copa Verde.
 *
 * O jogo lê OS DOIS: os grandes vêm do catálogo do Copero (que já tem força e
 * escudo calibrados) e este acrescenta o resto do Brasil.
 *
 * ── Os campos ─────────────────────────────────────────────────────────
 *
 *   nome     como aparece na tela
 *   div      'BR1' | 'BR2' | 'BR3' | '' (só disputa estadual e copas)
 *   uf       o estado, que define o estadual que ele joga
 *   forca    1 a 100, a MESMA régua do Copero — dá pra comparar um clube
 *            daqui com o River Plate sem converter nada
 *   regiao   'NE' entra na Copa do Nordeste, 'N' na Copa Verde, '' em nenhuma
 *
 * A força dos pequenos fica entre 30 e 55 de propósito: é o que faz o Palmeiras
 * (92) passear no Paulista e o estadual valer como aquecimento, não como
 * campeonato de verdade. Quando um time desses elimina um grande na Copa do
 * Brasil, é zebra — e o motor deixa acontecer, raramente.
 *
 * Nome de clube é referência factual. O jogo não é afiliado nem endossado por
 * nenhum deles e não hospeda escudo — quem tem URL usa a do Copero, quem não
 * tem aparece como monograma das iniciais.
 */

/** Os estaduais que o jogo disputa. */
const FUT_ESTADUAIS = [
    'SP' => 'Campeonato Paulista',
    'RJ' => 'Campeonato Carioca',
    'MG' => 'Campeonato Mineiro',
    'PR' => 'Campeonato Paranaense',
];

/** As copas regionais. */
const FUT_REGIONAIS = [
    'NE' => 'Copa do Nordeste',
    'N'  => 'Copa Verde',
];

/**
 * Os clubes que NÃO estão no catálogo do Copero.
 *
 * Os das Séries A e B que já existem lá não se repetem aqui — quem junta os
 * dois é futClubesDoBrasil(), e clube repetido viraria dois times com o mesmo
 * nome na mesma tabela.
 *
 * [nome, divisão, UF, força, região]
 */
const FUT_CLUBES_BR_EXTRA = [
    // ── Completam a Série A ──────────────────────────────────────────
    ['Vitória',            'BR1', 'BA', 76, 'NE'],
    ['Santos',             'BR1', 'SP', 76, ''],      // sobe da B do catálogo

    // ── Completam a Série B ──────────────────────────────────────────
    ['Atlético-GO',        'BR2', 'GO', 66, 'N'],
    ['Ponte Preta',        'BR2', 'SP', 62, ''],
    ['CRB',                'BR2', 'AL', 62, 'NE'],
    ['Operário-PR',        'BR2', 'PR', 61, ''],
    ['Botafogo-SP',        'BR2', 'SP', 60, ''],
    ['Amazonas',           'BR2', 'AM', 59, 'N'],
    ['Athletic-MG',        'BR2', 'MG', 58, ''],
    ['Ferroviária',        'BR2', 'SP', 58, ''],
    ['Náutico',            'BR2', 'PE', 60, 'NE'],
    ['Paysandu',           'BR2', 'PA', 58, 'N'],   // sobe da C do catálogo

    // ── Paulista ─────────────────────────────────────────────────────
    ['Guarani',            'BR3', 'SP', 55, ''],
    ['Portuguesa',         'BR3', 'SP', 52, ''],
    ['São Bernardo',       'BR3', 'SP', 50, ''],
    ['Inter de Limeira',   '',    'SP', 45, ''],
    ['Água Santa',         '',    'SP', 46, ''],
    ['Velo Clube',         '',    'SP', 42, ''],
    ['Noroeste',           '',    'SP', 41, ''],
    ['Santo André',        '',    'SP', 44, ''],

    // ── Carioca ──────────────────────────────────────────────────────
    ['Bangu',              '',    'RJ', 44, ''],
    ['Madureira',          '',    'RJ', 43, ''],
    ['Nova Iguaçu',        '',    'RJ', 46, ''],
    ['Portuguesa-RJ',      '',    'RJ', 42, ''],
    ['Boavista-RJ',        '',    'RJ', 43, ''],
    ['Sampaio Corrêa-RJ',  '',    'RJ', 40, ''],
    ['Maricá',             '',    'RJ', 45, ''],

    // ── Mineiro ──────────────────────────────────────────────────────
    ['Tombense',           'BR3', 'MG', 52, ''],
    ['Villa Nova-MG',      '',    'MG', 43, ''],
    ['Democrata',          '',    'MG', 39, ''],
    ['Itabirito',          '',    'MG', 42, ''],
    ['Pouso Alegre',       '',    'MG', 44, ''],
    ['Uberlândia',         '',    'MG', 43, ''],
    ['Caldense',           '',    'MG', 41, ''],
    ['Patrocinense',       '',    'MG', 38, ''],

    // ── Paranaense ───────────────────────────────────────────────────
    ['Maringá',            'BR3', 'PR', 51, ''],
    ['Cianorte',           '',    'PR', 44, ''],
    ['Azuriz',             '',    'PR', 41, ''],
    ['FC Cascavel',        '',    'PR', 45, ''],
    ['Rio Branco-PR',      '',    'PR', 38, ''],
    ['União Beltrão',      '',    'PR', 39, ''],
    ['São Joseense',       '',    'PR', 40, ''],
    ['Andraus',            '',    'PR', 36, ''],

    // ── Nordeste (Copa do Nordeste) ──────────────────────────────────
    ['Santa Cruz',         'BR3', 'PE', 54, 'NE'],
    ['CSA',                'BR3', 'AL', 53, 'NE'],
    ['América-RN',         '',    'RN', 45, 'NE'],
    ['Sampaio Corrêa',     'BR3', 'MA', 52, 'NE'],
    ['Altos',              '',    'PI', 42, 'NE'],
    ['Ferroviário',        '',    'CE', 46, 'NE'],
    ['Sousa',              '',    'PB', 40, 'NE'],
    ['Juazeirense',        '',    'BA', 43, 'NE'],
    ['Sergipe',            '',    'SE', 41, 'NE'],
    ['Treze',              '',    'PB', 40, 'NE'],

    // ── Norte e Centro-Oeste (Copa Verde) ────────────────────────────
    ['Brasiliense',        '',    'DF', 46, 'N'],
    ['Gama',               '',    'DF', 44, 'N'],
    ['Manaus',             'BR3', 'AM', 48, 'N'],
    ['Águia de Marabá',    '',    'PA', 42, 'N'],
    ['Porto Velho',        '',    'RO', 38, 'N'],
    ['Rio Branco-AC',      '',    'AC', 37, 'N'],
    ['Nova Mutum',         '',    'MT', 40, 'N'],
    ['Trem',               '',    'AP', 36, 'N'],
    ['São Raimundo-RR',    '',    'RR', 36, 'N'],
    ['Humaitá',            '',    'AC', 35, 'N'],
];

/**
 * A UF e a região dos clubes que vêm do catálogo do Copero.
 *
 * Aquele arquivo não tem estado — ele é mundial, e estado só importa aqui. Sem
 * este mapa, Palmeiras e Flamengo não teriam estadual pra disputar.
 */
const FUT_UF_DO_COPERO = [
    'Palmeiras'      => ['SP', ''],   'Flamengo'      => ['RJ', ''],
    'Corinthians'    => ['SP', ''],   'Fluminense'    => ['RJ', ''],
    'São Paulo'      => ['SP', ''],   'Botafogo'      => ['RJ', ''],
    'RB Bragantino'  => ['SP', ''],   'Vasco da Gama' => ['RJ', ''],
    'Mirassol'       => ['SP', ''],   'Atlético-MG'   => ['MG', ''],
    'Novorizontino'  => ['SP', ''],   'Cruzeiro'      => ['MG', ''],
    'Athletico-PR'   => ['PR', ''],   'América-MG'    => ['MG', ''],
    'Coritiba'       => ['PR', ''],   'Grêmio'        => ['RS', ''],
    'Londrina'       => ['PR', ''],   'Internacional' => ['RS', ''],
    'Juventude'      => ['RS', ''],   'Bahia'         => ['BA', 'NE'],
    'Chapecoense'    => ['SC', ''],   'Sport Recife'  => ['PE', 'NE'],
    'Criciúma'       => ['SC', ''],   'Ceará'         => ['CE', 'NE'],
    'Avaí'           => ['SC', ''],   'Fortaleza'     => ['CE', 'NE'],
    'Figueirense'    => ['SC', ''],   'ABC'           => ['RN', 'NE'],
    'Cuiabá'         => ['MT', 'N'],  'Botafogo-PB'   => ['PB', 'NE'],
    'Goiás'          => ['GO', 'N'],  'Paysandu'      => ['PA', 'N'],
    'Vila Nova'      => ['GO', 'N'],  'Remo'          => ['PA', 'N'],
    'Volta Redonda'  => ['RJ', ''],   'Ypiranga'      => ['RS', ''],
];

/**
 * TODOS os clubes brasileiros do jogo: os do Copero mais os daqui.
 *
 * @return array nome => ['nome','div','uf','forca','regiao','escudo']
 */
function futClubesDoBrasil(): array
{
    require_once __DIR__ . '/copero_clubes.php';

    $out = [];
    foreach (COPERO_CLUBES as $c) {
        if (!in_array($c[1], ['BR1', 'BR2', 'BR3'], true)) continue;
        [$uf, $regiao] = FUT_UF_DO_COPERO[$c[0]] ?? ['', ''];
        $out[$c[0]] = ['nome' => $c[0], 'div' => $c[1], 'uf' => $uf,
                       'forca' => (int)$c[2], 'regiao' => $regiao, 'escudo' => $c[3] ?? ''];
    }

    /* Os daqui ENTRAM POR CIMA: o Santos está como BR2 no Copero e como BR1
       aqui, e é esta divisão que vale no jogo. Sobrescrever em vez de pular
       é o que deixa a lista de cá ser a fonte da verdade da divisão. */
    foreach (FUT_CLUBES_BR_EXTRA as [$nome, $div, $uf, $forca, $regiao]) {
        $escudo = $out[$nome]['escudo'] ?? '';
        $out[$nome] = ['nome' => $nome, 'div' => $div, 'uf' => $uf,
                       'forca' => $forca, 'regiao' => $regiao, 'escudo' => $escudo];
    }
    return $out;
}

/** Os clubes de uma divisão nacional. */
function futClubesDaDivisao(string $div): array
{
    return array_filter(futClubesDoBrasil(), fn($c) => $c['div'] === $div);
}

/** Os clubes de um estadual. */
function futClubesDoEstado(string $uf): array
{
    return array_filter(futClubesDoBrasil(), fn($c) => $c['uf'] === $uf);
}

/** Os clubes de uma copa regional (NE = Nordeste, N = Verde). */
function futClubesDaRegiao(string $regiao): array
{
    return array_filter(futClubesDoBrasil(), fn($c) => $c['regiao'] === $regiao);
}
