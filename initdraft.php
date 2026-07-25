<?php
/**
 * Página do Draft Inicial unificada.
 * O antigo painel de admin e a sala de seleção foram fundidos em
 * initdraftselecao.php (a sala mostra o Painel do Admin em abas para admins).
 * Mantemos este arquivo como redirecionamento para não quebrar links/bookmarks.
 */
$token = $_GET['token'] ?? '';
$dest = 'initdraftselecao.php' . ($token !== '' ? ('?token=' . urlencode($token)) : '');
header('Location: ' . $dest, true, 302);
exit;
