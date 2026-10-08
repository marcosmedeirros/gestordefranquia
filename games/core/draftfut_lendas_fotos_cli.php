<?php
/**
 * AS FOTOS DAS LENDAS — ícones e heróis do FBA Draft. Roda na mão.
 *
 *   php games/core/draftfut_lendas_fotos_cli.php --sonda   (só mostra o que acharia)
 *   php games/core/draftfut_lendas_fotos_cli.php           (acha, baixa e grava)
 *
 * Pedido do Marcos (08/10/2026): "consegue pegar as fotos dos icones e
 * herois, todos esses que nao tem". As 44 lendas nasceram sem foto, e numa
 * carta ÍCONE a silhueta cinza dói mais que numa carta comum — ela é a carta
 * rara do pacote.
 *
 * ── POR QUE AS LENDAS PRECISAM DE OUTRO BUSCADOR ─────────────────────
 *
 * O buscador dos jogadores de hoje (@see draftfut_fotos_cli.php) tranca pelo
 * CLUBE ou pela NACIONALIDADE de quem está em atividade. Aqui nenhuma das
 * duas serve igual: o clube é o do auge, de trinta anos atrás, e a fonte
 * guarda o último clube. Sobra a nacionalidade — e ela basta, porque nomes
 * como Maradona e Beckenbauer não têm homônimo com a mesma bandeira.
 *
 * O problema real é OUTRO: a fonte não conhece a lenda pelo nome que a gente
 * escreve. "Ronaldo" devolve o Cristiano. "Pele" devolve o Bryan Pele, que é
 * francês. "Daniel Passarella" não devolve nada, mas "Passarella" devolve.
 * Daí DFUT_LENDA_BUSCA: um nome de busca à mão só pra quem precisa, e a
 * nacionalidade conferindo o que voltou.
 *
 * ── RECORTE OU RETRATO ───────────────────────────────────────────────
 *
 * A fonte tem dois tipos de imagem, e eles não se parecem:
 *   · strCutout  — o recorte sem fundo, que é o que a carta quer.
 *   · strThumb   — um retrato quadrado, com fundo de estádio ou de estúdio.
 *
 * Quem não tem recorte fica com o retrato, e o mapa ANOTA qual é qual, pra a
 * tela desenhar cada um do seu jeito (@see draftfut_carta.php). Fingir que
 * um retrato é recorte colaria uma caixa quadrada no meio da carta.
 *
 * Quem não passa na tranca continua sem foto. Foto errada numa carta é uma
 * mentira sobre uma pessoa real; faltar foto é só faltar foto.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/copero_clubes.php';
require_once __DIR__ . '/draftfut_lendas.php';
require_once __DIR__ . '/draftfut_cartas.php';

const API_LENDA  = 'https://www.thesportsdb.com/api/v1/json/3/';
const PASTA_FOTO = __DIR__ . '/../img/draftfut/lendas';
const WEB_FOTO   = '/games/img/draftfut/lendas';
const DESTINO    = __DIR__ . '/../data/draftfut_fotos_lendas.php';

$sonda = in_array('--sonda', $argv, true);

/**
 * O nome pelo qual a fonte conhece a lenda, quando não é o nosso.
 *
 * Só entra quem a busca pelo nome normal erra ou não acha. Cada linha aqui é
 * uma afirmação sobre uma pessoa, então nada de chute: ou eu sei o nome de
 * registro, ou o nome fica de fora e a carta segue sem foto.
 */
const DFUT_LENDA_BUSCA = [
    /* A fonte guarda estes dois pelo nome de registro, e só por ele:
       "Pele" devolve um francês chamado Bryan Pele, "Eusebio" devolve
       um Bancessi. Com o nome de batismo, os dois saem de primeira. */
    'Pelé'                  => 'Edson Arantes do Nascimento',
    'Eusébio'               => 'Eusebio da Silva Ferreira',
    'Ronaldo Fenômeno'      => 'Ronaldo Nazario',
    'Ronaldinho Gaúcho'     => 'Ronaldinho',
    'Sócrates'              => 'Socrates',
    'Falcão'                => 'Paulo Roberto Falcao',
    'Taffarel'              => 'Claudio Taffarel',
    'Carlos Alberto Torres' => 'Carlos Alberto',
    'Daniel Passarella'     => 'Passarella',
    'Alfredo Di Stéfano'    => 'Di Stefano',
    'Juan Román Riquelme'   => 'Riquelme',
    'Xavi Hernández'        => 'Xavi',
    'Lev Yashin'            => 'Yashin',
    'Gerd Müller'           => 'Gerd Muller',
    'Lothar Matthäus'       => 'Lothar Matthaus',
];

/**
 * OS QUE A FONTE NÃO TEM, e já procurei.
 *
 * Não é falta de tentar o nome certo: a chave pública devolve UM
 * resultado por busca, então procurei cada um pelo apelido, pelo nome de
 * registro e com acento. Ronaldo, Zico, Sócrates, Carlos Alberto Torres,
 * Falcão, Riquelme, Passarella e Francescoli não estão lá sob nenhum
 * deles. Estes oito ficam sem foto até vir de outro lugar — inventar um
 * nome de busca pra forçar um resultado traria a foto de outra pessoa.
 */

/**
 * Nacionalidades que a fonte escreve de outro jeito.
 *
 * Yashin jogou pela União Soviética, e é assim que ele aparece lá. Recusar a
 * foto por causa disso seria recusar a foto certa.
 */
const DFUT_LENDA_NAC_ALIAS = [
    'Soviet Union' => 'Russia',
    'USSR'         => 'Russia',
    'Russian Federation' => 'Russia',
    'Holland'      => 'Netherlands',
    'The Netherlands' => 'Netherlands',
    'West Germany' => 'Germany',
    'England'      => 'England',
];

/** Sem acento, pra comparar nome — iconv aqui separa o acento em vez de tirar. */
function lfSemAcento(string $s): string
{
    static $t = [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ø'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n','ý'=>'y','ÿ'=>'y','š'=>'s','ž'=>'z','ć'=>'c','č'=>'c','đ'=>'d',
    ];
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, $t);
}

/** As palavras do nome, sem acento e sem pontuação. */
function lfPalavras(string $s): array
{
    $p = preg_split('/[^a-z0-9]+/', lfSemAcento($s), -1, PREG_SPLIT_NO_EMPTY);
    return $p ?: [];
}

/**
 * O nome da fonte é o mesmo da lenda?
 *
 * Palavra inteira nos dois sentidos, como no buscador dos jogadores atuais:
 * {ronaldo} não está contido em {cristiano, ronaldo} de um jeito que
 * interesse, mas {di, stefano} está em {alfredo, di, stefano}. A tranca é
 * UM dos dois conter o outro, e a nacionalidade decide o resto.
 */
function lfNomeBate(string $nosso, string $deles): bool
{
    $a = lfPalavras($nosso);
    $b = lfPalavras($deles);
    if (!$a || !$b) return false;
    $contem = static fn(array $x, array $y) => !array_diff($x, $y);
    return $contem($a, $b) || $contem($b, $a);
}

function lfNac(string $n): string
{
    $n = trim($n);
    return DFUT_LENDA_NAC_ALIAS[$n] ?? $n;
}

/**
 * Pergunta à fonte, insistindo quando é a REDE que falha.
 *
 * A chave pública limita o ritmo, e a primeira versão disto tratava o erro
 * de rede como "não existe": na hora em que a fonte começou a recusar, oito
 * lendas que já tinham sido encontradas viraram "não achei". Um falso
 * negativo aqui apaga foto boa. Agora a mesma pergunta é refeita até três
 * vezes, com espera crescente, e quem não responder nem assim volta como
 * erro — não como ausência.
 */
function lfBusca(string $nome): array
{
    $u = API_LENDA . 'searchplayers.php?p=' . rawurlencode($nome);
    $ctx = stream_context_create(['http' => ['timeout' => 25, 'header' => "User-Agent: fba-draft/1.0\r\n"]]);

    for ($tent = 1; $tent <= 3; $tent++) {
        $j = @file_get_contents($u, false, $ctx);
        if ($j !== false) {
            $d = json_decode((string)$j, true);
            /* Resposta que não é JSON é a fonte reclamando, não resposta. */
            if (is_array($d) || trim((string)$j) === '') {
                return ['erro' => false, 'lista' => is_array($d['player'] ?? null) ? $d['player'] : []];
            }
        }
        sleep(4 * $tent);
    }
    return ['erro' => true, 'lista' => []];
}

/* ═══════════════════════════ A VARREDURA ═════════════════════════════ */

$achados = [];   // nome => ['url' => ..., 'tipo' => 'recorte'|'retrato', 'fonte' => nome deles]
$faltam  = [];   // a fonte respondeu e não tem
$mudos   = [];   // a fonte não respondeu — não é a mesma coisa

foreach (DFUT_LENDAS as [$nome, $pos, $ovr, $clube, $liga, $tipo]) {
    $nacEsperada = lfNac(DFUT_LENDA_NACAO[$nome] ?? '');

    /* Os nomes a tentar, do mais provável pro menos: o da mão, o nosso, e o
       nosso sem acento (a fonte escreve "Romario", não "Romário"). */
    $tentativas = [];
    if (isset(DFUT_LENDA_BUSCA[$nome])) $tentativas[] = DFUT_LENDA_BUSCA[$nome];
    $tentativas[] = $nome;
    $semAcento = lfSemAcento($nome);
    if ($semAcento !== mb_strtolower($nome, 'UTF-8')) $tentativas[] = $semAcento;

    $achou = null;
    $mudou = false;
    foreach (array_unique($tentativas) as $t) {
        $r = lfBusca($t);
        if ($r['erro']) { $mudou = true; continue; }

        foreach ($r['lista'] as $p) {
            $deles = trim((string)($p['strPlayer'] ?? ''));
            if (!lfNomeBate($nome, $deles)) continue;

            $nacDeles = lfNac((string)($p['strNationality'] ?? ''));
            if ($nacEsperada !== '' && $nacDeles !== '' && $nacDeles !== $nacEsperada) continue;

            $cut = trim((string)($p['strCutout'] ?? ''));
            $ren = trim((string)($p['strRender'] ?? ''));
            $thu = trim((string)($p['strThumb'] ?? ''));
            $url = $cut ?: $ren ?: $thu;
            if ($url === '') continue;

            $achou = ['url' => $url,
                      'tipo' => ($cut || $ren) ? 'recorte' : 'retrato',
                      'fonte' => $deles,
                      'nac' => $nacDeles,
                      'busca' => $t];
            break 2;
        }
        sleep(2);
    }

    if ($achou) {
        $achados[$nome] = $achou;
        printf("  %-24s %-9s %-22s %s\n", mb_substr($nome, 0, 23), $achou['tipo'],
               mb_substr($achou['fonte'], 0, 21), $achou['nac']);
    } elseif ($mudou) {
        $mudos[] = $nome;
        printf("  %-24s %s\n", mb_substr($nome, 0, 23), '… a fonte não respondeu');
    } else {
        $faltam[] = $nome;
        printf("  %-24s %s\n", mb_substr($nome, 0, 23), '— não achei');
    }
    sleep(3);
}

printf("\n%d de %d com foto · %d sem · %d sem resposta\n",
       count($achados), count(DFUT_LENDAS), count($faltam), count($mudos));
if ($faltam) echo "sem foto: " . implode(', ', $faltam) . "\n";
if ($mudos) {
    echo "SEM RESPOSTA (rode de novo, não é ausência): " . implode(', ', $mudos) . "\n";
}

if ($sonda) { echo "\n(sonda: nada foi baixado nem gravado)\n"; exit; }

/* ═══════════════════════ BAIXA E GRAVA ═══════════════════════════════ */

if (!is_dir(PASTA_FOTO) && !mkdir(PASTA_FOTO, 0775, true)) {
    fwrite(STDERR, "não consegui criar " . PASTA_FOTO . "\n");
    exit(1);
}

/* O MAPA ANTIGO É A BASE. Se a fonte não respondeu sobre alguém nesta
   rodada, a foto que ele já tinha continua valendo — gravar só o que
   voltou agora apagaria foto boa por causa de um limite de ritmo. */
$mapa = is_file(DESTINO) ? (array)(require DESTINO) : [];
foreach ($achados as $nome => $a) {
    $slug = preg_replace('/[^a-z0-9]+/', '-', lfSemAcento($nome));
    $slug = trim((string)$slug, '-');
    $ext  = strtolower(pathinfo(parse_url($a['url'], PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) $ext = 'png';
    $arq  = $slug . '.' . $ext;

    $ctx = stream_context_create(['http' => ['timeout' => 40, 'header' => "User-Agent: fba-draft/1.0\r\n"]]);
    $bin = @file_get_contents($a['url'], false, $ctx);
    /* ARQUIVO MINÚSCULO NÃO É FOTO. Já vi a fonte devolver um html de erro
       com 200; gravar isso deixaria a carta com imagem quebrada, que é pior
       que carta sem imagem. */
    if ($bin === false || strlen($bin) < 2048) {
        echo "  pulei $nome (download vazio)\n";
        continue;
    }
    file_put_contents(PASTA_FOTO . '/' . $arq, $bin);
    $mapa[$nome] = ['src' => WEB_FOTO . '/' . $arq, 'tipo' => $a['tipo']];
    sleep(1);
}

/* Lenda que saiu do catálogo não fica no mapa puxando arquivo morto. */
$mapa = array_intersect_key($mapa, array_flip(array_column(DFUT_LENDAS, 0)));
ksort($mapa);

$php = "<?php\n"
     . "/**\n"
     . " * AS FOTOS DAS LENDAS — gerado por games/core/draftfut_lendas_fotos_cli.php.\n"
     . " *\n"
     . " * Não edite na mão: rode o CLI de novo. `tipo` separa o recorte sem fundo\n"
     . " * (que a carta desenha inteiro) do retrato quadrado (que a carta desenha\n"
     . " * com máscara, senão vira uma caixa no meio do dourado).\n"
     . " */\n\n"
     . "return " . var_export($mapa, true) . ";\n";
file_put_contents(DESTINO, $php);

printf("\n%d fotos em %s\n%s atualizado\n", count($mapa), WEB_FOTO, basename(DESTINO));
