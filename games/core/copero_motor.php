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
    'ALG' => 'Argélia',    'TUN' => 'Tunísia',    'COD' => 'Congo',
    'IRN' => 'Irã',        'QAT' => 'Catar',

    // ── As 27 que faltavam ────────────────────────────────────────────
    //
    // O jogo já simulava 62 seleções: elas existiam pra DISPUTAR a Copa e o
    // continental, e sem elas o Marrocos levava 82% das Copas Africanas. Mas
    // só 35 dava pra vestir, e a lista das duas nunca bateu — quem quisesse
    // ser dinamarquês, peruano ou ganês não podia, mesmo com a seleção, a
    // bandeira e a força já no catálogo. Agora as duas listas são a mesma:
    // toda seleção que joga no jogo é uma seleção que você pode defender.
    'DEN' => 'Dinamarca',  'SUI' => 'Suíça',      'AUT' => 'Áustria',
    'UKR' => 'Ucrânia',    'SRB' => 'Sérvia',     'POL' => 'Polônia',
    'SWE' => 'Suécia',     'NOR' => 'Noruega',    'CZE' => 'Chéquia',
    'WAL' => 'País de Gales', 'IRL' => 'Irlanda',
    'ECU' => 'Equador',    'PER' => 'Peru',       'PAR' => 'Paraguai',
    'VEN' => 'Venezuela',  'BOL' => 'Bolívia',
    'CMR' => 'Camarões',   'GHA' => 'Gana',       'MLI' => 'Mali',
    'IRQ' => 'Iraque',     'UZB' => 'Uzbequistão','CHN' => 'China',
    'CAN' => 'Canadá',     'CRC' => 'Costa Rica', 'PAN' => 'Panamá',
    'JAM' => 'Jamaica',    'HON' => 'Honduras',
];

/**
 * As cores da camisa de cada seleção.
 *
 * A régua é a CAMISA DE CASA, e não a bandeira. A Itália é azul e a bandeira
 * é verde-branco-vermelha; a Holanda é laranja e a bandeira não tem laranja;
 * o Japão é azul-marinho e a bandeira é branca e vermelha. Tirar a cor da
 * bandeira daria camisa errada em um terço da lista — por isso é tabela
 * escrita à mão, e não algo derivado do BAND.
 *
 * `[padrão, primária, secundária, detalhe]`
 *
 * A primária é o tecido. A secundária pinta a gola e os punhos, e nas
 * listradas é a cor das barras. O detalhe é o contorno.
 *
 * Padrões: 'solida', 'listras' (verticais) e 'xadrez' (só a Croácia, e é
 * a assinatura dela — sem o xadrez a camisa croata é uma camisa branca
 * qualquer).
 */
const COPERO_CAMISAS = [
    // ── América do Sul ────────────────────────────────────────────────
    'BRA' => ['solida',  '#ffdf00', '#009b3a', '#00713a'],  // canarinho
    'ARG' => ['listras', '#75aadb', '#ffffff', '#0f2f66'],
    'URU' => ['solida',  '#5ba3dc', '#ffffff', '#0f2f66'],
    'CHI' => ['solida',  '#e11b22', '#ffffff', '#0039a6'],
    'COL' => ['solida',  '#fcd116', '#003893', '#ce1126'],
    // ── Europa ────────────────────────────────────────────────────────
    'ENG' => ['solida',  '#ffffff', '#ce1124', '#1a1a2e'],
    'ESP' => ['solida',  '#c60b1e', '#ffc400', '#7a0812'],
    'ITA' => ['solida',  '#1e4b9e', '#ffffff', '#0b2350'],  // azzurra, não a bandeira
    'GER' => ['solida',  '#ffffff', '#1a1a1a', '#dd0000'],
    'FRA' => ['solida',  '#21316f', '#ffffff', '#ed2939'],
    'POR' => ['solida',  '#7a1927', '#0d6b3e', '#ffe900'],
    'NED' => ['solida',  '#f36c21', '#ffffff', '#21468b'],  // laranja, não a bandeira
    'BEL' => ['solida',  '#e30613', '#fdda24', '#1a1a1a'],
    'CRO' => ['xadrez',  '#ffffff', '#d52b1e', '#0f2f66'],  // o xadrez é a assinatura
    'TUR' => ['solida',  '#e30a17', '#ffffff', '#7a0a10'],
    'RUS' => ['solida',  '#d52b1e', '#ffffff', '#0039a6'],
    'SCO' => ['solida',  '#16276b', '#ffffff', '#0d1a45'],
    'GRE' => ['solida',  '#0d5eaf', '#ffffff', '#083f77'],
    // ── América do Norte ──────────────────────────────────────────────
    'USA' => ['solida',  '#ffffff', '#002868', '#bf0a30'],
    'MEX' => ['solida',  '#006847', '#ffffff', '#ce1126'],
    // ── Ásia ──────────────────────────────────────────────────────────
    'KSA' => ['solida',  '#ffffff', '#006c35', '#044d27'],
    'JPN' => ['solida',  '#0b2f6b', '#ffffff', '#bc002d'],
    'KOR' => ['solida',  '#c8102e', '#ffffff', '#0b2f6b'],
    'IRN' => ['solida',  '#ffffff', '#239f40', '#da0000'],
    'QAT' => ['solida',  '#8a1538', '#ffffff', '#5c0e25'],
    // ── África ────────────────────────────────────────────────────────
    'EGY' => ['solida',  '#c8102e', '#ffffff', '#1a1a1a'],
    'MAR' => ['solida',  '#c1272d', '#006233', '#7a1418'],
    'RSA' => ['solida',  '#ffb612', '#007a4d', '#1a1a1a'],
    'AUS' => ['solida',  '#ffcd00', '#00843d', '#1a3a1a'],
    'NGA' => ['solida',  '#008751', '#ffffff', '#0b4a2e'],
    'SEN' => ['solida',  '#ffffff', '#00853f', '#fdef42'],
    'CIV' => ['solida',  '#f77f00', '#ffffff', '#009e60'],
    'ALG' => ['solida',  '#ffffff', '#007a3d', '#d21034'],
    'TUN' => ['solida',  '#ffffff', '#e70013', '#8a0a12'],
    'COD' => ['solida',  '#007fff', '#f7d618', '#0a5aa8'],

    // ── As 27 novas, pela CAMISA DE CASA ──────────────────────────────
    //
    // Mesma régra das outras: a camisa, não a bandeira. A Itália já era azul
    // com bandeira verde-branco-vermelha, e aqui a Venezuela é vinotinto com
    // bandeira amarelo-azul-vermelha, o Peru é branco com a faixa vermelha,
    // e o Paraguai é listrado — sem isso o paraguaio escolhia o país e
    // vestia uma camisa que ninguém reconhece.
    'DEN' => ['solida',  '#c60c30', '#ffffff', '#8a0820'],
    'SUI' => ['solida',  '#d52b1e', '#ffffff', '#8f1c13'],
    'AUT' => ['solida',  '#ed2939', '#ffffff', '#1a1a2e'],
    'UKR' => ['solida',  '#ffd500', '#005bbb', '#00408a'],
    'SRB' => ['solida',  '#c6363c', '#ffffff', '#0c4076'],
    'POL' => ['solida',  '#ffffff', '#dc143c', '#8f0d27'],
    'SWE' => ['solida',  '#fecc00', '#006aa7', '#00497a'],
    'NOR' => ['solida',  '#ba0c2f', '#ffffff', '#00205b'],
    'CZE' => ['solida',  '#d7141a', '#ffffff', '#11457e'],
    'WAL' => ['solida',  '#c8102e', '#00ab39', '#8a0a20'],
    'IRL' => ['solida',  '#169b62', '#ffffff', '#ff883e'],
    'ECU' => ['solida',  '#ffdd00', '#034ea2', '#ed1c24'],
    'PER' => ['solida',  '#ffffff', '#d91023', '#8f0b18'],   // a faixa vermelha
    'PAR' => ['listras', '#d52b1e', '#ffffff', '#0038a8'],
    'VEN' => ['solida',  '#7a1728', '#ffffff', '#4d0e19'],   // vinotinto
    'BOL' => ['solida',  '#007934', '#ffffff', '#004f22'],
    'CMR' => ['solida',  '#007a5e', '#fcd116', '#ce1126'],
    'GHA' => ['solida',  '#ffffff', '#fcd116', '#006b3f'],
    'MLI' => ['solida',  '#14b53a', '#fcd116', '#0b7a26'],
    'IRQ' => ['solida',  '#239f40', '#ffffff', '#14682a'],
    'UZB' => ['solida',  '#ffffff', '#0099b5', '#1eb53a'],
    'CHN' => ['solida',  '#de2910', '#ffde00', '#9a1c0b'],
    'CAN' => ['solida',  '#d80621', '#ffffff', '#8f0416'],
    'CRC' => ['solida',  '#ce1126', '#ffffff', '#002b7f'],
    'PAN' => ['solida',  '#d21034', '#ffffff', '#005293'],
    'JAM' => ['solida',  '#fed100', '#009b3a', '#1a1a1a'],
    'HON' => ['solida',  '#ffffff', '#0073cf', '#00509e'],
];

/** A camisa de quem ainda não escolheu país: o verde do próprio jogo. */
const COPERO_CAMISA_PADRAO = ['solida', '#166534', '#22c55e', '#22c55e'];

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
    'liga'    => ['Campeonato Nacional',    'nacional',     0.06],
    'copa'    => ['Copa Nacional',          'nacional',     0.26],
    'cont'    => ['Torneio Continental',    'continental',  0.22],
    // Os torneios continentais de baixo. Existem porque o clube médio também
    // tem o que disputar na Europa: sem eles, quem não briga por Champions
    // não tinha nenhuma noite europeia na carreira inteira.
    'cont2'   => ['Segunda Continental',    'continental',  0.30],
    'cont3'   => ['Terceira Continental',   'continental',  0.34],
    'mundial' => ['Mundial de Clubes',      'mundial',      0.16],
    // As supercopas não têm disputa própria: são jogo único entre quem já
    // ganhou outra coisa. Por isso a chance delas não sai de `adversarios`,
    // e sim de quem você é no ano — ver `titulosDaTemporada`.
    'supernac' => ['Supercopa Nacional',    'nacional',     0.00],
    'supercont'=> ['Supercopa Continental', 'continental',  0.00],
];

/** O nome da supercopa nacional de cada país. */
const COPERO_SUPERNAC = [
    'BRA' => 'Supercopa do Brasil',   'ARG' => 'Supercopa Argentina',
    'ENG' => 'Community Shield',      'ESP' => 'Supercopa de España',
    'ITA' => 'Supercoppa Italiana',   'GER' => 'DFL-Supercup',
    'FRA' => 'Trophée des Champions', 'POR' => 'Supertaça',
    'NED' => 'Johan Cruijff Schaal',  'TUR' => 'Süper Kupa',
];

/** E a supercopa continental: quem ganhou a principal contra quem ganhou a segunda. */
const COPERO_SUPERCONT = [
    'EUR' => 'Supercopa da Europa',
    'SAM' => 'Recopa Sul-Americana',
    'AFR' => 'Supercopa da CAF',
];

/**
 * A força da SELEÇÃO de cada país.
 *
 * Não sai da média das ligas de propósito. A Argentina tem seleção de ponta
 * e campeonato modesto; a Inglaterra tem o campeonato mais forte do mundo e
 * uma seleção que não ganha nada há décadas. Derivar um do outro apagaria
 * justamente o que torna a escolha de nacionalidade interessante.
 */
/**
 * `país => [força, continente]`.
 *
 * A lista vai MUITO além dos países jogáveis, e precisa ir: sem Gana,
 * Camarões, Argélia e Tunísia, o Marrocos levava 82% das Copas Africanas; e
 * com a Oceania tendo só a Austrália, o australiano era campeão continental
 * em 99% das carreiras. Estas seleções não são escolhíveis — elas existem
 * para DISPUTAR, que é o que faz o título valer.
 *
 * A Austrália joga na Ásia, como na vida real desde 2006.
 */
const COPERO_SELECOES = [
    // Europa
    'FRA' => [94,'EUR'], 'ESP' => [93,'EUR'], 'ENG' => [91,'EUR'], 'POR' => [89,'EUR'],
    'NED' => [88,'EUR'], 'GER' => [87,'EUR'], 'BEL' => [86,'EUR'], 'ITA' => [86,'EUR'],
    'CRO' => [84,'EUR'], 'DEN' => [81,'EUR'], 'SUI' => [80,'EUR'], 'AUT' => [80,'EUR'],
    'UKR' => [78,'EUR'], 'SRB' => [78,'EUR'], 'TUR' => [76,'EUR'], 'POL' => [76,'EUR'],
    'SWE' => [75,'EUR'], 'NOR' => [75,'EUR'], 'CZE' => [74,'EUR'], 'RUS' => [72,'EUR'],
    'GRE' => [72,'EUR'], 'SCO' => [72,'EUR'], 'WAL' => [72,'EUR'], 'IRL' => [71,'EUR'],
    // América do Sul
    'ARG' => [95,'SAM'], 'BRA' => [91,'SAM'], 'URU' => [83,'SAM'], 'COL' => [82,'SAM'],
    'ECU' => [76,'SAM'], 'PER' => [74,'SAM'], 'CHI' => [74,'SAM'], 'PAR' => [72,'SAM'],
    'VEN' => [70,'SAM'], 'BOL' => [66,'SAM'],
    // África
    'MAR' => [82,'AFR'], 'SEN' => [78,'AFR'], 'NGA' => [76,'AFR'], 'EGY' => [75,'AFR'],
    'CIV' => [75,'AFR'], 'ALG' => [75,'AFR'], 'CMR' => [74,'AFR'], 'TUN' => [73,'AFR'],
    'GHA' => [73,'AFR'], 'MLI' => [72,'AFR'], 'RSA' => [71,'AFR'], 'COD' => [70,'AFR'],
    // Ásia e Oceania — a Austrália disputa a Copa da Ásia.
    'JPN' => [79,'ASI'], 'IRN' => [77,'ASI'], 'KOR' => [76,'ASI'], 'AUS' => [73,'ASI'],
    'KSA' => [70,'ASI'], 'QAT' => [70,'ASI'], 'IRQ' => [68,'ASI'], 'UZB' => [68,'ASI'],
    'CHN' => [63,'ASI'],
    // América do Norte e Central
    'USA' => [78,'NAM'], 'MEX' => [78,'NAM'], 'CAN' => [75,'NAM'], 'CRC' => [71,'NAM'],
    'PAN' => [70,'NAM'], 'JAM' => [69,'NAM'], 'HON' => [66,'NAM'],
];

/**
 * O torneio de seleções de cada continente, e a Copa do Mundo.
 *
 * A Copa vem de quatro em quatro anos e o continental no meio do caminho —
 * é isso que faz uma carreira longa passar por três ou quatro delas, e que
 * transforma "estar no auge no ano certo" em sorte de verdade.
 */
/**
 * Quanto de overall a seleção tolera ABAIXO da própria força pra te convocar.
 *
 * Oito, e é pra ser apertado: dá 83 no Brasil e 87 na Argentina. Vestir a
 * camisa é privilégio de quem chegou ao topo — nascer num país forte é uma
 * escolha com dois lados, mais chance de título e mais dificuldade de entrar.
 *
 * Fica aqui e não no JS porque a conquista "Vestiu a amarelinha" é conferida
 * no SERVIDOR: se as duas réguas morassem em arquivos diferentes, uma mudava
 * e a outra ficava, e a conquista passaria a mentir.
 */
const COPERO_SELECAO_FOLGA = 8;

const COPERO_SELECAO_CONT = [
    'SAM' => 'Copa América',        'EUR' => 'Eurocopa',
    'AFR' => 'Copa Africana',       'ASI' => 'Copa da Ásia',
    'NAM' => 'Copa Ouro',
];

/**
 * O nome da copa nacional de cada país.
 *
 * O campeonato já tem nome próprio em COPERO_LIGAS (Bundesliga, LaLiga,
 * Ligue 1). Faltava a copa, que é outro torneio e merece o nome dela —
 * ganhar a DFB-Pokal não é ganhar a Bundesliga.
 */
/**
 * Os clássicos. Cada linha é um par de rivais.
 *
 * Serve pra uma coisa só, e ela é a que importa: trocar de clube pelo RIVAL
 * tem que doer. Sem isso o mercado era só uma tabela de força, e sair do
 * Palmeiras pro Corinthians era igual a sair do Palmeiras pro Bahia.
 */
const COPERO_RIVAIS = [
    ['Palmeiras', 'Corinthians'],   ['Flamengo', 'Fluminense'],
    ['Flamengo', 'Vasco da Gama'],  ['Botafogo', 'Flamengo'],
    ['São Paulo', 'Corinthians'],   ['São Paulo', 'Palmeiras'],
    ['Grêmio', 'Internacional'],    ['Atlético-MG', 'Cruzeiro'],
    ['Santos', 'Corinthians'],      ['Bahia', 'Sport Recife'],
    ['River Plate', 'Boca Juniors'],['Racing', 'Independiente'],
    ['Peñarol', 'Nacional'],        ['Colo-Colo', 'Universidad de Chile'],
    ['Real Madrid', 'Barcelona'],   ['Real Madrid', 'Atlético de Madrid'],
    ['Sevilla', 'Real Betis'],      ['Manchester United', 'Manchester City'],
    ['Manchester United', 'Liverpool'], ['Liverpool', 'Everton'],
    ['Arsenal', 'Tottenham'],       ['Arsenal', 'Chelsea'],
    ['Inter de Milão', 'AC Milan'], ['Juventus', 'Inter de Milão'],
    ['Roma', 'Lazio'],              ['Napoli', 'Juventus'],
    ['Bayern de Munique', 'Borussia Dortmund'],
    ['PSG', 'Marseille'],           ['Lyon', 'Saint-Étienne'],
    ['Benfica', 'Porto'],           ['Benfica', 'Sporting CP'],
    ['Ajax', 'Feyenoord'],          ['Celtic', 'Rangers'],
    ['Galatasaray', 'Fenerbahçe'],  ['Olympiacos', 'Panathinaikos'],
    ['CSKA Moscou', 'Spartak Moscou'], ['Al Hilal', 'Al Nassr'],
    ['Al Ahly', 'Zamalek'],         ['Club América', 'Chivas'],
    ['Wydad', 'Raja Casablanca'],   ['Boca Juniors', 'Racing'],
    // ── Os clássicos das ligas novas ──────────────────────────────────
    // Sem eles a troca pelo rival — que é uma das decisões mais caras do
    // jogo — só existia em quinze países, e a metade nova do mundo era uma
    // tabela de força sem história.
    ['Olimpia', 'Cerro Porteño'],       ['Alianza Lima', 'Universitario'],
    ['Barcelona SC', 'Emelec'],         ['Bolívar', 'The Strongest'],
    ['Caracas', 'Deportivo Táchira'],   ['Dinamo Zagreb', 'Hajduk Split'],
    ['Basel', 'Zürich'],                ['Young Boys', 'Basel'],
    ['Rapid Viena', 'Austria Viena'],   ['Dínamo de Kiev', 'Shakhtar Donetsk'],
    ['Estrela Vermelha', 'Partizan'],   ['Copenhague', 'Brøndby'],
    ['Rosenborg', 'Molde'],             ['AIK', 'Djurgården'],
    ['Legia', 'Lech Poznań'],           ['Sparta Praga', 'Slavia Praga'],
    ['Shanghai Port', 'Shanghai Shenhua'], ['Pakhtakor', 'Bunyodkor'],
    ['Al-Zawraa', 'Al-Quwa Al-Jawiya'], ['Hearts of Oak', 'Asante Kotoko'],
    ['Jaraaf', 'Casa Sports'],          ['ASEC Mimosas', 'Africa Sports'],
    ['Coton Sport', 'Canon Yaoundé'],   ['Stade Malien', 'Djoliba'],
    ['Saprissa', 'Alajuelense'],        ['CD Olimpia', 'Motagua'],
    ['Árabe Unido', 'Tauro'],           ['LA Galaxy', 'LAFC'],
];

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

    // ── As que faltavam, e as dos países novos ────────────────────────
    //
    // Seis ligas de primeira divisão já estavam sem copa (Irã, Catar,
    // Argélia, Tunísia, Nigéria e Congo): quem jogava lá disputava um
    // torneio sem nome. Sem entrada aqui o boletim mostrava a taça em
    // branco, o que é pior do que não ter torneio nenhum.
    'IRN' => 'Copa Hazfi',            'QAT' => 'Emir Cup',
    'ALG' => 'Copa da Argélia',       'TUN' => 'Copa da Tunísia',
    'NGA' => 'Copa da Nigéria',       'COD' => 'Copa do Congo',
    'PAR' => 'Copa Paraguay',         'PER' => 'Copa Perú',
    'ECU' => 'Copa Ecuador',          'VEN' => 'Copa Venezuela',
    'BOL' => 'Copa Bolivia',          'CRO' => 'Copa da Croácia',
    'SUI' => 'Copa da Suíça',         'AUT' => 'Copa da Áustria',
    'UKR' => 'Copa da Ucrânia',       'SRB' => 'Copa da Sérvia',
    'DEN' => 'Copa da Dinamarca',     'NOR' => 'Copa da Noruega',
    'SWE' => 'Svenska Cupen',         'POL' => 'Copa da Polônia',
    'CZE' => 'Copa da Chéquia',       'CHN' => 'Copa da China',
    'UZB' => 'Copa do Uzbequistão',   'IRQ' => 'Copa do Iraque',
    'GHA' => 'Copa de Gana',          'SEN' => 'Copa do Senegal',
    'CIV' => 'Copa da Costa do Marfim','CMR' => 'Copa de Camarões',
    'MLI' => 'Copa do Mali',          'CRC' => 'Copa da Costa Rica',
    'JAM' => 'Jamaica FA Cup',        'HON' => 'Copa de Honduras',
    'PAN' => 'Copa do Panamá',        'CAN' => 'Canadian Championship',
];

/** O nome do torneio continental de cada continente. */
const COPERO_CONTINENTAL = [
    'SAM' => 'Libertadores',
    'EUR' => 'Champions League',
    'NAM' => 'Concacaf Champions Cup',
    'ASI' => 'AFC Champions League',
    'AFR' => 'CAF Champions League',
];

/**
 * O segundo torneio continental, para quem não briga pelo primeiro.
 *
 * É o que dá noite europeia ao clube médio: sem isso, quem nunca chegou à
 * Champions terminava a carreira sem nenhuma competição continental.
 */
const COPERO_CONTINENTAL2 = [
    'SAM' => 'Sul-Americana',
    'EUR' => 'Europa League',
    'NAM' => 'Copa Centroamericana',
    'ASI' => 'AFC Champions League Two',
    'AFR' => 'CAF Confederation Cup',
];

/** E o terceiro, que só a Europa tem. */
const COPERO_CONTINENTAL3 = [
    'EUR' => 'Conference League',
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
    // Escudo, e nao calice: o titulo nacional e o scudetto, e escudo e a unica…
    'liga' => ['#e11d48',
        '<defs><linearGradient id="tc-liga-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".42"/><stop offset=".45" stop-color="#fff" stop-opacity=".04"/><stop offset="1" stop-color="#000" stop-opacity=".3"/></linearGradient></defs><path d="M16 5h32v21c0 14-9 23-16 28-7-5-16-14-16-28z" fill="currentColor"/><path d="M16 5h32v21c0 14-9 23-16 28-7-5-16-14-16-28z" fill="url(#tc-liga-g)"/><path d="M32 15l3.2 6.6 7.2.9-5.2 5.1 1.2 7.2L32 31.4l-6.4 3.4 1.2-7.2-5.2-5.1 7.2-.9z" fill="#7c2d12" opacity=".55"/>'],
    // Mata-mata: cone em V bem fechado, alças finas coladas ao corpo, HASTE…
    'copa' => ['#e5e7eb',
        '<defs><linearGradient id="tc-copa-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset=".38" stop-color="#fff" stop-opacity=".06"/><stop offset=".64" stop-color="#000" stop-opacity=".05"/><stop offset="1" stop-color="#000" stop-opacity=".32"/></linearGradient></defs><path d="M24.5 11c-4.6.4-6.8 3.4-6.8 7s2.3 6.2 5.2 7M39.5 11c4.6.4 6.8 3.4 6.8 7s-2.3 6.2-5.2 7" stroke="currentColor" stroke-width="2.8" fill="none" stroke-linecap="round"/><path d="M24 8h16l-3.5 17c-.4 1.8-1.7 2.6-4.5 2.6s-4.1-.8-4.5-2.6z" fill="currentColor"/><path d="M24 8h16l-3.5 17c-.4 1.8-1.7 2.6-4.5 2.6s-4.1-.8-4.5-2.6z" fill="url(#tc-copa-g)"/><rect x="30" y="27" width="4" height="16" fill="currentColor"/><rect x="26.5" y="32" width="11" height="3" rx="1.5" fill="currentColor"/><path d="M23 43h18l3 8H20z" fill="currentColor"/><path d="M23 43h18l3 8H20z" fill="url(#tc-copa-g)"/>'],
    // A orelhuda, e agora orelhuda de verdade: as alças são arcos de traço 5…
    'cont' => ['#c0c0c0',
        '<defs><linearGradient id="tc-cont-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset=".38" stop-color="#fff" stop-opacity=".06"/><stop offset=".64" stop-color="#000" stop-opacity=".05"/><stop offset="1" stop-color="#000" stop-opacity=".32"/></linearGradient></defs><path d="M22 11c-9.5 0-13.5 5-13.5 11s4.8 10.6 12.5 11.6M42 11c9.5 0 13.5 5 13.5 11s-4.8 10.6-12.5 11.6" stroke="currentColor" stroke-width="5" fill="none" stroke-linecap="round"/><path d="M21 8h22v13c0 7.5-4.4 12.4-11 14.4-6.6-2-11-6.9-11-14.4z" fill="currentColor"/><path d="M21 8h22v13c0 7.5-4.4 12.4-11 14.4-6.6-2-11-6.9-11-14.4z" fill="url(#tc-cont-g)"/><rect x="28.5" y="35" width="7" height="6" fill="currentColor"/><path d="M21 41h22l3 5H18z" fill="currentColor"/><rect x="16" y="46" width="32" height="6" rx="2" fill="currentColor"/><rect x="16" y="46" width="32" height="6" rx="2" fill="url(#tc-cont-g)"/>'],
    // Bola PEQUENA num pe alto sobre base de dois degraus: silhueta de torre…
    'mundial' => ['#fbbf24',
        '<defs><linearGradient id="tc-mundial-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".4"/><stop offset=".45" stop-color="#fff" stop-opacity=".04"/><stop offset="1" stop-color="#000" stop-opacity=".3"/></linearGradient></defs><circle cx="32" cy="13" r="8.5" fill="currentColor"/><circle cx="32" cy="13" r="8.5" fill="url(#tc-mundial-g)"/><path d="M32 7.5l4 2.9-1.5 4.7h-5L28 10.4z" fill="#7c2d12" opacity=".7"/><path d="M28.5 21.5h7l-1.5 22h-4z" fill="currentColor"/><path d="M28.5 21.5h7l-1.5 22h-4z" fill="url(#tc-mundial-g)"/><rect x="15" y="43" width="34" height="6" rx="2" fill="currentColor"/><rect x="12" y="49" width="40" height="6" rx="2" fill="currentColor"/><rect x="12" y="49" width="40" height="6" rx="2" fill="url(#tc-mundial-g)"/>'],
    // O único objeto DEITADO da coleção, e essa é a metade do trabalho: numa…
    'chuteira' => ['#fcd34d',
        '<defs><linearGradient id="tc-chuteira-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset=".38" stop-color="#fff" stop-opacity=".06"/><stop offset=".64" stop-color="#000" stop-opacity=".05"/><stop offset="1" stop-color="#000" stop-opacity=".32"/></linearGradient></defs><path d="M11 42V27c0-3 1.8-5 4.5-5s4.5 2 4.5 5v1c4.5-1.5 9.5-1.6 15 0 7 2.2 12 5.6 15 10 1.4 2 .8 4-1.5 4z" fill="currentColor"/><path d="M11 42V27c0-3 1.8-5 4.5-5s4.5 2 4.5 5v1c4.5-1.5 9.5-1.6 15 0 7 2.2 12 5.6 15 10 1.4 2 .8 4-1.5 4z" fill="url(#tc-chuteira-g)"/><path d="M23 29.5l1.6 3.4M28.2 28.6l1.6 3.6M33.6 29.3l1.6 3.7" stroke="#78350f" stroke-width="1.9" stroke-linecap="round"/><path d="M11 42h37.5l-.8 4H11z" fill="currentColor"/><path d="M11 42h37.5l-.8 4H11z" fill="#000" opacity=".2"/><path d="M14 46h4.6l-.7 5h-3.2zM23 46h4.6l-.7 5h-3.2zM32 46h4.6l-.7 5h-3.2zM40.4 46h4.4l-.7 5h-3z" fill="currentColor"/>'],
    // Prêmio de um homem só, então a bola é o troféu inteiro: raio 14 num…
    'bola_ouro' => ['#facc15',
        '<defs><linearGradient id="tc-bola_ouro-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset=".38" stop-color="#fff" stop-opacity=".06"/><stop offset=".64" stop-color="#000" stop-opacity=".05"/><stop offset="1" stop-color="#000" stop-opacity=".32"/></linearGradient><radialGradient id="tc-bola_ouro-r" cx=".35" cy=".3" r=".8"><stop offset="0" stop-color="#fff" stop-opacity=".5"/><stop offset=".55" stop-color="#fff" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".34"/></radialGradient></defs><circle cx="32" cy="20" r="14" fill="currentColor"/><path d="M32 11l6 4.4-2.3 7.1h-7.4L26 15.4z" fill="#78350f"/><path d="M32 6v5M18.5 17l7-1.6M45.5 17l-7-1.6M24.5 32l2.2-4.5M39.5 32l-2.2-4.5" stroke="#78350f" stroke-width="1.7"/><circle cx="32" cy="20" r="14" fill="url(#tc-bola_ouro-r)"/><path d="M28.5 34h7l1.5 10h-10z" fill="currentColor"/><rect x="21" y="44" width="22" height="4" rx="1" fill="currentColor"/><rect x="18" y="48" width="28" height="5.5" rx="2" fill="currentColor"/><rect x="18" y="48" width="28" height="5.5" rx="2" fill="url(#tc-bola_ouro-g)"/>'],
    // Rei tem coroa, e coroa é a silhueta mais barata de reconhecer que existe…
    'rei_america' => ['#34d399',
        '<defs><linearGradient id="tc-rei_america-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset=".38" stop-color="#fff" stop-opacity=".06"/><stop offset=".64" stop-color="#000" stop-opacity=".05"/><stop offset="1" stop-color="#000" stop-opacity=".32"/></linearGradient></defs><circle cx="7" cy="15" r="2.8" fill="currentColor"/><circle cx="32" cy="10" r="3" fill="currentColor"/><circle cx="57" cy="15" r="2.8" fill="currentColor"/><path d="M11 41 7 17l11 9 14-14 14 14 11-9-4 24z" fill="currentColor"/><path d="M11 41 7 17l11 9 14-14 14 14 11-9-4 24z" fill="url(#tc-rei_america-g)"/><circle cx="20" cy="31" r="2.4" fill="#065f46"/><circle cx="32" cy="28" r="2.6" fill="#065f46"/><circle cx="44" cy="31" r="2.4" fill="#065f46"/><rect x="10" y="41" width="44" height="8" rx="2.5" fill="currentColor"/><rect x="10" y="41" width="44" height="8" rx="2.5" fill="url(#tc-rei_america-g)"/>'],
    // O problema da versão antiga era hierarquia invertida: a rede era um…
    'artilheiro' => ['#f97316',
        '<defs><linearGradient id="tc-artilheiro-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset=".38" stop-color="#fff" stop-opacity=".06"/><stop offset=".64" stop-color="#000" stop-opacity=".05"/><stop offset="1" stop-color="#000" stop-opacity=".32"/></linearGradient><radialGradient id="tc-artilheiro-r" cx=".35" cy=".3" r=".8"><stop offset="0" stop-color="#fff" stop-opacity=".5"/><stop offset=".55" stop-color="#fff" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".34"/></radialGradient></defs><path d="M18 16v30M27 16v30M36 16v30M45 16v30M13 23h38M13 31h38M13 39h38" stroke="currentColor" stroke-width=".9" opacity=".32"/><rect x="8" y="11" width="48" height="4.5" rx="1.5" fill="currentColor"/><rect x="8" y="11" width="4.5" height="35" rx="1.5" fill="currentColor"/><rect x="51.5" y="11" width="4.5" height="35" rx="1.5" fill="currentColor"/><circle cx="32" cy="33" r="10.5" fill="currentColor"/><path d="M32 26l4.6 3.4-1.8 5.5h-5.6L27.4 29.4z" fill="#7c2d12"/><path d="M32 22.5v3.5M22.6 30.2l4.8-.8M41.4 30.2l-4.8-.8M27.5 41.6l1.7-3.3M36.5 41.6l-1.7-3.3" stroke="#7c2d12" stroke-width="1.4"/><circle cx="32" cy="33" r="10.5" fill="url(#tc-artilheiro-r)"/><rect x="6" y="46" width="52" height="5" rx="2" fill="currentColor"/><rect x="6" y="46" width="52" height="5" rx="2" fill="url(#tc-artilheiro-g)"/>'],
    // O maior título do jogo, e o mais distinto: globo pequeno com meridiano e…
    'copa_mundo' => ['#f59e0b',
        '<defs><linearGradient id="tc-copa_mundo-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset=".38" stop-color="#fff" stop-opacity=".06"/><stop offset=".64" stop-color="#000" stop-opacity=".05"/><stop offset="1" stop-color="#000" stop-opacity=".32"/></linearGradient><radialGradient id="tc-copa_mundo-r" cx=".35" cy=".3" r=".8"><stop offset="0" stop-color="#fff" stop-opacity=".5"/><stop offset=".55" stop-color="#fff" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".34"/></radialGradient></defs><circle cx="32" cy="14" r="8" fill="currentColor"/><ellipse cx="32" cy="14" rx="3.7" ry="8" fill="none" stroke="#78350f" stroke-width="1.2" opacity=".5"/><path d="M24.2 12.4h15.6M25 17.6h14" stroke="#78350f" stroke-width="1.2" opacity=".5"/><circle cx="32" cy="14" r="8" fill="url(#tc-copa_mundo-r)"/><path d="M29.5 18c-5.5 4-9.5 11.5-11 23h7c1.4-10 4-16.5 7.5-19.5zM34.5 18c5.5 4 9.5 11.5 11 23h-7c-1.4-10-4-16.5-7.5-19.5z" fill="currentColor"/><path d="M29.5 18c-5.5 4-9.5 11.5-11 23h7c1.4-10 4-16.5 7.5-19.5zM34.5 18c5.5 4 9.5 11.5 11 23h-7c-1.4-10-4-16.5-7.5-19.5z" fill="url(#tc-copa_mundo-g)"/><rect x="17" y="41" width="30" height="5" rx="2" fill="currentColor"/><rect x="14" y="46" width="36" height="7" rx="3" fill="currentColor"/><rect x="14" y="46" width="36" height="7" rx="3" fill="url(#tc-copa_mundo-g)"/>'],
    // Segunda divisão continental: ÂNFORA. Corpo bojudo que fecha num gargalo…
    'cont2' => ['#cd7f32',
        '<defs><linearGradient id="tc-cont2-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset=".38" stop-color="#fff" stop-opacity=".06"/><stop offset=".64" stop-color="#000" stop-opacity=".05"/><stop offset="1" stop-color="#000" stop-opacity=".32"/></linearGradient></defs><path d="M26 13c-5 2-6.8 6.4-5 11.4M38 13c5 2 6.8 6.4 5 11.4" stroke="currentColor" stroke-width="3.2" fill="none" stroke-linecap="round"/><rect x="24.5" y="9" width="15" height="4.2" rx="1.6" fill="currentColor"/><path d="M27 13h10c5 4 8 10 8 16 0 8-6 13.5-13 13.5S19 37 19 29c0-6 3-12 8-16z" fill="currentColor"/><path d="M27 13h10c5 4 8 10 8 16 0 8-6 13.5-13 13.5S19 37 19 29c0-6 3-12 8-16z" fill="url(#tc-cont2-g)"/><rect x="29.5" y="42" width="5" height="4" fill="currentColor"/><rect x="23" y="46" width="18" height="6" rx="2" fill="currentColor"/><rect x="23" y="46" width="18" height="6" rx="2" fill="url(#tc-cont2-g)"/>'],
    // Terceira divisão continental: peça moderna de CINTURA — boca larga com…
    'cont3' => ['#4ade80',
        '<defs><linearGradient id="tc-cont3-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset=".38" stop-color="#fff" stop-opacity=".06"/><stop offset=".64" stop-color="#000" stop-opacity=".05"/><stop offset="1" stop-color="#000" stop-opacity=".32"/></linearGradient></defs><rect x="19" y="12" width="26" height="4.2" rx="1.6" fill="currentColor"/><path d="M21 16h22c-1 7.4-5 12-8 15 2.5 2.2 4.7 4.8 5.6 8H23.4c.9-3.2 3.1-5.8 5.6-8-3-3-7-7.6-8-15z" fill="currentColor"/><path d="M21 16h22c-1 7.4-5 12-8 15 2.5 2.2 4.7 4.8 5.6 8H23.4c.9-3.2 3.1-5.8 5.6-8-3-3-7-7.6-8-15z" fill="url(#tc-cont3-g)"/><rect x="24" y="41" width="16" height="4" fill="currentColor"/><rect x="20.5" y="45" width="23" height="6" rx="2" fill="currentColor"/><rect x="20.5" y="45" width="23" height="6" rx="2" fill="url(#tc-cont3-g)"/>'],
    // Conceito: jogo unico, entao prato raso e nada mais — boca de 52 unidades…
    'supernac' => ['#94a3b8',
        '<defs><linearGradient id="tc-supernac-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset=".38" stop-color="#fff" stop-opacity=".06"/><stop offset=".64" stop-color="#000" stop-opacity=".05"/><stop offset="1" stop-color="#000" stop-opacity=".32"/></linearGradient></defs><path d="M6 14h52c0 3.4-3.4 5.8-7 6.6C49.4 28 41.4 33 32 33S14.6 28 13 20.6C9.4 19.8 6 17.4 6 14z" fill="currentColor"/><path d="M6 14h52c0 3.4-3.4 5.8-7 6.6C49.4 28 41.4 33 32 33S14.6 28 13 20.6C9.4 19.8 6 17.4 6 14z" fill="url(#tc-supernac-g)"/><path d="M11 18h42" stroke="#000" stroke-width="1.4" opacity=".15"/><rect x="28" y="33" width="8" height="5" fill="currentColor"/><rect x="19" y="38" width="26" height="7" rx="2.5" fill="currentColor"/><rect x="19" y="38" width="26" height="7" rx="2.5" fill="url(#tc-supernac-g)"/>'],
    // Mesma familia do supernac de proposito — as duas supercopas sao decisao…
    'supercont' => ['#d4af37',
        '<defs><linearGradient id="tc-supercont-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset=".38" stop-color="#fff" stop-opacity=".06"/><stop offset=".64" stop-color="#000" stop-opacity=".05"/><stop offset="1" stop-color="#000" stop-opacity=".32"/></linearGradient></defs><path d="M13 20c-5 .3-7.5 2.4-7.5 4.6s2.2 4.4 6.5 4.8M51 20c5 .3 7.5 2.4 7.5 4.6s-2.2 4.4-6.5 4.8" stroke="currentColor" stroke-width="3.6" fill="none" stroke-linecap="round"/><path d="M13 16h38c0 3-2.6 5.2-6 6C43.6 28.6 38.4 33 32 33s-11.6-4.4-13-11c-3.4-.8-6-3-6-6z" fill="currentColor"/><path d="M13 16h38c0 3-2.6 5.2-6 6C43.6 28.6 38.4 33 32 33s-11.6-4.4-13-11c-3.4-.8-6-3-6-6z" fill="url(#tc-supercont-g)"/><path d="M17 23.5h30" stroke="#78350f" stroke-width="1.7" opacity=".4"/><rect x="28" y="33" width="8" height="5" fill="currentColor"/><rect x="20" y="38" width="24" height="7" rx="2.5" fill="currentColor"/><rect x="20" y="38" width="24" height="7" rx="2.5" fill="url(#tc-supercont-g)"/>'],
    // A versao antiga era desenhada em CONTORNO (traco 2.6, miolo vazado) e…
    'luva_ouro' => ['#e0e7ff',
        '<defs><linearGradient id="tc-luva_ouro-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset=".38" stop-color="#fff" stop-opacity=".06"/><stop offset=".64" stop-color="#000" stop-opacity=".05"/><stop offset="1" stop-color="#000" stop-opacity=".32"/></linearGradient></defs><rect x="17.5" y="17" width="5.4" height="18" rx="2.7" fill="currentColor"/><rect x="25" y="12.5" width="5.4" height="22.5" rx="2.7" fill="currentColor"/><rect x="32.5" y="14" width="5.4" height="21" rx="2.7" fill="currentColor"/><rect x="40" y="18.5" width="5.2" height="16.5" rx="2.6" fill="currentColor"/><path d="M20 30l-8.6 4.8c-3 1.7-3.9 5-2 7.8 1.9 2.8 5.4 3.2 8.4 1.5l6.4-3.6z" fill="currentColor"/><path d="M15.5 30h32v8.5c0 5.2-4.2 8.5-9.5 8.5H25c-5.3 0-9.5-3.3-9.5-8.5z" fill="currentColor"/><path d="M15.5 30h32v8.5c0 5.2-4.2 8.5-9.5 8.5H25c-5.3 0-9.5-3.3-9.5-8.5z" fill="url(#tc-luva_ouro-g)"/><rect x="22" y="33" width="19" height="9" rx="4.5" fill="#fff" opacity=".2"/><rect x="15" y="45" width="34" height="8" rx="3" fill="currentColor"/><rect x="15" y="45" width="34" height="8" rx="3" fill="url(#tc-luva_ouro-g)"/>'],
    // Torneio de selecoes: as alcas nao ficam do LADO da boca como em toda…
    'selecao_cont' => ['#38bdf8',
        '<defs><linearGradient id="tc-selecao_cont-g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset=".38" stop-color="#fff" stop-opacity=".06"/><stop offset=".64" stop-color="#000" stop-opacity=".05"/><stop offset="1" stop-color="#000" stop-opacity=".32"/></linearGradient></defs><path d="M23 17C17 16 12.5 12 11.5 6M41 17c6-1 10.5-5 11.5-11" stroke="currentColor" stroke-width="4" fill="none" stroke-linecap="round"/><circle cx="11" cy="5.5" r="2.8" fill="currentColor"/><circle cx="53" cy="5.5" r="2.8" fill="currentColor"/><path d="M22 14h20v10c0 7.2-4 12-10 14-6-2-10-6.8-10-14z" fill="currentColor"/><path d="M22 14h20v10c0 7.2-4 12-10 14-6-2-10-6.8-10-14z" fill="url(#tc-selecao_cont-g)"/><rect x="29" y="38" width="6" height="5" fill="currentColor"/><path d="M21 43h22l3 5H18z" fill="currentColor"/><rect x="17.5" y="48" width="29" height="5.5" rx="2" fill="currentColor"/><rect x="17.5" y="48" width="29" height="5.5" rx="2" fill="url(#tc-selecao_cont-g)"/>'],
];

/**
 * O catálogo de eventos.
 *
 * Cada um é: título, uma frase de contexto, e cartas com efeito e
 * probabilidade VISÍVEIS. A probabilidade na tela é o coração do jogo — sem
 * ela a escolha vira chute, e com ela vira aposta.
 *
 * O QUE UMA CARTA PODE FAZER COM VOCÊ. Nenhuma destas chaves inventa sistema
 * novo: cada uma cai numa alavanca que o motor já tinha e que nenhum evento
 * acionava. Quem as consome é o `aplicarEfeito()` do copero.php — chave que
 * não estiver lá é promessa que a tela faz e o jogo não cumpre.
 *
 *   ovr      ± overall na hora
 *   jogos    ± jogos da próxima temporada, uma vez só
 *   lesao    N temporadas PERDIDAS de verdade: liga a mesma flag `t.lesao`
 *            do sorteio anual, come quase todos os jogos do ano, cobra o
 *            overall em `evoluir` e conta pra conquista do osso duro
 *   saida    você perde o clube: cai numa tela sem a carta de ficar, e
 *            `motivo` (crise, ruptura, pedido, dispensa) escolhe o texto
 *   mercado  abre uma janela de transferências extra, esta com opção de ficar
 *   queima   o clube de agora não te contrata mais (mesmo slot da traição)
 *   pico     ± anos de auge — dois anos a mais valem de 4 a 8 de overall
 *   dur      ± durabilidade: decide se o fim é ladeira ou tobogã
 *   semSel   N anos fora da seleção: sem convocação e sem título de seleção
 *
 * NÃO EXISTE `quando` AQUI, e é de propósito. Closure não serializa, o
 * `json_encode` do copero.php jogava a chave fora, e o que sobrava era uma
 * segunda lista de condições que ninguém executava — e que já tinha começado
 * a divergir da que vale. A condição de cada evento mora num lugar só: o
 * mapa `cabe` de eventoDaVez(), em games/games/copero.php. Evento novo aqui
 * pede uma linha lá, senão ele passa a cair em qualquer idade.
 *
 * `peso` é o freio do catálogo: o sorteio de eventoDaVez é ponderado, e sem
 * ele a crise financeira cairia tanto quanto o plano alimentar. Quanto mais
 * dói, menos aparece. A exceção são os de janela rara (cirurgia, estreia,
 * braçadeira), que levam peso alto porque a condição deles quase nunca abre.
 * Evento sem `peso` vale 10.
 */
function coperoEventos(): array
{
    return [
        // ── O COMEÇO ────────────────────────────────────────────────
        [
            'id' => 'estreia', 'peso' => 16,
            'titulo' => 'A estreia no profissional',
            'texto'  => 'O treinador vai te escalar entre os titulares pela primeira vez, contra um adversário direto.',
            'cartas' => [
                ['rotulo' => 'Jogar solto', 'efeitos' => [
                    ['chance' => 55, 'ovr' => +4, 'jogos' => +6, 'texto' => 'Estreia dos sonhos'],
                    ['chance' => 45, 'ovr' => -2, 'jogos' => -8, 'texto' => 'Trava em campo'],
                ]],
                ['rotulo' => 'Entrar no segundo tempo', 'efeitos' => [
                    ['chance' => 100, 'ovr' => +1, 'texto' => 'Estreia discreta'],
                ]],
            ],
        ],

        // ── O CORPO: o que você faz com ele cobra ───────────────────
        [
            'id' => 'concentracao', 'peso' => 7,
            'titulo' => 'Concentração extra',
            'texto'  => 'Uma preparação especial pode melhorar seu jogo, mas o esforço extra pode cobrar seu preço.',
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
            // A CARTA QUE MENTIA. O efeito se chamava 'Lesão' e só mexia em
            // overall — a flag de lesão de verdade passava ao lado. Agora a
            // palavra custa o que ela diz que custa: a temporada.
            'id' => 'treino_dobro', 'peso' => 8,
            'titulo' => 'Treino em dobro',
            'texto'  => 'Dois treinos por dia para melhorar seu desempenho. O corpo tem um limite e ele não avisa antes.',
            'cartas' => [
                ['rotulo' => 'Treinar dobrado', 'efeitos' => [
                    ['chance' => 62, 'ovr' => +3, 'texto' => 'Evolução física'],
                    ['chance' => 26, 'ovr' => -1, 'texto' => 'Desgaste sem ganho'],
                    ['chance' => 12, 'lesao' => 1, 'ovr' => -2, 'texto' => 'Rompe e perde a temporada'],
                ]],
                ['rotulo' => 'Reduzir a carga', 'efeitos' => [
                    ['chance' => 100, 'jogos' => -8, 'texto' => 'Menos minutos'],
                ]],
            ],
        ],
        [
            'id' => 'noite_da_cidade', 'peso' => 9,
            'titulo' => 'A noite antes do jogo',
            'texto'  => 'Os companheiros chamam para sair na véspera. Ninguém do clube precisa ficar sabendo.',
            'cartas' => [
                ['rotulo' => 'Ir com o grupo', 'efeitos' => [
                    ['chance' => 40, 'ovr' => +2, 'texto' => 'O grupo se solta em campo'],
                    ['chance' => 35, 'jogos' => -8, 'texto' => 'A foto vaza'],
                    ['chance' => 25, 'ovr' => -3, 'dur' => -0.15, 'texto' => 'Vira rotina'],
                ]],
                ['rotulo' => 'Dormir cedo', 'efeitos' => [
                    ['chance' => 100, 'ovr' => 0, 'texto' => 'Nada acontece'],
                ]],
            ],
        ],
        [
            'id' => 'dieta', 'peso' => 7,
            'titulo' => 'Plano alimentar',
            'texto'  => 'Um nutricionista propõe reconstruir sua dieta do zero. Muda o corpo, e nem sempre pra melhor.',
            'cartas' => [
                ['rotulo' => 'Seguir o plano', 'efeitos' => [
                    ['chance' => 58, 'ovr' => +2, 'dur' => +0.15, 'texto' => 'Corpo responde melhor'],
                    ['chance' => 42, 'ovr' => -2, 'texto' => 'Perde força no ano'],
                ]],
                ['rotulo' => 'Manter a sua', 'efeitos' => [
                    ['chance' => 100, 'ovr' => 0, 'texto' => 'Sem mudanças'],
                ]],
            ],
        ],
        [
            'id' => 'preparador', 'peso' => 8,
            'titulo' => 'Preparador físico particular',
            'texto'  => 'Um preparador que trabalhou com veteranos de elite oferece um programa por fora do clube.',
            'cartas' => [
                ['rotulo' => 'Contratar', 'efeitos' => [
                    ['chance' => 62, 'pico' => +2, 'dur' => +0.2, 'texto' => 'Auge esticado'],
                    ['chance' => 38, 'ovr' => -2, 'jogos' => -5, 'texto' => 'Sobrecarga sem retorno'],
                ]],
                ['rotulo' => 'Só o trabalho do clube', 'efeitos' => [
                    ['chance' => 100, 'ovr' => 0, 'texto' => 'Rotina normal'],
                ]],
            ],
        ],

        // ── A LESÃO: o ano que some ─────────────────────────────────
        [
            'id' => 'sacrificio', 'peso' => 8,
            'titulo' => 'Jogar no sacrifício',
            'texto'  => 'Você sente a coxa na semana da decisão. O departamento médico pede duas semanas. O treinador pede que você entre.',
            'cartas' => [
                ['rotulo' => 'Entrar em campo', 'efeitos' => [
                    ['chance' => 46, 'ovr' => +4, 'texto' => 'Decide o jogo'],
                    ['chance' => 24, 'ovr' => +1, 'jogos' => -6, 'texto' => 'Sai no primeiro tempo'],
                    ['chance' => 30, 'lesao' => 1, 'ovr' => -2, 'texto' => 'Rompe e perde a temporada'],
                ]],
                ['rotulo' => 'Poupar', 'efeitos' => [
                    ['chance' => 100, 'jogos' => -4, 'texto' => 'Assiste de fora'],
                ]],
            ],
        ],
        [
            // A ÚNICA porta de saída sem risco aqui é 'Adiar a decisão' — e
            // ela existe porque as outras duas cartas custam a temporada nos
            // dois lados, o que faria da cirurgia a única carta do jogo sem
            // escolha segura. O joelho só é decidido UMA VEZ por carreira:
            // `S.jaOperou` fecha a janela, senão operar gera a lesão que
            // reabre a própria cirurgia no ano seguinte, em círculo.
            'id' => 'cirurgia', 'peso' => 22,
            'titulo' => 'A cirurgia',
            'texto'  => 'O joelho não respondeu ao tratamento. O cirurgião propõe uma operação que resolve de vez, com um ano fora.',
            'cartas' => [
                ['rotulo' => 'Operar', 'efeitos' => [
                    ['chance' => 70, 'lesao' => 1, 'dur' => +0.2, 'texto' => 'Um ano fora, joelho refeito'],
                    ['chance' => 30, 'lesao' => 1, 'ovr' => -5, 'dur' => -0.1, 'texto' => 'A cirurgia não pega'],
                ]],
                ['rotulo' => 'Tratamento conservador', 'efeitos' => [
                    ['chance' => 55, 'ovr' => -1, 'texto' => 'Joga o ano com dor'],
                    ['chance' => 45, 'lesao' => 1, 'ovr' => -4, 'dur' => -0.15, 'texto' => 'O joelho trava de novo'],
                ]],
                ['rotulo' => 'Adiar a decisão', 'efeitos' => [
                    ['chance' => 100, 'ovr' => -2, 'texto' => 'Empurra o problema pra frente'],
                ]],
            ],
        ],
        [
            'id' => 'dor_cronica', 'peso' => 10,
            'titulo' => 'A dor que não passa',
            'texto'  => 'O tornozelo dói em todo treino. O médico diz que dá para administrar, mas não para curar.',
            'cartas' => [
                ['rotulo' => 'Infiltrar e jogar', 'efeitos' => [
                    ['chance' => 58, 'ovr' => +1, 'jogos' => +6, 'texto' => 'Aguenta o ano inteiro'],
                    ['chance' => 42, 'lesao' => 1, 'ovr' => -3, 'dur' => -0.2, 'texto' => 'O tornozelo cede'],
                ]],
                ['rotulo' => 'Reduzir o calendário', 'efeitos' => [
                    ['chance' => 100, 'jogos' => -14, 'dur' => +0.15, 'texto' => 'Menos jogos, corpo inteiro'],
                ]],
            ],
        ],

        // ── O CLUBE: a porta que se fecha ───────────────────────────
        [
            // Os textos daqui foram reescritos pra não prometer o que o
            // encanamento não faz: a saída é cobrada DEPOIS da temporada
            // jogada, então nada aqui pode dizer 'imediata' nem 'agora'.
            'id' => 'crise_clube', 'peso' => 7,
            'titulo' => 'Crise financeira no clube',
            'texto'  => 'O clube está há meses sem pagar salários e o elenco discute ir à Justiça.',
            'cartas' => [
                ['rotulo' => 'Rescindir agora', 'efeitos' => [
                    ['chance' => 100, 'saida' => 1, 'motivo' => 'crise', 'texto' => 'Você vai ter que achar clube'],
                ]],
                ['rotulo' => 'Esperar o clube resolver', 'efeitos' => [
                    ['chance' => 45, 'jogos' => +6, 'texto' => 'Salários em dia'],
                    ['chance' => 35, 'ovr' => -3, 'jogos' => -12, 'texto' => 'O elenco desmonta'],
                    ['chance' => 20, 'saida' => 1, 'ovr' => -2, 'motivo' => 'crise', 'texto' => 'O clube te libera'],
                ]],
            ],
        ],
        [
            'id' => 'novo_tecnico', 'peso' => 9,
            'titulo' => 'Troca de treinador',
            'texto'  => 'Chega um treinador novo, com ideias próprias e um elenco inteiro para reavaliar.',
            'cartas' => [
                ['rotulo' => 'Se adaptar ao esquema', 'efeitos' => [
                    ['chance' => 55, 'ovr' => +2, 'jogos' => +5, 'texto' => 'Vira peça do time'],
                    ['chance' => 45, 'jogos' => -10, 'texto' => 'Não entra nos planos'],
                ]],
                ['rotulo' => 'Pedir para sair', 'efeitos' => [
                    ['chance' => 100, 'saida' => 1, 'motivo' => 'pedido', 'texto' => 'Rescisão amigável'],
                ]],
            ],
        ],
        [
            'id' => 'polemica', 'peso' => 8,
            'titulo' => 'Declaração polêmica',
            'texto'  => 'Você critica publicamente o treinador depois de uma derrota dura, e o vestiário fica tenso.',
            'cartas' => [
                ['rotulo' => 'Pedir desculpas', 'efeitos' => [
                    ['chance' => 100, 'jogos' => -10, 'texto' => 'Perde espaço no time'],
                ]],
                ['rotulo' => 'Manter o que disse', 'efeitos' => [
                    ['chance' => 40, 'ovr' => +2, 'texto' => 'O grupo te respeita'],
                    ['chance' => 35, 'jogos' => -14, 'queima' => 1, 'texto' => 'Isolado no elenco'],
                    ['chance' => 25, 'saida' => 1, 'motivo' => 'ruptura', 'texto' => 'O clube te dispensa'],
                ]],
            ],
        ],
        [
            'id' => 'capitao', 'peso' => 14,
            'titulo' => 'A braçadeira',
            'texto'  => 'O treinador te oferece a capitania. Vem com responsabilidade e com holofote.',
            'cartas' => [
                ['rotulo' => 'Aceitar', 'efeitos' => [
                    ['chance' => 68, 'ovr' => +3, 'texto' => 'Você cresce com ela'],
                    ['chance' => 32, 'ovr' => -2, 'texto' => 'O peso atrapalha'],
                ]],
                ['rotulo' => 'Recusar', 'efeitos' => [
                    ['chance' => 100, 'ovr' => 0, 'texto' => 'Sem mudanças'],
                ]],
            ],
        ],

        // ── O MERCADO: a porta que se abre ──────────────────────────
        [
            // `queima` aqui é silenciosa enquanto você ficar: ofertas() já
            // exclui o clube atual da lista, então a marca só aparece no dia
            // em que você sair. Por isso o texto fala dos minutos, que é o
            // que a pessoa sente no mesmo ano.
            'id' => 'empresario', 'peso' => 8,
            'titulo' => 'Troca de empresário',
            'texto'  => 'Um agente grande te procura. Ele promete abrir portas que o seu atual não abre.',
            'cartas' => [
                ['rotulo' => 'Trocar de agente', 'efeitos' => [
                    ['chance' => 55, 'mercado' => 1, 'texto' => 'Propostas na mesa'],
                    ['chance' => 45, 'queima' => 1, 'jogos' => -6, 'texto' => 'O clube corta seus minutos'],
                ]],
                ['rotulo' => 'Ficar com quem está', 'efeitos' => [
                    ['chance' => 100, 'ovr' => 0, 'texto' => 'Nada muda'],
                ]],
            ],
        ],
        [
            'id' => 'assedio_gigante', 'peso' => 10,
            'titulo' => 'Sondagem de um gigante',
            'texto'  => 'Um clube muito acima do seu procura seu empresário. A diretoria avisa que não negocia.',
            'cartas' => [
                ['rotulo' => 'Forçar a saída', 'efeitos' => [
                    ['chance' => 50, 'mercado' => 1, 'texto' => 'A porta abre'],
                    // Sair brigado é SAÍDA: com `mercado` a tela ainda
                    // oferecia a carta de ficar, e o texto virava mentira.
                    ['chance' => 30, 'saida' => 1, 'motivo' => 'ruptura', 'jogos' => -12, 'queima' => 1, 'texto' => 'Você sai brigado'],
                    ['chance' => 20, 'jogos' => -16, 'ovr' => -3, 'texto' => 'Fica e paga o preço'],
                ]],
                ['rotulo' => 'Recusar e ficar', 'efeitos' => [
                    ['chance' => 100, 'ovr' => +1, 'texto' => 'O clube reconhece'],
                ]],
            ],
        ],

        // ── A SELEÇÃO e o nome sujo ─────────────────────────────────
        [
            'id' => 'selecao_briga', 'peso' => 12,
            'titulo' => 'Corte da seleção',
            'texto'  => 'O técnico da seleção te deixou de fora da lista. A imprensa te espera na saída do treino.',
            'cartas' => [
                ['rotulo' => 'Responder na imprensa', 'efeitos' => [
                    ['chance' => 42, 'ovr' => +1, 'texto' => 'A pressão funciona'],
                    ['chance' => 58, 'semSel' => 3, 'texto' => 'Três anos fora da seleção'],
                ]],
                ['rotulo' => 'Engolir', 'efeitos' => [
                    ['chance' => 100, 'semSel' => 1, 'texto' => 'Um ano fora da seleção'],
                ]],
            ],
        ],
        [
            'id' => 'investigacao', 'peso' => 6,
            'titulo' => 'Convite suspeito',
            'texto'  => 'Um intermediário oferece dinheiro para você segurar o resultado de um jogo.',
            'cartas' => [
                ['rotulo' => 'Aceitar', 'efeitos' => [
                    ['chance' => 55, 'jogos' => +4, 'texto' => 'Ninguém desconfia'],
                    // 'afastado' prometia perder jogos e não perdia nenhum:
                    // o processo só se materializa na saída do ano seguinte.
                    ['chance' => 45, 'saida' => 1, 'semSel' => 5, 'ovr' => -4, 'motivo' => 'ruptura', 'texto' => 'Investigado e denunciado'],
                ]],
                ['rotulo' => 'Denunciar', 'efeitos' => [
                    ['chance' => 70, 'ovr' => +2, 'texto' => 'O vestiário te apoia'],
                    ['chance' => 30, 'jogos' => -8, 'ovr' => -1, 'texto' => 'Você vira o problema'],
                ]],
            ],
        ],
        [
            'id' => 'fiscal', 'peso' => 10,
            'titulo' => 'Problemas fiscais',
            'texto'  => 'Uma investigação fiscal coloca em dúvida sua permanência no país.',
            'cartas' => [
                ['rotulo' => 'Encarar o processo', 'efeitos' => [
                    ['chance' => 55, 'ovr' => -3, 'texto' => 'A distração pesa'],
                    ['chance' => 45, 'ovr' => -3, 'saida' => 1, 'motivo' => 'ruptura', 'texto' => 'O clube rescinde'],
                ]],
                ['rotulo' => 'Se antecipar e negociar', 'efeitos' => [
                    ['chance' => 60, 'ovr' => 0, 'texto' => 'Resolvido em silêncio'],
                    ['chance' => 40, 'ovr' => -5, 'texto' => 'O caso vaza'],
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
/**
 * As conquistas da carreira: `[ícone, nome, descrição, nível, teste]`.
 *
 * Os quatro níveis existem pra dar régua: `facil` sai quase toda carreira que
 * vai até o fim, `media` pede uma carreira boa, `dificil` pede uma carreira
 * excepcional, e `impossivel` é pra ser perseguido por muitas partidas — não
 * pra cair no colo. Uma lista onde tudo é alcançável não vale nada, e uma
 * onde nada é alcançável também não.
 *
 * O teste recebe os totais RECALCULADOS no servidor a partir das temporadas,
 * nunca o resumo que o cliente desenhou. `$c['t']` é a contagem por troféu.
 */
/**
 * O que cada nível de conquista paga, e em quê.
 *
 * Paga UMA VEZ POR CONQUISTA, nunca por carreira: sem isso bastaria repetir
 * carreiras curtas e fáceis pra imprimir moeda. Os impossíveis pagam em FBA
 * Points porque são a única moeda que não se ganha jogando qualquer coisa.
 */
const COPERO_PREMIO = [
    'facil'      => ['moedas' => 50],
    'media'      => ['moedas' => 100],
    'dificil'    => ['moedas' => 150],
    'impossivel' => ['fba_points' => 50],
];

function coperoConquistas(): array
{
    $t = fn(array $c, string $id) => (int)($c['t'][$id] ?? 0);

    return [
        // ── Tranquilas: quem termina uma carreira leva a maioria ──────
        'estreante'   => ['🌱', 'Primeiro contrato', 'Termine a carreira com pelo menos 100 jogos.',
                          'facil', fn($c) => $c['jogos'] >= 100],
        'centenario'  => ['💯', 'Centenário',        'Marque 100 gols na carreira.',
                          'facil', fn($c) => $c['gols'] >= 100],
        'garcom'      => ['🎩', 'Garçom',            'Dê 100 assistências na carreira.',
                          'facil', fn($c) => $c['ast'] >= 100],
        'primeiro_tit'=> ['🏆', 'O primeiro de muitos', 'Ganhe seu primeiro título.',
                          'facil', fn($c) => $c['coletivos'] >= 1],
        // A REGRA ERA 'picoOvr >= 75' E ISSO NÃO É SER CONVOCADO. O corte real
        // é a força da seleção menos a folga: 83 no Brasil, 87 na Argentina.
        // Um brasileiro que parava em 78 levava "Vestiu a amarelinha" sem ter
        // vestido nada, e um argentino de 85 ficava sem — mesmo tendo passado
        // do 75. Agora usa a MESMA conta de convocado() no jogo.
        'convocado'   => ['👕', 'Vestiu a amarelinha', 'Seja convocado para a seleção.',
                          'facil', fn($c) => $c['picoOvr'] >=
                                             ((COPERO_SELECOES[$c['pais']] ?? [999])[0])
                                             - COPERO_SELECAO_FOLGA],
        'idolo'       => ['🏠', 'Ídolo da casa',     'Faça sete temporadas seguidas no mesmo clube.',
                          'facil', fn($c) => $c['maiorSequencia'] >= 7],

        // ── Medianas: pedem uma carreira boa ──────────────────────────
        'elite'       => ['⭐', 'Nome da elite',     'Chegue a 90 de overall.',
                          'media', fn($c) => $c['picoOvr'] >= 90],
        'artilheiro'  => ['⚽', 'Artilheiro eterno', 'Marque 250 gols na carreira.',
                          'media', fn($c) => $c['gols'] >= 250],
        'milionario'  => ['💶', 'Cifra de craque',   'Valer 100 milhões de euros.',
                          'media', fn($c) => $c['picoValor'] >= 100000000],
        'lenda_clube' => ['♾️', 'Lenda do clube',    'Faça 450 jogos por um mesmo clube.',
                          'media', fn($c) => $c['maiorNoClube'] >= 450],
        'de_baixo'    => ['📈', 'De baixo',          'Comece na 2ª divisão ou abaixo e chegue a um clube de 90+.',
                          'media', fn($c) => $c['comecouAbaixo'] && $c['maiorForcaClube'] >= 90],
        'europeu'     => ['🇪🇺', 'Sonho europeu',    'Seja campeão de uma das cinco grandes ligas da Europa.',
                          'media', fn($c) => $c['grandesEuropeias'] >= 1],
        'orelhuda'    => ['🥇', 'Orelhuda',          'Ganhe um torneio continental de clubes.',
                          'media', fn($c) => $t($c,'cont') >= 1],
        'resiliente'  => ['🩹', 'Osso duro',         'Sofra três lesões e ainda assim passe de 88 de overall.',
                          'media', fn($c) => $c['lesoes'] >= 3 && $c['picoOvr'] >= 88],
        'passaporte'  => ['🌍', 'Passaporte carimbado', 'Jogue em ligas de quatro países diferentes.',
                          'media', fn($c) => $c['paises'] >= 4],

        // ── Difíceis: pedem uma carreira excepcional ──────────────────
        'teto'        => ['🟣', 'Fora da curva',     'Chegue a 96 de overall.',
                          'dificil', fn($c) => $c['picoOvr'] >= 96],
        'heroi'       => ['🏅', 'Herói nacional',    'Ganhe a Copa do Mundo.',
                          'dificil', fn($c) => $t($c,'copa_mundo') >= 1],
        'mundo_seu'   => ['🌐', 'O mundo é seu',     'Ganhe a Copa do Mundo e o Mundial de Clubes.',
                          'dificil', fn($c) => $t($c,'copa_mundo') >= 1 && $t($c,'mundial') >= 1],
        'triplice'    => ['👑', 'A tríplice coroa',  'Ganhe liga, copa e torneio continental na mesma temporada.',
                          'dificil', fn($c) => !empty($c['tripla'])],
        // 'cont' é QUALQUER continental de clubes, então um argentino que
        // ganhasse a Champions com o Real levava um "Rei da América" que fala
        // em Libertadores. Agora conta só o continental levantado por um
        // clube da América do Sul, que é o que o texto sempre prometeu.
        'rei_america' => ['🌎', 'Rei da América',    'Ganhe a Copa América e a Libertadores na mesma carreira.',
                          'dificil', fn($c) => $t($c,'selecao_cont') >= 1 && !empty($c['contSAM'])
                                               && in_array($c['pais'], ['BRA','ARG','URU','CHI','COL'], true)],
        'menino_ouro' => ['✨', 'Menino de ouro',    'Chegue a 88 de overall antes dos 24 anos.',
                          'dificil', fn($c) => $c['picoOvr'] >= 88 && $c['idadePico'] > 0 && $c['idadePico'] <= 23],
        'matagigante' => ['🗡️', 'Matagigantes',      'Ganhe um torneio continental com um clube de força abaixo de 78.',
                          'dificil', fn($c) => $c['menorCampeaoCont'] < 78],
        'baldosero'   => ['🧳', 'Baldosero',         'Jogue por sete clubes diferentes numa mesma carreira.',
                          'dificil', fn($c) => $c['clubes'] >= 7],
        'um_clube_so' => ['🔒', 'Um clube só',       'Faça a carreira inteira em no máximo dois clubes.',
                          'dificil', fn($c) => $c['clubes'] <= 2 && $c['temporadas'] >= 12],
        'terror'      => ['👟', 'Terror das redes',  'Ganhe duas Chuteiras de Ouro.',
                          'dificil', fn($c) => $t($c,'chuteira') >= 2],
        'nomade'      => ['🛫', 'Nômade',            'Jogue em clubes de três continentes diferentes.',
                          'dificil', fn($c) => $c['continentes'] >= 3],
        'periferia'   => ['🧭', 'Da periferia',      'Ganhe uma Bola de Ouro sendo de fora da Europa e da América do Sul.',
                          'dificil', fn($c) => $t($c,'bola_ouro') >= 1
                                               && !in_array($c['pais'],
                                                  ['ENG','ESP','ITA','GER','FRA','POR','NED','BEL','CRO','TUR',
                                                   'RUS','SCO','GRE','BRA','ARG','URU','CHI','COL'], true)],
        'ringless'    => ['🕳️', 'Ringless',          'Termine uma carreira de 15 temporadas sem nenhum título.',
                          'dificil', fn($c) => $c['coletivos'] === 0 && $c['temporadas'] >= 15],
        'poliglota'   => ['🗺️', 'Poliglota',         'Seja campeão nacional em três países diferentes.',
                          'dificil', fn($c) => $c['paisesCampeao'] >= 3],
        'traidor'     => ['⚡', 'Do outro lado',     'Troque de clube pelo maior rival dele.',
                          'media', fn($c) => ($c['trocasRival'] ?? 0) >= 1],
        'mercenario'  => ['🎭', 'Mercenário',        'Troque pelo rival duas vezes na mesma carreira.',
                          'dificil', fn($c) => ($c['trocasRival'] ?? 0) >= 2],
        'muralha'     => ['🧤', 'Muralha',           'Como goleiro, termine com 200 jogos sem sofrer gol.',
                          'dificil', fn($c) => $c['posicao'] === 'GOL' && $c['cleanSheets'] >= 200],

        // ── Impossíveis: pra perseguir por muitas carreiras ───────────
        'mr_champions'=> ['🏛️', 'Mr. Champions',     'Ganhe seis torneios continentais de clubes.',
                          'dificil', fn($c) => $t($c,'cont') >= 6],
        // AS CINCO, e não três. Com três o nome mentia: dono da Europa é quem
        // levantou Premier, LaLiga, Serie A, Bundesliga E Ligue 1 — cinco
        // mudanças de país numa carreira só, que é exatamente o tipo de coisa
        // que justifica a faixa dos impossíveis.
        'dono_europa' => ['🏰', 'Dono da Europa',    'Seja campeão das cinco grandes ligas europeias: '
                                                   . 'Premier, LaLiga, Serie A, Bundesliga e Ligue 1.',
                          'impossivel', fn($c) => $c['grandesEuropeias'] >= 5],
        'lenda_maxima'=> ['💫', 'Lenda absoluta',    'Passe a carreira inteira num só clube e ganhe liga, copa, '
                                                   . 'continental e Mundial de Clubes.',
                          'impossivel', fn($c) => $c['clubes'] === 1 && $c['temporadas'] >= 15
                                                  && $t($c,'liga') >= 1 && $t($c,'copa') >= 1
                                                  && $t($c,'cont') >= 1 && $t($c,'mundial') >= 1],
        'colecionador'=> ['🗄️', 'O mais vencedor da história', 'Ganhe 35 títulos coletivos.',
                          'dificil', fn($c) => $c['coletivos'] >= 35],
        'so_o_pele'   => ['👑👑', 'Só o Pelé',         'Faça 1.000 gols e ganhe três Copas do Mundo.',
                          'impossivel', fn($c) => $t($c,'copa_mundo') >= 3 && $c['gols'] >= 1000],
        'mil_gols'    => ['🎯', 'O milésimo',        'Marque 1.000 gols na carreira.',
                          'media', fn($c) => $c['gols'] >= 1000],
        // Aqui entra TUDO o que se levanta: taça de clube, de seleção e
        // prêmio individual. É o contrário do 'colecionador', que conta só
        // o que o time ganhou — este mede a estante inteira.
        'messi'       => ['🎖️', 'O mais condecorado', 'Ganhe 47 troféus numa carreira, somando títulos '
                                                    . 'coletivos e prêmios individuais.',
                          'impossivel', fn($c) => ($c['trofeus'] ?? $c['coletivos']) >= 47],
        'maestro'     => ['🎼', 'Maestro',           'Dê 400 assistências na carreira.',
                          'dificil', fn($c) => $c['ast'] >= 400],
        'goat'        => ['🐐', 'GOAT',              'Copa do Mundo, dois continentais de seleção, três Bolas de Ouro '
                                                   . 'e três torneios continentais de clubes.',
                          'impossivel', fn($c) => $t($c,'copa_mundo') >= 1 && $t($c,'selecao_cont') >= 2
                                                  && $t($c,'bola_ouro') >= 3 && $t($c,'cont') >= 3],
        'de_baixo_max'=> ['⛰️', 'Do fundo ao topo',  'Comece na TERCEIRA divisão e seja campeão da primeira '
                                                   . 'com esse mesmo clube.',
                          'impossivel', fn($c) => !empty($c['subiuComOMesmo'])],
        'yashin'      => ['🥅', 'O Yashin',          'Ganhe uma Bola de Ouro sendo goleiro. '
                                                   . 'Aconteceu uma vez na história do futebol.',
                          'impossivel', fn($c) => $c['posicao'] === 'GOL' && $t($c,'bola_ouro') >= 1],
        'seis_conts'  => ['🌏', 'O mundo inteiro',   'Jogue por clubes dos cinco continentes: todos os que o jogo tem.',
                          'impossivel', fn($c) => $c['continentes'] >= 5],
        'completar'   => ['✅', 'Completar o futebol', 'Ganhe liga, copa, continental, Mundial de Clubes, '
                                                     . 'Copa do Mundo e um continental de seleções.',
                          'impossivel', fn($c) => $t($c,'liga') >= 1 && $t($c,'copa') >= 1 && $t($c,'cont') >= 1
                                                  && $t($c,'mundial') >= 1 && $t($c,'copa_mundo') >= 1
                                                  && $t($c,'selecao_cont') >= 1],
    ];
}
