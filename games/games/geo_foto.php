<?php
/**
 * ── A FOTO DA RODADA, SEM O NOME DELA ────────────────────────────────
 *
 * A tela apontava direto pro Wikimedia Commons, e o nome do arquivo lá é o
 * nome do lugar: "Budapest, Fischer üzlet- és bérház, kilátás, 2.jpg". Ou
 * seja, a resposta estava escrita no código-fonte da página — bastava olhar,
 * ou passar o mouse na imagem, ou abri-la em outra aba. Num jogo de adivinhar
 * onde é, isso não é vazamento pequeno: é o gabarito.
 *
 * Aqui o endereço é só o número da rodada. O servidor confere que aquela
 * rodada é de quem está pedindo, busca a foto no Commons e devolve os bytes.
 * Nem o nome do arquivo nem o domínio do Commons aparecem pro navegador.
 *
 * REDIRECIONAR NÃO RESOLVERIA: um 302 pro Commons põe a URL verdadeira na
 * aba de rede do navegador, e quem quisesse colar continuaria colando.
 *
 * ── O CACHE NÃO É LUXO ───────────────────────────────────────────────
 *
 * Sem ele, cada rodada de cada pessoa seria uma baixada nova do Commons —
 * lento pra quem joga e deselegante com quem hospeda de graça. O arquivo
 * fica em games/data/cache/geo/, com nome derivado da foto.
 */

require_once __DIR__ . '/../core/conexao.php';     // login do site + $pdo
require_once __DIR__ . '/../core/geo.php';

const GEO_FOTO_LARGURA = 1100;
const GEO_FOTO_CACHE_DIAS = 60;

/** Sai com um pixel transparente, pra tela não ficar com ícone quebrado. */
function geoFotoVazia(int $codigo): void
{
    http_response_code($codigo);
    header('Content-Type: image/gif');
    header('Cache-Control: no-store');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

$uid = (int)($_SESSION['user_id'] ?? 0);
$rid = (int)($_GET['r'] ?? 0);
if ($uid <= 0 || $rid <= 0) geoFotoVazia(400);

/* A RODADA TEM QUE SER DESTA PESSOA. Sem isso, bastaria varrer os números
   pra ver a foto da rodada do vizinho — e, num duelo futuro, a dele. */
$st = $pdo->prepare('SELECT r.lugar FROM geo_rodadas r
                       JOIN geo_partidas p ON p.id = r.partida_id
                      WHERE r.id = ? AND p.user_id = ? LIMIT 1');
$st->execute([$rid, $uid]);
$indice = $st->fetchColumn();
if ($indice === false) geoFotoVazia(404);

$lugar = geoLugares()[(int)$indice] ?? null;
if (!$lugar || empty($lugar['img'])) geoFotoVazia(404);

$arquivo = (string)$lugar['img'];
$ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
$tipo = ['png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'image/jpeg';

$pasta = __DIR__ . '/../data/cache/geo';
if (!is_dir($pasta)) @mkdir($pasta, 0775, true);
$cache = $pasta . '/' . md5($arquivo . '|' . GEO_FOTO_LARGURA) . '.bin';

$bytes = null;
if (is_file($cache) && filemtime($cache) > time() - GEO_FOTO_CACHE_DIAS * 86400) {
    $bytes = (string)file_get_contents($cache);
}

if ($bytes === null || $bytes === '') {
    $c = curl_init(geoFoto($arquivo, GEO_FOTO_LARGURA));
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['User-Agent: FBA Manager (liga amadora; jogo de lugares)'],
    ]);
    /* ── QUANDO O PHP NÃO TEM OS CERTIFICADOS ────────────────────────
       O servidor tem; o PHP da máquina de desenvolvimento não, e lá toda
       chamada HTTPS morre com "unable to get local issuer certificate".
       Em vez de apontar pra um arquivo que só existe numa máquina, pede-se
       ao sistema operacional os certificados que ele já confia — o mesmo
       conjunto que o navegador usa. A verificação continua ligada; o que
       muda é de onde vem a lista. */
    if (defined('CURLSSLOPT_NATIVE_CA') && trim((string)ini_get('curl.cainfo')) === '') {
        curl_setopt($c, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $corpo = curl_exec($c);
    $codigo = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    if ($codigo !== 200 || !$corpo) {
        error_log('[geo] foto ' . $codigo . ': ' . $arquivo);
        geoFotoVazia(502);
    }
    $bytes = (string)$corpo;
    @file_put_contents($cache, $bytes);
}

/* O navegador pode guardar: a foto não muda, e a rodada é de quem pediu.
   "private" porque o endereço depende da sessão — proxy nenhum no caminho
   deve servir esta resposta pra outra pessoa. */
header('Content-Type: ' . $tipo);
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, max-age=86400');
echo $bytes;
