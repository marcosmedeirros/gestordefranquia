<?php
/**
 * Serve o headshot da NBA pelo nosso domínio.
 *
 * Existe por causa do canvas: cdn.nba.com não manda Access-Control-Allow-Origin,
 * então uma imagem de lá CONTAMINA o canvas e o toBlob/toDataURL passa a
 * estourar com SecurityError. Carregar com crossOrigin="anonymous" também não
 * resolve — sem o cabeçalho do outro lado, a imagem simplesmente não carrega.
 *
 * Passando por aqui a imagem vira mesma-origem e o cartão do elenco consegue
 * desenhar a foto dos titulares.
 *
 * Só o CDN da NBA é aceito. Um proxy que busca qualquer URL vira ferramenta
 * de varredura da rede interna de quem hospeda — o servidor faria a requisição
 * por quem pedisse, inclusive pra 127.0.0.1 e para a rede da Oracle.
 *
 *   /api/foto-proxy.php?id=1628369
 */
require_once __DIR__ . '/../backend/auth.php';

$user = getUserSession();
if (!$user) { http_response_code(401); exit; }

// Só dígitos: o id entra numa URL, e qualquer outro caractere seria caminho.
$id = preg_replace('/\D+/', '', (string)($_GET['id'] ?? ''));
if ($id === '' || strlen($id) > 12) { http_response_code(400); exit; }

$url = "https://cdn.nba.com/headshots/nba/latest/260x190/{$id}.png";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_FOLLOWLOCATION => false,   // redirecionamento sairia do CDN
    CURLOPT_USERAGENT      => 'FBA Manager',
]);
$corpo = curl_exec($ch);
$http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$tipo  = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($corpo === false || $http !== 200 || !str_starts_with($tipo, 'image/')) {
    http_response_code(404);
    exit;
}

// Uma semana de cache: headshot não muda, e sem isto cada geração de cartão
// faria cinco viagens ao CDN.
header('Content-Type: ' . $tipo);
header('Cache-Control: public, max-age=604800');
header('Content-Length: ' . strlen($corpo));
echo $corpo;
