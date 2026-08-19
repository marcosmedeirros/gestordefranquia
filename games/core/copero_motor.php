<?php
/**
 * O motor do Copero: identidade, progressão e eventos.
 *
 * Mora no servidor e não no JavaScript pelo mesmo motivo do caminho.php: é
 * aqui que a carreira vira registro e prêmio, e número que o cliente manda é
 * número que o cliente escolhe. O JS recebe estas tabelas prontas só pra
 * desenhar.
 */

require_once __DIR__ . '/copero_clubes.php';

/** Idade em que a carreira começa e o teto em que ela acaba. */
const COPERO_IDADE_INICIAL = 16;
const COPERO_IDADE_FINAL   = 40;

/** Quantos anos cada modo avança por rodada. */
const COPERO_MODOS = [
    'classico' => ['nome' => 'Clássico', 'passo' => 1, 'sub' => 'ano a ano, cada decisão conta'],
    'rapido'   => ['nome' => 'Rápido',   'passo' => 2, 'sub' => 'de dois em dois, carreira em metade do tempo'],
];

/**
 * As posições, no desenho do campo.
 *
 * [sigla => [nome, linha (0 = gol, 4 = ataque), peso de gol, peso de assist.]]
 * Os pesos são o que faz um zagueiro não terminar artilheiro: a mesma
 * temporada rende números diferentes conforme onde o jogador atua.
 */
const COPERO_POSICOES = [
    'GOL' => ['Goleiro',        0, 0.00, 0.01],
    'ZAG' => ['Zagueiro',       1, 0.06, 0.04],
    'LE'  => ['Lateral esq.',   1, 0.08, 0.22],
    'LD'  => ['Lateral dir.',   1, 0.08, 0.22],
    'VOL' => ['Volante',        2, 0.10, 0.18],
    'MC'  => ['Meio-campista',  2, 0.18, 0.30],
    'ME'  => ['Meia esq.',      2, 0.22, 0.34],
    'MD'  => ['Meia dir.',      2, 0.22, 0.34],
    'MEI' => ['Meia-atacante',  3, 0.38, 0.42],
    'PE'  => ['Ponta esq.',     4, 0.46, 0.34],
    'PD'  => ['Ponta dir.',     4, 0.46, 0.34],
    'CA'  => ['Centroavante',   4, 0.68, 0.18],
];

/**
 * As nacionalidades. Menos que o original, a pedido — as que têm liga no
 * catálogo, mais um punhado que dá cor à carreira sem inchar a lista.
 */
const COPERO_PAISES = [
    'BRA' => 'Brasil',     'ARG' => 'Argentina',  'URU' => 'Uruguai',
    'CHI' => 'Chile',      'COL' => 'Colômbia',   'ENG' => 'Inglaterra',
    'ESP' => 'Espanha',    'ITA' => 'Itália',     'GER' => 'Alemanha',
    'FRA' => 'França',     'POR' => 'Portugal',   'NED' => 'Holanda',
    'BEL' => 'Bélgica',    'CRO' => 'Croácia',    'TUR' => 'Turquia',
    'RUS' => 'Rússia',     'SCO' => 'Escócia',    'GRE' => 'Grécia',
    'USA' => 'Estados Unidos', 'MEX' => 'México', 'KSA' => 'Arábia Saudita',
    'JPN' => 'Japão',      'KOR' => 'Coreia do Sul', 'EGY' => 'Egito',
    'MAR' => 'Marrocos',   'RSA' => 'África do Sul', 'AUS' => 'Austrália',
    'NGA' => 'Nigéria',    'SEN' => 'Senegal',    'CIV' => 'Costa do Marfim',
];

/**
 * As faixas de cor do OVR: [mínimo, rótulo, cor].
 *
 * 96+ é roxo, como pedido. As outras seguem a leitura natural — quem está
 * em 50 e quem está em 90 têm que se distinguir de relance, sem ler o número.
 */
const COPERO_FAIXAS_OVR = [
    [96, 'lenda',     '#a855f7'],
    [90, 'craque',    '#38bdf8'],
    [80, 'elite',     '#eab308'],
    [70, 'titular',   '#94a3b8'],
    [60, 'promessa',  '#f97316'],
    [0,  'iniciante', '#ea580c'],
];

function coperoCorDoOvr(int $ovr): array
{
    foreach (COPERO_FAIXAS_OVR as [$min, $rotulo, $cor]) {
        if ($ovr >= $min) return ['rotulo' => $rotulo, 'cor' => $cor];
    }
    return ['rotulo' => 'iniciante', 'cor' => '#ea580c'];
}

/**
 * Quanto o jogador vale, em euros.
 *
 * A curva foi ajustada aos pontos de referência do gênero: OVR 50 vale
 * ~€100K, 75 vale ~€6M, 81 vale ~€27M e 90 vale ~€100M. Isso dá cerca de
 * ×1.19 por ponto de OVR — exponencial, e é o que faz subir de 88 pra 90
 * valer mais que a carreira inteira até ali.
 *
 * A idade é o outro fator: pico entre 22 e 26, e queda firme depois dos 28.
 * É o que faz o fim de carreira doer sem a tela precisar dizer nada.
 */
function coperoValor(int $ovr, int $idade, int $forcaClube): int
{
    if ($ovr < 45) return 50000;

    $base = 100000 * pow(1.19, $ovr - 50);

    // Promessa nova vale mais que veterano do mesmo OVR: parte do preço é o
    // que ainda vem pela frente.
    if     ($idade <= 21) $base *= 0.70 + (($idade - 16) * 0.06);
    elseif ($idade <= 26) $base *= 1.00;
    elseif ($idade <= 29) $base *= 0.85;
    elseif ($idade <= 32) $base *= 0.55;
    elseif ($idade <= 35) $base *= 0.25;
    else                  $base *= max(0.04, 0.12 - (($idade - 35) * 0.02));

    // A vitrine mexe pouco: quem decide o preço é o jogador, não o escudo.
    $base *= 0.88 + ($forcaClube / 400);

    // Arredonda como o mercado fala: milhares embaixo, milhões em cima.
    if ($base >= 10000000) return (int)(round($base / 1000000) * 1000000);
    if ($base >= 1000000)  return (int)(round($base / 100000) * 100000);
    return (int)(round($base / 10000) * 10000);
}

/**
 * Quanto o OVR anda num ano.
 *
 * Jovem em clube forte cresce rápido; veterano cai, e cai mais depressa
 * quanto mais velho. O clube pesa porque trocar de vitrine é a decisão
 * central do jogo — se jogar no Ypiranga rendesse igual ao Real Madrid, as
 * ofertas não significariam nada.
 */
function coperoEvolucao(int $ovr, int $idade, int $forcaClube): int
{
    $ambiente = ($forcaClube - $ovr) / 10;        // clube acima de você puxa

    if ($idade <= 21)      $delta = mt_rand(2, 6) + $ambiente;
    elseif ($idade <= 25)  $delta = mt_rand(1, 4) + $ambiente * 0.8;
    elseif ($idade <= 29)  $delta = mt_rand(-1, 2) + $ambiente * 0.5;
    elseif ($idade <= 33)  $delta = mt_rand(-3, 1);
    else                   $delta = mt_rand(-5, -1);

    // Teto: passar de 90 é raro e não acontece por acumular ano.
    if ($ovr >= 90) $delta = min($delta, mt_rand(0, 1));
    if ($ovr >= 96) $delta = min($delta, 0);

    return (int)max(35, min(99, $ovr + round($delta)));
}

/**
 * Os números de uma temporada: jogos, gols e assistências.
 *
 * Saem da posição, do OVR e da força do clube — um centroavante de 90 num
 * clube forte marca muito; o mesmo jogador num clube fraco joga menos e
 * marca menos, porque o time chega menos.
 */
function coperoTemporada(string $posicao, int $ovr, int $forcaClube): array
{
    $pos = COPERO_POSICOES[$posicao] ?? COPERO_POSICOES['MC'];
    [, , $pesoGol, $pesoAst] = $pos;

    // Quem está muito abaixo do clube joga menos: é o banco, sem precisar
    // dizer "você ficou no banco" na tela.
    $encaixe = max(0.35, min(1.15, 1 + (($ovr - $forcaClube) / 40)));
    $jogos   = (int)round(mt_rand(26, 42) * $encaixe);
    $jogos   = max(4, min(52, $jogos));

    $qualidade = max(0.2, ($ovr - 45) / 45);
    $gols = (int)round($jogos * $pesoGol * $qualidade * (mt_rand(75, 130) / 100));
    $ast  = (int)round($jogos * $pesoAst * $qualidade * (mt_rand(70, 135) / 100));

    return ['jogos' => $jogos, 'gols' => max(0, $gols), 'ast' => max(0, $ast)];
}

/**
 * As competições que um clube pode ganhar, e o desenho de cada taça.
 *
 * `dificuldade` é o quão forte o clube precisa ser pra ter chance real. A
 * liga nacional é a mais fácil (você só precisa ser o melhor do seu país); a
 * continental é a mais dura, porque disputa com o continente inteiro.
 *
 * As taças são DESENHADAS aqui, em SVG. Foto de taça de verdade é imagem de
 * terceiro e o projeto não hospeda isso — e taça em SVG aparece igual em todo
 * aparelho, sem depender de rede, como já foi feito com as bandeiras.
 */
const COPERO_COMPETICOES = [
    // id            nome                     escopo      dificuldade
    'liga'    => ['Liga Nacional',          'nacional',      55],
    'copa'    => ['Copa Nacional',          'nacional',      48],
    'cont'    => ['Torneio Continental',    'continental',   82],
    'mundial' => ['Mundial de Clubes',      'mundial',       92],
];

/** O nome do torneio continental de cada continente. */
const COPERO_CONTINENTAL = [
    'SAM' => 'Libertadores',
    'EUR' => 'Champions League',
    'NAM' => 'Concacaf Champions Cup',
    'ASI' => 'AFC Champions League',
    'AFR' => 'CAF Champions League',
    'OCE' => 'OFC Champions League',
];

/**
 * Os prêmios individuais. Dependem do jogador, não do clube — é o que
 * separa "meu time ganhou" de "eu fui o melhor".
 */
const COPERO_PREMIOS = [
    'artilheiro'  => ['Artilheiro da Liga', 'gols'],
    // A Chuteira é acima da artilharia: não basta ser o melhor do seu país,
    // tem que ser um número que se destaca em qualquer lugar.
    'chuteira'    => ['Chuteira de Ouro',   'gols'],
    'bola_ouro'   => ['Bola de Ouro',       'ovr'],
    // Só pra quem joga na América do Sul — é o prêmio do continente.
    'rei_america' => ['Rei da América',     'ovr'],
];

/**
 * As taças em SVG: id => [cor, desenho].
 *
 * Cada uma tem silhueta própria — a continental é a de alças largas, a de
 * liga é o escudo, a copa é o cálice alto. A pessoa reconhece pela forma
 * antes de ler o nome.
 */
const COPERO_TACAS = [
    'liga' => ['#eab308',
        '<path d="M20 6h24v18c0 8-5 14-12 16-7-2-12-8-12-16z" fill="currentColor"/>'
      . '<rect x="28" y="42" width="8" height="8" fill="currentColor"/>'
      . '<rect x="20" y="50" width="24" height="6" rx="2" fill="currentColor"/>'],
    'copa' => ['#e5e7eb',
        '<path d="M22 6h20v12c0 9-4 15-10 17-6-2-10-8-10-17z" fill="currentColor"/>'
      . '<path d="M22 10h-6v6c0 5 3 8 6 9M42 10h6v6c0 5-3 8-6 9" stroke="currentColor" stroke-width="3" fill="none"/>'
      . '<rect x="29" y="35" width="6" height="11" fill="currentColor"/>'
      . '<rect x="20" y="46" width="24" height="8" rx="2" fill="currentColor"/>'],
    'cont' => ['#c0c0c0',
        '<path d="M18 8h28v14c0 10-6 17-14 19-8-2-14-9-14-19z" fill="currentColor"/>'
      . '<path d="M18 8c-8 0-12 5-12 11s5 10 11 11M46 8c8 0 12 5 12 11s-5 10-11 11" stroke="currentColor" stroke-width="4" fill="none"/>'
      . '<rect x="29" y="41" width="6" height="8" fill="currentColor"/>'
      . '<path d="M18 49h28l3 7H15z" fill="currentColor"/>'],
    'mundial' => ['#fbbf24',
        '<circle cx="32" cy="20" r="13" fill="currentColor"/>'
      . '<path d="M24 31c-2 6-2 12 0 18h16c2-6 2-12 0-18z" fill="currentColor"/>'
      . '<rect x="18" y="49" width="28" height="7" rx="2" fill="currentColor"/>'],
    // A chuteira é a própria chuteira, dourada; o Rei da América é a coroa.
    'chuteira' => ['#fcd34d',
        '<path d="M8 38c5-9 14-15 26-15 9 0 16 3 18 9-3 9-14 15-26 15-9 0-15-3-18-9z" fill="currentColor"/>'
      . '<path d="M10 43h36l-2 8H14z" fill="currentColor" opacity=".65"/>'
      . '<path d="M20 28l3 5M27 25l3 5M34 24l3 5" stroke="#78350f" stroke-width="2"/>'],
    'rei_america' => ['#34d399',
        '<path d="M12 40l-4-20 11 8 9-14 9 14 11-8-4 20z" fill="currentColor"/>'
      . '<rect x="12" y="42" width="40" height="7" rx="2" fill="currentColor"/>'
      . '<circle cx="20" cy="29" r="2.4" fill="#065f46"/><circle cx="32" cy="26" r="2.4" fill="#065f46"/>'
      . '<circle cx="44" cy="29" r="2.4" fill="#065f46"/>'],
    'artilheiro' => ['#f97316',
        '<path d="M10 40c6-10 16-16 28-16 8 0 14 3 16 8-4 8-14 14-26 14-8 0-15-2-18-6z" fill="currentColor"/>'
      . '<path d="M12 44h34l-2 8H16z" fill="currentColor"/>'],
    'bola_ouro' => ['#facc15',
        '<circle cx="32" cy="22" r="14" fill="currentColor"/>'
      . '<rect x="29" y="36" width="6" height="10" fill="currentColor"/>'
      . '<rect x="20" y="46" width="24" height="8" rx="2" fill="currentColor"/>'],
];

/**
 * O catálogo de eventos.
 *
 * Cada um é: título, uma frase de contexto, e cartas com efeito e
 * probabilidade VISÍVEIS. A probabilidade na tela é o coração do jogo — sem
 * ela a escolha vira chute, e com ela vira aposta.
 *
 * `quando` decide se o evento pode cair agora. `tipo` separa os que mexem em
 * OVR dos que mexem em clube.
 */
function coperoEventos(): array
{
    return [
        [
            'id' => 'concentracao', 'tipo' => 'ovr',
            'titulo' => 'Concentração extra',
            'texto'  => 'Uma preparação especial pode melhorar seu jogo, mas o esforço extra pode cobrar seu preço.',
            'quando' => fn($s) => $s['idade'] >= 18,
            'cartas' => [
                ['rotulo' => 'Fazer', 'efeitos' => [
                    ['chance' => 65, 'ovr' => +4, 'texto' => '+4 OVR'],
                    ['chance' => 35, 'ovr' => -3, 'texto' => '-3 OVR'],
                ]],
                ['rotulo' => 'Preparação habitual', 'efeitos' => [
                    ['chance' => 100, 'ovr' => 0, 'texto' => 'Sem mudanças'],
                ]],
            ],
        ],
        [
            'id' => 'treino_dobro', 'tipo' => 'ovr',
            'titulo' => 'Treino em dobro',
            'texto'  => 'Dois treinos por dia para melhorar seu desempenho.',
            'quando' => fn($s) => $s['idade'] >= 17 && $s['idade'] <= 30,
            'cartas' => [
                ['rotulo' => 'Treinar forte', 'efeitos' => [
                    ['chance' => 65, 'ovr' => +3, 'texto' => 'Titular'],
                    ['chance' => 35, 'ovr' => -4, 'texto' => 'Lesão'],
                ]],
                ['rotulo' => 'Reduzir a carga', 'efeitos' => [
                    ['chance' => 100, 'ovr' => 0, 'jogos' => -8, 'texto' => 'Menos minutos'],
                ]],
            ],
        ],
        [
            'id' => 'dieta', 'tipo' => 'ovr',
            'titulo' => 'Plano alimentar',
            'texto'  => 'Um nutricionista propõe ajustar sua dieta. Pode melhorar seu desempenho ou dar errado.',
            'quando' => fn($s) => $s['idade'] >= 19,
            'cartas' => [
                ['rotulo' => 'Seguir o plano', 'efeitos' => [
                    ['chance' => 60, 'ovr' => +3, 'texto' => '+3 OVR'],
                    ['chance' => 40, 'ovr' => -2, 'texto' => '-2 OVR'],
                ]],
                ['rotulo' => 'Manter sua dieta', 'efeitos' => [
                    ['chance' => 100, 'ovr' => 0, 'texto' => 'Sem mudanças'],
                ]],
            ],
        ],
        [
            'id' => 'polemica', 'tipo' => 'ovr',
            'titulo' => 'Declaração polêmica',
            'texto'  => 'Você critica publicamente o treinador após uma derrota dura, e o vestiário fica tenso.',
            'quando' => fn($s) => $s['idade'] >= 22 && $s['ovr'] >= 70,
            'cartas' => [
                ['rotulo' => 'Pedir desculpas', 'efeitos' => [
                    ['chance' => 100, 'ovr' => 0, 'jogos' => -10, 'texto' => 'Seus minutos diminuem'],
                ]],
                ['rotulo' => 'Manter o que disse', 'efeitos' => [
                    ['chance' => 45, 'ovr' => +2, 'texto' => 'O grupo te respeita'],
                    ['chance' => 55, 'ovr' => -3, 'texto' => 'Você fica de fora'],
                ]],
            ],
        ],
        [
            'id' => 'fiscal', 'tipo' => 'ovr',
            'titulo' => 'Problemas fiscais',
            'texto'  => 'Uma investigação fiscal coloca em dúvida sua permanência no país.',
            'quando' => fn($s) => $s['idade'] >= 25 && $s['valor'] >= 8000000,
            'cartas' => [
                ['rotulo' => 'Encarar o processo', 'efeitos' => [
                    ['chance' => 100, 'ovr' => -3, 'texto' => '-3 OVR pela distração'],
                ]],
                ['rotulo' => 'Se antecipar e negociar', 'efeitos' => [
                    ['chance' => 55, 'ovr' => 0, 'texto' => 'Resolvido em silêncio'],
                    ['chance' => 45, 'ovr' => -5, 'texto' => 'O caso vaza'],
                ]],
            ],
        ],
        [
            'id' => 'capitao', 'tipo' => 'ovr',
            'titulo' => 'A braçadeira',
            'texto'  => 'O treinador te oferece a capitania. Vem com responsabilidade e com holofote.',
            'quando' => fn($s) => $s['idade'] >= 24 && $s['ovr'] >= 75,
            'cartas' => [
                ['rotulo' => 'Aceitar', 'efeitos' => [
                    ['chance' => 70, 'ovr' => +3, 'texto' => 'Você cresce com ela'],
                    ['chance' => 30, 'ovr' => -2, 'texto' => 'O peso atrapalha'],
                ]],
                ['rotulo' => 'Recusar', 'efeitos' => [
                    ['chance' => 100, 'ovr' => 0, 'texto' => 'Sem mudanças'],
                ]],
            ],
        ],
    ];
}

/** Sorteia um efeito respeitando as probabilidades da carta. */
function coperoSortearEfeito(array $carta): array
{
    $r = mt_rand(1, 100);
    $acc = 0;
    foreach ($carta['efeitos'] as $ef) {
        $acc += (int)$ef['chance'];
        if ($r <= $acc) return $ef;
    }
    return end($carta['efeitos']);
}

/**
 * As conquistas: id => [ícone, nome, descrição, teste].
 *
 * O teste recebe o estado FINAL da carreira. Só cai no fim de propósito —
 * conquista de carreira é sobre o conjunto, não sobre o ano bom.
 */
function coperoConquistas(): array
{
    return [
        'estreante'   => ['🌱', 'Primeiro contrato', 'Termine a carreira com pelo menos 100 jogos.',
                          fn($c) => $c['jogos'] >= 100],
        'centenario'  => ['💯', 'Centenário',        'Marque 100 gols na carreira.',
                          fn($c) => $c['gols'] >= 100],
        'artilheiro'  => ['⚽', 'Artilheiro eterno', 'Marque 250 gols na carreira.',
                          fn($c) => $c['gols'] >= 250],
        'garcom'      => ['🎩', 'Garçom',            'Dê 100 assistências na carreira.',
                          fn($c) => $c['ast'] >= 100],
        'teto'        => ['🟣', 'Fora da curva',     'Chegue a 96 de OVR.',
                          fn($c) => $c['picoOvr'] >= 96],
        'elite'       => ['⭐', 'Nome da elite',     'Chegue a 90 de OVR.',
                          fn($c) => $c['picoOvr'] >= 90],
        'nomade'      => ['🧳', 'Nômade',            'Jogue por oito clubes diferentes.',
                          fn($c) => $c['clubes'] >= 8],
        'lenda_clube' => ['♾️', 'Lenda do clube',    'Faça 200 jogos por um mesmo clube.',
                          fn($c) => $c['maiorNoClube'] >= 200],
        'continentes' => ['🌍', 'Passaporte carimbado', 'Jogue em quatro continentes diferentes.',
                          fn($c) => $c['continentes'] >= 4],
        'de_baixo'    => ['📈', 'De baixo',          'Comece na 2ª divisão ou abaixo e chegue a um clube de 90+.',
                          fn($c) => $c['comecouAbaixo'] && $c['maiorForcaClube'] >= 90],
        'milionario'  => ['💶', 'Cifra de craque',   'Valer 100 milhões de euros.',
                          fn($c) => $c['picoValor'] >= 100000000],
        'longevo'     => ['🦾', 'Longevo',           'Jogue até os 38 anos ou mais.',
                          fn($c) => $c['idadeFinal'] >= 38],
        'um_clube_so' => ['🏠', 'Um clube só',       'Faça a carreira inteira em no máximo dois clubes.',
                          fn($c) => $c['clubes'] <= 2 && $c['temporadas'] >= 12],
    ];
}
