<?php
/**
 * Catálogo antigo do games. Depois da fusão quem manda é o /games.php do
 * site, que traz a lista enxuta de jogos e as apostas nas duas abas — esta
 * página listava jogos que saíram do ar, então redireciona pra lá.
 */
header('Location: /games.php', true, 301);
exit;
