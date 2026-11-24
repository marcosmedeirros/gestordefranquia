<?php
/**
 * MINIATURA DE ESCUDO, com cache em disco.
 *
 * Os escudos dos times são as fotos que os GMs subiram no site, no tamanho
 * em que subiram: há arquivos de 7,7 MB na pasta, e a pasta inteira pesa
 * 233 MB. A tela do Minha Temporada desenha esses escudos em 23 a 82
 * pixels — baixar sete megabytes para pintar um quadradinho de 23px é o
 * que fazia a lista de 32 times demorar a aparecer.
 *
 * Aqui o arquivo é redimensionado UMA VEZ e guardado em
 * games/storage/escudos/. Da segunda visita em diante é um WebP de poucos
 * kilobytes servido direto do disco, com cache de um ano no navegador.
 *
 * ── POR QUE SÓ O NOME DO ARQUIVO ─────────────────────────────────────
 * O parâmetro é o BASENAME, nunca um caminho nem uma URL. Aceitar URL
 * transformaria isto num buscador de páginas pra quem chamasse — o
 * servidor baixaria o que mandassem, inclusive endereços internos. Com
 * basename validado por regex e resolvido dentro de /img/teams, o pior que
 * alguém consegue pedir é um escudo que já é público.
 */

declare(strict_types=1);

$arquivo = (string) ($_GET['f'] ?? '');
$lado    = (int) ($_GET['s'] ?? 96);
$lado    = max(24, min(256, $lado));

/* Só nome de arquivo: letras, números, hífen, ponto. Sem barra, sem "..",
   sem nada que saia da pasta. */
if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $arquivo) || str_contains($arquivo, '..')) {
    http_response_code(400); exit;
}

$base   = dirname(__DIR__) . '/img/teams';
$origem = $base . '/' . $arquivo;

// realpath fecha a porta de vez: o arquivo TEM que estar dentro da pasta.
$real = realpath($origem);
$raiz = realpath($base);
if ($real === false || $raiz === false || !str_starts_with($real, $raiz . DIRECTORY_SEPARATOR)) {
    http_response_code(404); exit;
}

$cacheDir = __DIR__ . '/storage/escudos';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cache = $cacheDir . '/' . $lado . '-' . preg_replace('/[^A-Za-z0-9._-]/', '', $arquivo) . '.webp';

/* Serve do cache quando ele existe e é mais novo que o original — trocar o
   escudo no site refaz a miniatura sozinho. */
$servir = function (string $caminho, string $tipo): void {
    header('Content-Type: ' . $tipo);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($caminho));
    readfile($caminho);
    exit;
};

if (is_file($cache) && filemtime($cache) >= filemtime($real)) {
    $servir($cache, 'image/webp');
}

/* Sem GD não há miniatura — e isso não pode derrubar a tela: manda o
   original, que é o que acontecia antes de este arquivo existir. */
if (!extension_loaded('gd')) {
    $tipos = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
              'gif' => 'image/gif', 'webp' => 'image/webp'];
    $servir($real, $tipos[strtolower(pathinfo($real, PATHINFO_EXTENSION))] ?? 'image/png');
}

try {
    $info = @getimagesize($real);
    if (!$info) { http_response_code(404); exit; }

    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($real),
        IMAGETYPE_PNG  => @imagecreatefrompng($real),
        IMAGETYPE_GIF  => @imagecreatefromgif($real),
        IMAGETYPE_WEBP => @imagecreatefromwebp($real),
        default        => false,
    };
    if (!$src) { http_response_code(415); exit; }

    [$lo, $al] = [imagesx($src), imagesy($src)];

    /* QUADRADO, CORTANDO PELO CENTRO. A tela desenha os escudos em caixas
       quadradas com object-fit:cover; fazer o mesmo corte aqui evita que a
       miniatura chegue esticada e economiza os pixels que seriam jogados
       fora de qualquer jeito. */
    $corte = min($lo, $al);
    $sx = (int) (($lo - $corte) / 2);
    $sy = (int) (($al - $corte) / 2);

    $dst = imagecreatetruecolor($lado, $lado);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $lado, $lado, $corte, $corte);

    imagewebp($dst, $cache, 82);
    imagedestroy($src);
    imagedestroy($dst);

    if (is_file($cache)) $servir($cache, 'image/webp');
    http_response_code(500);
} catch (Throwable $e) {
    error_log('[escudo] ' . $arquivo . ': ' . $e->getMessage());
    http_response_code(500);
}
