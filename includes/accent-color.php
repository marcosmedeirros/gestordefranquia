<?php
/**
 * Sobrescreve --red com a cor escolhida pelo usuário, se houver.
 * Como todos os tons derivados (--red-2, --red-soft, --red-glow,
 * --border-red, --fba-brand etc.) são calculados a partir de --red via
 * color-mix()/var(), sobrescrever só essa variável já recolore o app inteiro.
 * Uso: inserir DENTRO de um bloco <style> já aberto (sem tags <style> próprias),
 * ex: logo antes do </style> de cada página.
 */
if (!empty($user['accent_color']) && function_exists('isValidAccentColor') && isValidAccentColor($user['accent_color'])):
?>
:root{--red:<?= htmlspecialchars($user['accent_color']) ?> !important}
<?php endif; ?>
