<?php
/**
 * O jogo virou "The Journey" e mora em thejourney.php.
 *
 * Este arquivo fica porque o link antigo já circulou: ele era o único jeito
 * de chegar no jogo enquanto ele não estava no catálogo, então está em
 * conversa, em print e no favorito de quem jogou. Quebrar isso agora perderia
 * carreiras em andamento — a pessoa abriria um 404 e acharia que o jogo saiu.
 *
 * 301 e não 302: o endereço mudou de vez, e é isso que faz o navegador parar
 * de perguntar por ele.
 */
$destino = 'thejourney.php';
if (!empty($_SERVER['QUERY_STRING'])) {
    $destino .= '?' . $_SERVER['QUERY_STRING'];
}
header('Location: ' . $destino, true, 301);
exit;
