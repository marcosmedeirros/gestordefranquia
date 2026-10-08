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
    'totw'   => ['rot' => 'TIME DA SEMANA', 'quimica' => 'normal', 'peso' => 14],
    'futuro' => ['rot' => 'FUTURO', 'quimica' => 'normal', 'peso' => 25],
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
 * ── A SEGUNDA COLEÇÃO: FUTURO ────────────────────────────────────────
 *
 * O Future Stars do EA FC: o garoto com a carta que ele ainda vai ter. Entra
 * aqui pelo mesmo motivo do Time da Semana — é promoção feita de DADO QUE JÁ
 * EXISTE. A idade está em todo elenco de games/data/elencos/, então "quem tem
 * 21 anos ou menos" é consulta, não chute.
 *
 * O bônus é maior que o do Time da Semana porque a carta é mais rara e o
 * jogador é pior de base: sem isso, "Futuro" seria um selo bonito numa carta
 * que ninguém escolheria.
 */
const DFUT_FUTURO_IDADE = 22;
const DFUT_FUTURO_BONUS = 3;

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

/**
 * ── O NOME SEM ACENTO, PRA COMPARAR E PRA BUSCAR ─────────────────────
 *
 * Existe porque a saída óbvia estava errada e custou caro: os importadores
 * usavam iconv('UTF-8', 'ASCII//TRANSLIT'), e neste PHP do Windows ele não
 * tira o acento — ele SEPARA o acento numa marca ASCII:
 *
 *     Inter de Milão   =>  "Inter de Mil~ao"
 *     Vinícius Júnior  =>  "Vin'icius J'unior"
 *     Grêmio           =>  "Gr^emio"
 *
 * Como o passo seguinte troca tudo que não é letra por espaço, "Vinícius
 * Júnior" virava a busca "vin icius j unior" e a API não achava nada. Metade
 * dos nomes do baralho tem acento, então metade das buscas ia quebrada — e o
 * sintoma era parecer que a API só não tinha aqueles jogadores.
 *
 * Aqui a troca é tabela, não transliteração: cada letra acentuada vira a
 * letra sem acento, inclusive as que o inglês não tem (ø, ß, ğ, ş, ı, đ, ł).
 */
function draftFutChaveNome(string $n): string
{
    static $mapa = null;
    if ($mapa === null) {
        $de   = ['á','à','â','ã','ä','å','ā','ă','ą','ç','ć','č','é','è','ê','ë','ē','ė','ę','ě',
                 'í','ì','î','ï','ī','į','ı','ñ','ń','ň','ó','ò','ô','õ','ö','ø','ō','ő',
                 'ú','ù','û','ü','ū','ů','ű','ý','ÿ','š','ś','ş','ž','ź','ż','ğ','đ','ł','ß','æ','œ','þ','ð'];
        $para = ['a','a','a','a','a','a','a','a','a','c','c','c','e','e','e','e','e','e','e','e',
                 'i','i','i','i','i','i','i','n','n','n','o','o','o','o','o','o','o','o',
                 'u','u','u','u','u','u','u','y','y','s','s','s','z','z','z','g','d','l','ss','ae','oe','th','d'];
        $mapa = array_combine($de, $para);
    }

    $n = mb_strtolower($n, 'UTF-8');
    /* O İ turco minúsculo é "i" + ponto combinante, e o ponto sozinho virava
       espaço: "İlkay" saía "i lkay". Fora as marcas combinantes primeiro. */
    $n = (string)preg_replace('/\p{Mn}/u', '', $n);
    $n = strtr($n, $mapa);
    $n = preg_replace('/[^a-z ]/', ' ', $n);
    return trim(preg_replace('/\s+/', ' ', (string)$n));
}

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
 * ── QUEM TROCOU DE CLUBE DEPOIS QUE O ELENCO FOI TIRADO ──────────────
 *
 * Os elencos em games/data/elencos/ são uma foto do passado, e as fotos dos
 * jogadores denunciaram: o Isak aparecia de Liverpool numa carta do
 * Newcastle. Esta camada corrige o CLUBE da carta — e só no draft; nada aqui
 * toca os elencos, que são do jogo de carreira.
 *
 * O arquivo é gerado (@see draftfut_clubes_cli.php) e guarda também quem foi
 * conferido e NÃO mudou, como string vazia, pra a próxima rodada não gastar
 * a chave da fonte perguntando de novo.
 *
 * O OVR NÃO MUDA JUNTO, de propósito. O ajuste por liga
 * (@see draftFutOvrAjustado) existe pra corrigir como o elenco de origem foi
 * gerado, não pra dizer quanto vale quem joga ali — um goleiro de 88 que foi
 * pro Fenerbahçe continua um goleiro de 88, só que agora num campeonato mais
 * fraco. Recalcular derrubaria ele pra 74 por ter mudado de endereço.
 */
function draftFutTransferencias(): array
{
    static $t = null;
    if ($t !== null) return $t;
    $arq = __DIR__ . '/../data/draftfut_transferencias.php';
    $t = is_file($arq) ? (array)require $arq : [];
    return $t;
}

/**
 * ── AS NACIONALIDADES ESCRITAS À MÃO ─────────────────────────────────
 *
 * A fonte não acha jogador de nome curto. "Rodri" devolve Jay Rodriguez;
 * "Ederson", "Pedri", "Carvajal" e "Gabriel" devolvem outras pessoas, e a
 * conferência por clube — que é o que protege o resto — derruba todos. São
 * justamente as cartas mais altas do baralho, as que mais aparecem.
 *
 * Então estas vêm escritas, com a chave CLUBE|NOME pra homônimo não pegar
 * carona. É o mesmo tratamento que o projeto já dá às lendas
 * (@see DFUT_LENDA_NACAO): dado curado, declarado como curado, e usado só
 * quando a fonte não respondeu. O importador sempre ganha de quem está aqui,
 * então consertar a fonte apaga a exceção sozinho.
 */
const DFUT_NACAO_MAO = [
    // ── Os que a busca confunde com outra pessoa ─────────────────────
    'Manchester City|Rodri'          => 'Spain',
    'Manchester City|Ederson'        => 'Brazil',
    'Manchester City|Savinho'        => 'Brazil',
    'Arsenal|Gabriel'                => 'Brazil',
    'Arsenal|Benjamin White'         => 'England',
    'Arsenal|Jorginho'               => 'Italy',
    'Arsenal|Neto'                   => 'Brazil',
    'Real Madrid|Carvajal'           => 'Spain',
    'Real Madrid|Fran García'        => 'Spain',
    'Barcelona|Pedri'                => 'Spain',
    'Barcelona|Gavi'                 => 'Spain',
    'AC Milan|Theo Hernández'        => 'France',
    'AC Milan|Rafael Leão'           => 'Portugal',
    'AC Milan|Morata'                => 'Spain',
    'Liverpool|Luis Díaz'            => 'Colombia',
    'Newcastle|Bruno Guimarães'      => 'Brazil',
    'Manchester United|Casemiro'     => 'Brazil',
    'Manchester United|Antony'       => 'Brazil',
    'Bayern de Munique|Palhinha'     => 'Portugal',
    'Bayer Leverkusen|Grimaldo'      => 'Spain',
    'PSG|Marquinhos'                 => 'Brazil',
    'PSG|Vitinha'                    => 'Portugal',
    'PSG|Lee Kang In'                => 'South Korea',
    'Juventus|Danilo'                => 'Brazil',
    'Juventus|Michele Di Gregorio'   => 'Italy',
    'Napoli|André-Franck Zambo Anguissa' => 'Cameroon',
    'Inter de Milão|Yann Aurel Bisseck'  => 'Germany',
    'Roma|Mathew Ryan'               => 'Australia',
    'Monaco|Alexandr Golovin'        => 'Russia',
    'Porto|Pepê'                     => 'Brazil',
    'Sevilla|Suso'                   => 'Spain',
    'Real Sociedad|Zubimendi'        => 'Spain',
    'Athletic Club|De Marcos'        => 'Spain',
    'Atlético de Madrid|Reinildo'    => 'Mozambique',
    'Atlético de Madrid|Azpilicueta' => 'Spain',
    'Atlético de Madrid|Riquelme'    => 'Spain',
    'Tottenham|Reguilón'             => 'Spain',

    // ── Brasileiros de nome curto, nos clubes brasileiros ────────────
    'Flamengo|Pedro'                 => 'Brazil',
    'Botafogo|Danilo'                => 'Brazil',
    'Botafogo|Vitinho'               => 'Brazil',
    'Palmeiras|Mauricio'             => 'Brazil',
    'Fluminense|Martinelli'          => 'Brazil',
    'Fluminense|Hércules'            => 'Brazil',
    'Grêmio|Tetê'                    => 'Brazil',
    'São Paulo|Victor Sá'            => 'Brazil',
    'Atlético-MG|Victor Hugo'        => 'Brazil',
    'Palmeiras|Flaco López'          => 'Argentina',

    /* FICAM DE FORA, DE PROPÓSITO: "Arias" do Palmeiras, "Bastos" e "Pedro
       Henrique" do Fortaleza. Não consigo dizer de quem são essas três cartas
       com certeza, e chutar o país pelo jeito do nome é exatamente o erro que
       esta lista existe pra não cometer. Ficam sem nação, e a química trata:
       jogador sem nação não faz elo de nação com ninguém. */
];

/**
 * ── COMO A FONTE ESCREVE CADA CLUBE ──────────────────────────────────
 *
 * Mora aqui porque os DOIS importadores precisam dela e, enquanto cada um
 * tinha a sua, elas desandaram: o de jogador foi corrigido, o de clube ficou
 * com a lista velha, e dezessete clubes — Newcastle, Brighton, Inter de
 * Milão, PSG, Monaco, Lille, Athletic Club entre eles — voltaram vazios da
 * busca. Duas listas pra mesma pergunta viram duas respostas diferentes.
 *
 * Só entra quem a busca pelo nome do catálogo não acha sozinho. O resto o
 * próprio nome resolve, ou a conferência por contenção ("Tottenham" dentro de
 * "Tottenham Hotspur").
 */
const DFUT_CLUBE_API = [
    'Atlético-MG'        => 'Atletico Mineiro',
    'Athletico-PR'       => 'Athletico Paranaense',
    'RB Bragantino'      => 'Red Bull Bragantino',
    'Bayern de Munique'  => 'Bayern Munich',
    'Inter de Milão'     => 'Inter Milan',
    'Atlético de Madrid' => 'Atletico Madrid',
    'Celta de Vigo'      => 'Celta Vigo',
    'Athletic Club'      => 'Athletic Bilbao',
    'Sporting CP'        => 'Sporting Lisbon',
    /* "Paris SG" devolvia o Torcy e "PSG" devolvia um time de Hong Kong;
       sem hífen a fonte acha. */
    'PSG'                => 'Paris Saint Germain',
    'Roma'               => 'AS Roma',
    'Porto'              => 'FC Porto',
    'Tottenham'          => 'Tottenham Hotspur',
    'Newcastle'          => 'Newcastle United',
    'West Ham'           => 'West Ham United',
    'Brighton'           => 'Brighton and Hove Albion',
];

/**
 * O país que a fonte dá ao clube, quando não é o país da liga.
 *
 * Só o Monaco: joga a Ligue 1, então o catálogo o põe na França, mas a fonte
 * responde "Monaco". Sem esta exceção a conferência de país o derruba.
 */
const DFUT_CLUBE_PAIS_API = ['Monaco' => 'Monaco'];

/**
 * O clube que a fonte devolveu é o clube que pedimos?
 *
 * Igualdade exata deixava passar batido metade dos ingleses — a fonte escreve
 * "Tottenham Hotspur", "Newcastle United", "Brighton and Hove Albion", e o
 * catálogo escreve o nome curto. Então vale também quando um nome contém o
 * outro, com PELO MENOS SEIS LETRAS no mais curto.
 *
 * O piso de seis não é enfeite: com cinco, "inter" casaria "Inter de Milão"
 * com "Internacional" e o Lautaro viraria colorado.
 */
function draftFutClubeBate(string $daFonte, array $nossos): bool
{
    $a = draftFutChaveNome($daFonte);
    foreach ($nossos as $n) {
        $b = draftFutChaveNome($n);
        if ($a === $b) return true;
        $curto = mb_strlen($a) <= mb_strlen($b) ? $a : $b;
        $longo = $curto === $a ? $b : $a;
        if (mb_strlen($curto) >= 6 && str_contains($longo, $curto)) return true;
    }
    return false;
}

/** A nacionalidade de uma carta: a da fonte, senão a escrita à mão. */
function draftFutNacao(string $clube, string $nome, ?array $daFonte): string
{
    $nac = trim((string)($daFonte['nac'] ?? ''));
    if ($nac !== '') return $nac;
    return DFUT_NACAO_MAO[$clube . '|' . $nome] ?? '';
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
