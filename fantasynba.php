<?php
/**
 * ── O ENDEREÇO ANTIGO ────────────────────────────────────────────────
 *
 * O jogo se chamava "Fantasy NBA" e virou escaleAi em 20/10/2026. Como ele
 * vive só por link — não tem card no hub — o endereço velho já estava na
 * mão dos GMs, e sumir com ele quebraria o que eles salvaram. Então fica
 * aqui, sem tela nenhuma, só apontando pro novo com os parâmetros
 * intactos: quem abrir ?aba=ranking cai no ranking, não na home.
 *
 * @see escaleai.php
 */
$q = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: /escaleai.php' . ($q !== '' ? '?' . $q : ''), true, 301);
exit;
