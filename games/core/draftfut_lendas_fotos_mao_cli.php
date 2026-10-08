<?php
/**
 * AS FOTOS DE LENDA QUE CHEGARAM NA MÃO — roda na mão.
 *
 *   php -d extension=gd games/core/draftfut_lendas_fotos_mao_cli.php
 *   php -d extension=gd games/core/draftfut_lendas_fotos_mao_cli.php --de="C:/caminho"
 *
 * Oito lendas não existem na fonte sob nome nenhum (@see
 * draftfut_lendas_fotos_cli.php, que lista quais e por quê), então elas vêm
 * de fora. O Marcos salva os arquivos na pasta de downloads e isto encaixa:
 * reconhece de quem é pelo nome do arquivo, encolhe pro tamanho da carta e
 * escreve no mapa.
 *
 * ── RECORTE OU RETRATO, DECIDIDO OLHANDO A IMAGEM ────────────────────
 *
 * PNG com transparência de verdade entra como recorte e a carta desenha
 * inteiro. Qualquer outra coisa entra como retrato e a carta recorta em
 * medalhão — é o que impede uma foto de arquivo com fundo de estádio de
 * virar um azulejo no meio do dourado. Ninguém precisa declarar nada: o
 * arquivo diz o que é.
 *
 * ── O NOME DO ARQUIVO É QUEM IDENTIFICA ──────────────────────────────
 *
 * Vale o slug ("juan-roman-riquelme.png"), o nome com ou sem acento, e com
 * espaço, ponto ou sublinhado no lugar do hífen. O que NÃO vale é adivinhar:
 * arquivo que não bate com nenhuma lenda é listado e ignorado, porque pôr a
 * foto errada numa carta é mentir sobre uma pessoa real.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/copero_clubes.php';
require_once __DIR__ . '/draftfut_lendas.php';

const PASTA_LENDA = __DIR__ . '/../img/draftfut/lendas';
const WEB_LENDA   = '/games/img/draftfut/lendas';
const MAPA_LENDA  = __DIR__ . '/../data/draftfut_fotos_lendas.php';
const LARG_LENDA  = 320;

if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "Sem GD. Rode com: php -d extension=gd " . basename(__FILE__) . "\n");
    exit(1);
}

$de = getenv('USERPROFILE') ? getenv('USERPROFILE') . '/Downloads' : getenv('HOME') . '/Downloads';
foreach ($argv as $a) if (preg_match('/^--de=(.+)$/', $a, $m)) $de = trim($m[1], '"');
$de = rtrim(str_replace('\\', '/', $de), '/');

if (!is_dir($de)) { fwrite(STDERR, "não achei a pasta $de\n"); exit(1); }

/** A chave de comparação: minúsculo, sem acento, só letras e números. */
function mlChave(string $s): string
{
    $t = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
          'í'=>'i','ì'=>'i','î'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
          'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','ć'=>'c','š'=>'s','ž'=>'z'];
    $s = strtr(mb_strtolower($s, 'UTF-8'), $t);
    return (string)preg_replace('/[^a-z0-9]+/', '', $s);
}

/**
 * O PNG tem transparência de verdade?
 *
 * Ter canal alfa não basta — quase todo PNG tem. O que importa é existir
 * pixel transparente, e logo na BORDA, que é onde o fundo de um recorte
 * está. Um retrato quadrado salvo como PNG passaria no teste do canal e
 * entraria como recorte, colando a foto inteira na carta.
 */
function mlTemFundoVazado($im, int $w, int $h): bool
{
    $pontos = [];
    for ($x = 0; $x < $w; $x += max(1, (int)($w / 24))) { $pontos[] = [$x, 0]; $pontos[] = [$x, $h - 1]; }
    for ($y = 0; $y < $h; $y += max(1, (int)($h / 24))) { $pontos[] = [0, $y]; $pontos[] = [$w - 1, $y]; }

    $vazados = 0;
    foreach ($pontos as [$x, $y]) {
        $a = (imagecolorat($im, $x, $y) >> 24) & 0x7F;
        if ($a > 100) $vazados++;
    }
    /* Metade da borda transparente = recorte. Menos que isso pode ser só um
       canto apagado, e aí vale mais tratar como retrato. */
    return $vazados >= count($pontos) / 2;
}

/* ── De quem é cada lenda sem foto ────────────────────────────────── */
$mapa = is_file(MAPA_LENDA) ? (array)require MAPA_LENDA : [];
$porChave = [];
foreach (DFUT_LENDAS as [$nome]) $porChave[mlChave($nome)] = $nome;

$entrou = []; $sobrou = [];
foreach (glob($de . '/*.{png,jpg,jpeg,webp,PNG,JPG,JPEG,WEBP}', GLOB_BRACE) as $arq) {
    $base = pathinfo($arq, PATHINFO_FILENAME);
    $nome = $porChave[mlChave($base)] ?? null;
    if ($nome === null) continue;                 // não é lenda; nem menciona
    if (isset($mapa[$nome])) { $sobrou[] = "$nome já tinha foto — pulei"; continue; }

    $info = @getimagesize($arq);
    if (!$info) { $sobrou[] = basename($arq) . ': não é imagem'; continue; }
    [$w, $h] = $info;
    $ext = strtolower(pathinfo($arq, PATHINFO_EXTENSION));

    $src = $ext === 'png' ? @imagecreatefrompng($arq)
         : ($ext === 'webp' ? @imagecreatefromwebp($arq) : @imagecreatefromjpeg($arq));
    if (!$src) { $sobrou[] = basename($arq) . ': não abriu'; continue; }

    $recorte = $ext === 'png' && mlTemFundoVazado($src, $w, $h);

    $nw = min($w, LARG_LENDA);
    $nh = (int)round($h * $nw / $w);
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-',
                 strtr(mb_strtolower($nome, 'UTF-8'),
                       ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o',
                        'ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c','ñ'=>'n','ä'=>'a','ü'=>'u'])), '-');
    $saida = PASTA_LENDA . '/' . $slug . ($recorte ? '.png' : '.jpg');
    if ($recorte) imagepng($dst, $saida, 8); else imagejpeg($dst, $saida, 86);
    imagedestroy($src); imagedestroy($dst);

    $mapa[$nome] = ['src' => WEB_LENDA . '/' . basename($saida),
                    'tipo' => $recorte ? 'recorte' : 'retrato'];
    $entrou[] = sprintf('%-24s %-8s %dx%d', $nome, $recorte ? 'recorte' : 'retrato', $nw, $nh);
}

if (!$entrou) {
    echo "Nenhuma foto nova em $de.\n";
    echo "O nome do arquivo tem que ser o da lenda — ex.: zico.png, juan-roman-riquelme.jpg.\n";
    foreach ($sobrou as $x) echo "  $x\n";
    exit;
}

ksort($mapa);
$php = "<?php\n"
     . "/**\n"
     . " * AS FOTOS DAS LENDAS — gerado pelos CLIs de foto de lenda.\n"
     . " *\n"
     . " * Não edite na mão. `tipo` separa o recorte sem fundo (a carta desenha\n"
     . " * inteiro) do retrato (a carta recorta em medalhão).\n"
     . " */\n\n"
     . "return " . var_export($mapa, true) . ";\n";
file_put_contents(MAPA_LENDA, $php);

foreach ($entrou as $x) echo "  $x\n";
foreach ($sobrou as $x) echo "  $x\n";
$semFoto = count(DFUT_LENDAS) - count($mapa);
printf("\n%d entraram · %d de %d lendas com foto · %d ainda sem\n",
       count($entrou), count($mapa), count(DFUT_LENDAS), $semFoto);
if ($semFoto) {
    $faltam = array_diff(array_column(DFUT_LENDAS, 0), array_keys($mapa));
    echo "sem foto: " . implode(', ', $faltam) . "\n";
}
