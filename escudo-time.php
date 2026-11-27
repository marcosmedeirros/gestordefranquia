<?php
/**
 * ESCUDO DE UM TIME, SEMPRE DO NOSSO DOMÍNIO.
 *
 * ── Por que existe ───────────────────────────────────────────────────
 * Os 30 times da ROOKIE não têm escudo no site: o `photo_url` deles aponta
 * pro cdn.nba.com. Isso funciona pra OLHAR, e quebra pra DESENHAR — um
 * <img> de outro domínio contamina o canvas, e o navegador recusa
 * `toBlob` com "Tainted canvases may not be exported". Era o que impedia
 * a tier list de gerar a imagem quando havia um time da ROOKIE no quadro.
 *
 * Aqui o arquivo de fora é baixado UMA VEZ, convertido pra WebP e guardado
 * em disco. O navegador passa a pedir tudo ao fbabrasil.com.br, o canvas
 * não é contaminado, e de quebra um SVG de CDN vira um arquivo de poucos
 * kilobytes servido daqui.
 *
 * ── Por que recebe o ID do time, e não a URL ─────────────────────────
 * Um proxy que aceita URL é um buscador de páginas pra quem chamar: o
 * servidor baixaria o que mandassem, inclusive endereços internos. Aqui
 * entra o ID; a URL vem do banco. O pior que alguém consegue pedir é o
 * escudo de um time que já é público.
 *
 * O bloqueio de IP privado abaixo cobre o que sobra: um admin colando sem
 * querer um endereço da rede interna no cadastro do time.
 */

declare(strict_types=1);

require_once __DIR__ . '/backend/db.php';

$teamId = (int) ($_GET['t'] ?? 0);
$lado   = max(24, min(256, (int) ($_GET['s'] ?? 96)));
if ($teamId <= 0) { http_response_code(400); exit; }

$servir = function (string $caminho, string $tipo): void {
    header('Content-Type: ' . $tipo);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . (string) filesize($caminho));
    readfile($caminho);
    exit;
};

try {
    $st = db()->prepare('SELECT photo_url FROM teams WHERE id = ?');
    $st->execute([$teamId]);
    $url = trim((string) ($st->fetchColumn() ?: ''));
} catch (Throwable $e) {
    error_log('[escudo-time] banco: ' . $e->getMessage());
    http_response_code(500); exit;
}

if ($url === '') { http_response_code(404); exit; }

/* Escudo que mora aqui já tem miniaturador. Redirecionar mantém um cache
   só pros dois caminhos e não duplica a conversão. 302 pro mesmo domínio
   não contamina canvas nenhum. */
if (preg_match('~^/img/teams/([A-Za-z0-9._-]+)$~', $url, $m)) {
    header('Location: /games/escudo.php?f=' . rawurlencode($m[1]) . '&s=' . $lado, true, 302);
    exit;
}

if (!preg_match('~^https?://~i', $url)) { http_response_code(404); exit; }

$cacheDir = __DIR__ . '/games/storage/escudos-externos';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$cache = $cacheDir . '/' . $lado . '-' . sha1($url) . '.webp';

// Uma semana: escudo de CDN troca sozinho de vez em quando.
if (is_file($cache) && (time() - filemtime($cache)) < 604800) $servir($cache, 'image/webp');

/** Endereço de rede interna não se busca, nem vindo do nosso próprio banco. */
$privado = static function (string $host): bool {
    $ips = @gethostbynamel($host) ?: [];
    if (!$ips) return true;                       // não resolveu: não busca
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return true;
    }
    return false;
};

$host = (string) parse_url($url, PHP_URL_HOST);
if ($host === '' || $privado($host)) {
    error_log('[escudo-time] host recusado: ' . $host);
    http_response_code(404); exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTP | CURLPROTO_HTTPS,
    // Cliente genérico: nada que identifique a liga ou quem está olhando.
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; image-fetch)',
    CURLOPT_BUFFERSIZE     => 65536,
    CURLOPT_NOPROGRESS     => false,
    // Corta no meio do download se o arquivo for grande demais.
    CURLOPT_PROGRESSFUNCTION => static fn($c, $esperado, $baixado) => $baixado > 8388608 ? 1 : 0,
]);
$bytes  = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!is_string($bytes) || $bytes === '' || $status >= 400) {
    error_log('[escudo-time] #' . $teamId . ' HTTP ' . $status);
    http_response_code(502); exit;
}

try {
    if (!class_exists('Imagick')) { http_response_code(501); exit; }

    /* SVG precisa de fundo transparente DECLARADO antes da leitura: sem
       isso o Imagick rasteriza com fundo branco e o escudo chega num
       quadrado branco por cima da faixa colorida. */
    $im = new Imagick();
    $im->setBackgroundColor(new ImagickPixel('transparent'));
    $im->readImageBlob($bytes);
    $im->setImageFormat('webp');
    $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
    // Cabe inteiro na caixa, sem cortar: escudo cortado perde o símbolo.
    $im->thumbnailImage($lado, $lado, true, true);
    $im->setImageCompressionQuality(85);
    $im->writeImage($cache);
    $im->clear();

    if (is_file($cache)) $servir($cache, 'image/webp');
    http_response_code(500);
} catch (Throwable $e) {
    error_log('[escudo-time] converter #' . $teamId . ': ' . $e->getMessage());
    http_response_code(500);
}
