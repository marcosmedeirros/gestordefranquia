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
/**
 * As competições, e o quanto cada uma aceita zebra.
 *
 * A `zebra` é a fatia do resultado que ignora quem é mais forte. Liga é
 * campeonato longo e quase sempre premia o melhor; copa é mata-mata e
 * derruba favorito o tempo todo. Sem essa diferença, ganhar a copa seria
 * só uma liga mais fácil, e as duas contariam a mesma história.
 */
const COPERO_COMPETICOES = [
    // id            nome genérico            escopo        zebra
    'liga'    => ['Campeonato Nacional',    'nacional',     0.10],
    'copa'    => ['Copa Nacional',          'nacional',     0.42],
    'cont'    => ['Torneio Continental',    'continental',  0.22],
    'mundial' => ['Mundial de Clubes',      'mundial',      0.16],
];

/**
 * O nome da copa nacional de cada país.
 *
 * O campeonato já tem nome próprio em COPERO_LIGAS (Bundesliga, LaLiga,
 * Ligue 1). Faltava a copa, que é outro torneio e merece o nome dela —
 * ganhar a DFB-Pokal não é ganhar a Bundesliga.
 */
const COPERO_COPAS = [
    'BRA' => 'Copa do Brasil',        'ARG' => 'Copa Argentina',
    'URU' => 'Copa Uruguay',          'CHI' => 'Copa Chile',
    'COL' => 'Copa Colombia',         'ENG' => 'FA Cup',
    'ESP' => 'Copa del Rey',          'ITA' => 'Coppa Italia',
    'GER' => 'DFB-Pokal',             'FRA' => 'Coupe de France',
    'POR' => 'Taça de Portugal',      'NED' => 'KNVB Beker',
    'BEL' => 'Beker van België',      'TUR' => 'Türkiye Kupası',
    'RUS' => 'Copa da Rússia',        'SCO' => 'Scottish Cup',
    'GRE' => 'Copa da Grécia',        'USA' => 'US Open Cup',
    'MEX' => 'Copa MX',               'KSA' => "King's Cup",
    'JPN' => 'Copa do Imperador',     'KOR' => 'Copa da Coreia',
    'EGY' => 'Copa do Egito',         'MAR' => 'Taça do Trono',
    'RSA' => 'Nedbank Cup',           'AUS' => 'Australia Cup',
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
 * Todas com marca de FUTEBOL. A primeira versão saiu com bola lisa e taça de
 * boca larga, que é a silhueta de troféu de futebol americano — os gomos de
 * pentágono na bola e o cálice estreito são o que faz o esporte se reconhecer
 * de relance.
 */
const COPERO_TACAS = [
    // Liga: cálice clássico, base larga.
    'liga' => ['#eab308',
        '<path d="M21 7h22v15c0 8-4 13-11 15-7-2-11-7-11-15z" fill="currentColor"/>'
      . '<path d="M21 10h-5v5c0 4 2 7 5 8M43 10h5v5c0 4-2 7-5 8" stroke="currentColor" stroke-width="2.6" fill="none"/>'
      . '<rect x="29" y="37" width="6" height="9" fill="currentColor"/>'
      . '<rect x="20" y="46" width="24" height="6" rx="2" fill="currentColor"/>'],
    // Copa: cálice alto e estreito, com pé longo.
    'copa' => ['#e5e7eb',
        '<path d="M24 6h16v16c0 7-3 11-8 13-5-2-8-6-8-13z" fill="currentColor"/>'
      . '<path d="M24 9h-6v7c0 5 3 8 6 9M40 9h6v7c0 5-3 8-6 9" stroke="currentColor" stroke-width="2.6" fill="none"/>'
      . '<rect x="30" y="35" width="4" height="12" fill="currentColor"/>'
      . '<path d="M22 47h20l2 6H20z" fill="currentColor"/>'],
    // Continental: as alças largas, silhueta de "orelhuda".
    'cont' => ['#c0c0c0',
        '<path d="M22 7h20v15c0 8-4 13-10 15-6-2-10-7-10-15z" fill="currentColor"/>'
      . '<path d="M22 8c-9 0-13 5-13 11s5 11 12 12M42 8c9 0 13 5 13 11s-5 11-12 12" '
      . 'stroke="currentColor" stroke-width="4.5" fill="none" stroke-linecap="round"/>'
      . '<rect x="29" y="37" width="6" height="8" fill="currentColor"/>'
      . '<path d="M20 45h24l3 8H17z" fill="currentColor"/>'],
    // Mundial: a bola de futebol em cima, com os gomos à mostra.
    'mundial' => ['#fbbf24',
        '<circle cx="32" cy="19" r="13" fill="currentColor"/>'
      . '<path d="M32 11l5.5 4-2 6.5h-7L26.5 15z" fill="#7c2d12"/>'
      . '<path d="M32 6v5M19.5 16l6.5 -1M44.5 16l-6.5 -1M24 30l2.5-4M40 30l-2.5-4" '
      . 'stroke="#7c2d12" stroke-width="1.6"/>'
      . '<rect x="29" y="33" width="6" height="10" fill="currentColor"/>'
      . '<rect x="19" y="43" width="26" height="7" rx="2" fill="currentColor"/>'],
    // Chuteira: com travas embaixo, que é o que a distingue de um sapato.
    'chuteira' => ['#fcd34d',
        '<path d="M9 37c5-9 14-14 25-14 9 0 15 3 17 8-3 8-13 13-25 13-8 0-14-3-17-7z" fill="currentColor"/>'
      . '<path d="M19 27l2.5 4M26 24l2.5 4M33 23l2.5 4" stroke="#78350f" stroke-width="2"/>'
      . '<path d="M11 44h34l-1.5 5H12.5z" fill="currentColor" opacity=".7"/>'
      . '<path d="M16 49v4M24 49v4M32 49v4M40 49v4" stroke="currentColor" stroke-width="2.6"/>'],
    // Bola de Ouro: a bola sobre o pedestal, gomos visíveis.
    'bola_ouro' => ['#facc15',
        '<circle cx="32" cy="21" r="14" fill="currentColor"/>'
      . '<path d="M32 12l6 4.5-2.3 7h-7.4L26 16.5z" fill="#78350f"/>'
      . '<path d="M32 7v5M18.5 18l7-1.5M45.5 18l-7-1.5M24.5 33l2-4.5M39.5 33l-2-4.5" '
      . 'stroke="#78350f" stroke-width="1.7"/>'
      . '<rect x="29" y="36" width="6" height="9" fill="currentColor"/>'
      . '<rect x="20" y="45" width="24" height="7" rx="2" fill="currentColor"/>'],
    // Rei da América: a coroa.
    'rei_america' => ['#34d399',
        '<path d="M12 41l-4-21 11 8 9-15 9 15 11-8-4 21z" fill="currentColor"/>'
      . '<rect x="12" y="43" width="40" height="7" rx="2" fill="currentColor"/>'
      . '<circle cx="20" cy="30" r="2.4" fill="#065f46"/><circle cx="32" cy="27" r="2.4" fill="#065f46"/>'
      . '<circle cx="44" cy="30" r="2.4" fill="#065f46"/>'],
    // Artilheiro: a bola no fundo da rede.
    'artilheiro' => ['#f97316',
        '<circle cx="32" cy="26" r="12" fill="currentColor"/>'
      . '<path d="M32 18l5 3.7-1.9 6h-6.2L27 21.7z" fill="#7c2d12"/>'
      . '<path d="M12 14h40v30H12z" stroke="currentColor" stroke-width="2" fill="none" opacity=".55"/>'
      . '<path d="M20 14v30M28 14v30M36 14v30M44 14v30M12 22h40M12 30h40M12 38h40" '
      . 'stroke="currentColor" stroke-width=".8" opacity=".35"/>'],
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
