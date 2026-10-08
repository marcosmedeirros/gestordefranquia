<?php
/**
 * ── OS TIPOS DE CARTA ────────────────────────────────────────────────
 *
 * Pedido do Marcos (07/10/2026): "o design das cartas padrão deixa sempre
 * igual, aí vamos colocar icons, heróis, igual o fifa, e cada um vai ter o
 * seu design, além das coleções".
 *
 * Um tipo diz três coisas ao mesmo tempo, e é por isso que ele mora aqui em
 * vez de no CSS: como a carta é desenhada, como ela pontua na química, e
 * quão rara ela é. Mudar um tipo muda as três de uma vez, que é o que evita
 * a carta parecer especial e jogar como comum.
 *
 * ── ÍCONE E HERÓI NÃO SÃO O MESMO BICHO ──────────────────────────────
 *
 * No EA FC os dois têm química cheia na posição certa, mas ajudam o time de
 * formas diferentes, e isso é regra do jogo, não enfeite:
 *
 *   · ÍCONE é global: conta DOIS pro limiar da nação dele e UM pra TODA
 *     liga. Ele costura um time de ligas misturadas.
 *   · HERÓI é de uma liga: conta UM pra nação e DOIS pra liga dele. Ele
 *     premia quem monta em volta de um campeonato.
 *
 * ── AS COLEÇÕES ENTRAM AQUI ──────────────────────────────────────────
 *
 * `DFUT_TIPOS` é a lista inteira, e uma promoção nova é uma linha a mais:
 * nome, cor, raridade e como pontua. Nada no resto do jogo precisa saber
 * que ela existe — a tela lê o tipo e desenha, a química lê o tipo e conta.
 */

/**
 * Os tipos. `peso` é a chance relativa de a carta aparecer no lugar da pior
 * das cinco; `quimica` diz como ela pontua ('normal', 'icone', 'heroi').
 */
const DFUT_TIPOS = [
    'bronze' => ['rot' => 'Bronze', 'quimica' => 'normal', 'peso' => 0],
    'prata'  => ['rot' => 'Prata',  'quimica' => 'normal', 'peso' => 0],
    'ouro'   => ['rot' => 'Ouro',   'quimica' => 'normal', 'peso' => 0],
    'totw'   => ['rot' => 'TIME DA SEMANA', 'quimica' => 'normal', 'peso' => 16],
    'icone'  => ['rot' => 'ÍCONE',  'quimica' => 'icone',  'peso' => 5],
    'heroi'  => ['rot' => 'HERÓI',  'quimica' => 'heroi',  'peso' => 7],
];

/**
 * ── A PRIMEIRA COLEÇÃO: TIME DA SEMANA ───────────────────────────────
 *
 * O Marcos pediu coleções, e esta é a que dá pra fazer HOJE sem inventar
 * ninguém: é o mesmo jogador real, com a carta preta e dois pontos de OVR a
 * mais — a promoção mais antiga do FIFA, e a única em que o "especial" é uma
 * regra de jogo e não um dado inventado sobre alguém.
 *
 * Ícone e Herói mudam a química; o Time da Semana NÃO muda — ele pontua como
 * carta comum, como no EA FC. O que ele dá é OVR, e é o bastante pra a carta
 * preta ser disputada.
 */
const DFUT_TOTW_BONUS = 2;

/**
 * O tipo de uma carta comum, pelo OVR — a régua de sempre do FIFA.
 *
 * Como o baralho do draft tem piso 75, na prática quase tudo é ouro; prata e
 * bronze ficam pros tipos que uma coleção futura queira usar. A régua fica
 * completa de propósito: quando entrar pacote com carta de 60, ela já sabe
 * de que cor nascer.
 */
function draftFutTipoPorOvr(int $ovr): string
{
    if ($ovr >= 75) return 'ouro';
    if ($ovr >= 65) return 'prata';
    return 'bronze';
}

/** O tipo de qualquer carta, inclusive as especiais. */
function draftFutTipo(array $c): string
{
    $t = (string)($c['tipo'] ?? '');
    if ($t !== '' && isset(DFUT_TIPOS[$t])) return $t;
    return draftFutTipoPorOvr((int)($c['ovr'] ?? 0));
}

/** Como a carta pontua na química: 'normal', 'icone' ou 'heroi'. */
function draftFutQuimicaDoTipo(array $c): string
{
    return DFUT_TIPOS[draftFutTipo($c)]['quimica'] ?? 'normal';
}

/**
 * O código ISO de duas letras do país, pra bandeira.
 *
 * A API devolve o nome em inglês ("Brazil"); a bandeira é servida por código
 * ("br"). Só os países que aparecem no baralho estão aqui — e o que faltar
 * cai no vazio, que a tela trata escondendo a bandeira em vez de mostrar um
 * quadrado quebrado.
 */
const DFUT_PAIS_ISO = [
    'Brazil' => 'br', 'Argentina' => 'ar', 'Uruguay' => 'uy', 'Chile' => 'cl',
    'Colombia' => 'co', 'Paraguay' => 'py', 'Peru' => 'pe', 'Ecuador' => 'ec',
    'Venezuela' => 've', 'Bolivia' => 'bo', 'Mexico' => 'mx', 'United States' => 'us',
    'Canada' => 'ca', 'Costa Rica' => 'cr', 'Jamaica' => 'jm', 'Panama' => 'pa',
    'England' => 'gb-eng', 'Scotland' => 'gb-sct', 'Wales' => 'gb-wls',
    'Northern Ireland' => 'gb-nir', 'Ireland' => 'ie', 'France' => 'fr',
    'Spain' => 'es', 'Portugal' => 'pt', 'Italy' => 'it', 'Germany' => 'de',
    'Netherlands' => 'nl', 'Belgium' => 'be', 'Switzerland' => 'ch', 'Austria' => 'at',
    'Denmark' => 'dk', 'Sweden' => 'se', 'Norway' => 'no', 'Finland' => 'fi',
    'Iceland' => 'is', 'Poland' => 'pl', 'Czech Republic' => 'cz', 'Czechia' => 'cz',
    'Slovakia' => 'sk', 'Hungary' => 'hu', 'Romania' => 'ro', 'Bulgaria' => 'bg',
    'Serbia' => 'rs', 'Croatia' => 'hr', 'Slovenia' => 'si', 'Bosnia and Herzegovina' => 'ba',
    'Montenegro' => 'me', 'North Macedonia' => 'mk', 'Albania' => 'al', 'Kosovo' => 'xk',
    'Greece' => 'gr', 'Turkey' => 'tr', 'Russia' => 'ru', 'Ukraine' => 'ua',
    'Belarus' => 'by', 'Georgia' => 'ge', 'Armenia' => 'am', 'Israel' => 'il',
    'Morocco' => 'ma', 'Algeria' => 'dz', 'Tunisia' => 'tn', 'Egypt' => 'eg',
    'Senegal' => 'sn', 'Ivory Coast' => 'ci', "Cote d'Ivoire" => 'ci', 'Ghana' => 'gh',
    'Nigeria' => 'ng', 'Cameroon' => 'cm', 'Mali' => 'ml', 'Guinea' => 'gn',
    'Burkina Faso' => 'bf', 'DR Congo' => 'cd', 'Congo' => 'cg', 'Gabon' => 'ga',
    'South Africa' => 'za', 'Angola' => 'ao', 'Cape Verde' => 'cv', 'Zambia' => 'zm',
    'Japan' => 'jp', 'South Korea' => 'kr', 'Korea Republic' => 'kr', 'China' => 'cn',
    'Australia' => 'au', 'New Zealand' => 'nz', 'Iran' => 'ir', 'Iraq' => 'iq',
    'Saudi Arabia' => 'sa', 'Qatar' => 'qa', 'United Arab Emirates' => 'ae',
    'Uzbekistan' => 'uz', 'Jordan' => 'jo', 'Syria' => 'sy', 'Lebanon' => 'lb',
];

/** A URL da bandeirinha, ou '' quando o país não é conhecido. */
function draftFutBandeira(string $nacao): string
{
    $iso = DFUT_PAIS_ISO[$nacao] ?? '';
    return $iso === '' ? '' : "https://flagcdn.com/w40/{$iso}.png";
}

/**
 * Nacionalidade e foto por clube e nome, do que o importador já trouxe.
 *
 * Quem não está aqui fica sem nação, e a química trata isso: jogador sem nação
 * não faz elo de nação com ninguém. É melhor do que chutar o país pela liga,
 * que acertaria a maioria e erraria justamente no estrangeiro — que é o
 * jogador que o elo de nação existe pra encontrar.
 *
 * @see games/core/draftfut_nacionalidades_cli.php
 */
function draftFutExtras(): array
{
    static $dados = null;
    if ($dados !== null) return $dados;

    $arq = __DIR__ . '/../data/draftfut_jogadores.php';
    $dados = is_file($arq) ? (array)require $arq : [];
    return $dados;
}

/**
 * ── O OVR DA CARTA, CORRIGIDO PELA LIGA ──────────────────────────────
 *
 * Pedido do Marcos (07/10/2026): "diminui o ovr dos jogadores de palmeiras e
 * flamengo, carrascal mesmo ovr do dembele é sacanagem". Ele está certo, e o
 * motivo é conhecido: os elencos foram gerados por
 * games/core/fut_importar_ovr.php, que aplica o MESMO teto a todo clube
 * (FUT_OVR_TETO_CLUBE = 85, FUT_OVR_TETO_JOGADOR = 89). Isso serve ao jogo de
 * carreira, onde cada liga é um campeonato separado — e estraga o draft, onde
 * as ligas se misturam numa única régua.
 *
 * Aqui a régua passa a ser o PRESTÍGIO DA LIGA, que o catálogo do Copero já
 * guarda (COPERO_LIGAS[$liga][4]): Premier 96, LaLiga 94, Brasileirão 86.
 * O teto da Premier fica nos 89 de hoje e os outros descem a partir dele.
 *
 * ── A QUEDA É CURVA, E A PRIMEIRA TENTATIVA FOI RETA DEMAIS ──────────
 *
 * Com desconto linear de 0,45 por ponto de prestígio, o Carrascal saiu em 82
 * e o Dembélé em 83 — ou seja, a reclamação continuava de pé. A causa é que a
 * tabela de prestígio é apertada em cima: Premier 96 e Brasileirão 86 são dez
 * pontos, mas no futebol a distância entre os dois topos é bem maior.
 *
 * Por isso o desconto cresce com a distância (expoente 1,35): as cinco
 * grandes ficam coladas — 89, 88, 87, 87, 84 — e o resto desce de verdade,
 * Brasileirão em 82, Portugal e Argentina em 78. Aí Carrascal fica em 79
 * contra 82 do Dembélé, e Arrascaeta em 82, que é mais ou menos o que o
 * próprio EA FC dá a ele.
 *
 * O ajuste é um APERTO, não um corte: a carta é puxada em direção a 70 na
 * proporção do que a liga perdeu de teto. Cortar no teto achataria o topo de
 * cada elenco todo no mesmo número — dez brasileiros de 82 —, e é justamente
 * essa achatada que gerou o problema que estamos consertando.
 *
 * O ajuste vive SÓ NO DRAFT. Nada aqui toca games/data/elencos/, e o jogo de
 * carreira continua com os números dele.
 */
function draftFutOvrAjustado(int $ovr, string $liga): int
{
    $nivel = (int)(COPERO_LIGAS[$liga][4] ?? 70);
    $teto  = (int)round(89 - pow(max(0, 96 - $nivel), 1.35) * 0.30);

    if ($teto >= 89) return $ovr;           // Premier: a régua é ela mesma.
    if ($ovr <= 70)  return $ovr;           // Embaixo ninguém mexe.

    return (int)round(70 + ($ovr - 70) * (($teto - 70) / 19));
}
