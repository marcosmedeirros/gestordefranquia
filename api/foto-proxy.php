<?php
/**
 * Serve imagem de CDN externo pelo nosso domínio.
 *
 * Existe por causa do canvas: esses CDNs não mandam Access-Control-Allow-Origin,
 * então uma imagem de lá CONTAMINA o canvas e o toBlob/toDataURL passa a
 * estourar com SecurityError. Carregar com crossOrigin="anonymous" também não
 * resolve — sem o cabeçalho do outro lado, a imagem simplesmente não carrega.
 *
 * Passando por aqui a imagem vira mesma-origem, e aí o cartão consegue
 * desenhar foto de jogador e escudo de clube.
 *
 * ── Por que não aceita URL inteira ────────────────────────────────────
 *
 * Um proxy que busca qualquer URL vira ferramenta de varredura da rede interna
 * de quem hospeda: o servidor faria a requisição por quem pedisse, inclusive
 * pra 127.0.0.1 e pra rede da Oracle. Aqui o chamador manda só um IDENTIFICADOR
 * curto, e a URL é montada deste lado, a partir de uma lista fechada de fontes.
 *
 *   /api/foto-proxy.php?id=1628369                          jogador da NBA
 *   /api/foto-proxy.php?f=sdb&p=team/badge/vsqwqp1473.png    escudo (thesportsdb)
 *   /api/foto-proxy.php?f=espn&p=ncaa/500/2294.png           logo universitário
 */
require_once __DIR__ . '/../backend/auth.php';

/**
 * As fontes aceitas: apelido => [base, regra do caminho].
 *
 * A regra é propositalmente apertada. Sem `..`, sem barra dupla, sem query —
 * só o formato que aquele CDN realmente usa. Qualquer coisa fora disso é
 * recusada antes de virar requisição.
 */
const PROXY_FONTES = [
    'sdb'  => ['https://r2.thesportsdb.com/images/media/',
               '#^[a-z]+/[a-z]+/[a-zA-Z0-9_-]+\.(png|jpg|jpeg|webp)$#'],
    'espn' => ['https://a.espncdn.com/i/teamlogos/',
               '#^[a-z]+/[0-9]+/[0-9]+\.(png|jpg)$#'],
    // O escudo dos times da NBA. O caminho é fixo — só o id do time varia —
    // e é o que o cartão em imagem precisa: desenhar SVG de outro domínio no
    // canvas contamina a tela e derruba a imagem inteira.
    'nbat' => ['https://cdn.nba.com/logos/nba/',
               '#^[0-9]{4,12}/global/L/logo\.svg$#'],
];

$url = null;

// Formato antigo, que a tela do elenco usa: só o id do jogador. Este segue
// pedindo sessão, porque quem usa está logado de qualquer jeito.
$id = preg_replace('/\D+/', '', (string)($_GET['id'] ?? ''));
if ($id !== '' && strlen($id) <= 12) {
    if (!getUserSession()) { http_response_code(401); exit; }
    $url = "https://cdn.nba.com/headshots/nba/latest/260x190/{$id}.png";
} else {
    // As fontes por apelido são ABERTAS: os games rodam sem login, e o cartão
    // de compartilhar precisa dos escudos pra existir. Abrir aqui não abre
    // nada de rede interna — o chamador não escolhe o destino, só um caminho
    // dentro de uma base fixa, e o que não casa com a regra é recusado com
    // 400 antes de virar requisição.
    $fonte   = (string)($_GET['f'] ?? '');
    $caminho = (string)($_GET['p'] ?? '');
    if (isset(PROXY_FONTES[$fonte]) && $caminho !== '' && strlen($caminho) <= 160) {
        [$base, $regra] = PROXY_FONTES[$fonte];
        // str_contains do '..' é redundante com a regra, mas é a checagem que
        // alguém lê primeiro ao auditar isto.
        if (!str_contains($caminho, '..') && preg_match($regra, $caminho)) {
            $url = $base . $caminho;
        }
    }
}

if ($url === null) { http_response_code(400); exit; }

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_FOLLOWLOCATION => false,   // redirecionamento sairia da fonte aceita
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

// Uma semana de cache: escudo e headshot não mudam, e sem isto cada geração
// de cartão faria uma dezena de viagens ao CDN.
header('Content-Type: ' . $tipo);
header('Cache-Control: public, max-age=604800');
header('Content-Length: ' . strlen($corpo));
echo $corpo;
