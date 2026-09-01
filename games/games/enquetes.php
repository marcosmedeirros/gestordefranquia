<?php
/**
 * As enquetes viraram a aba "Banca" do /games — o conteúdo mora em
 * games/core/enquetes_painel.php.
 *
 * Este arquivo fica porque o endereço circulou enquanto era página.
 */
require __DIR__ . '/../core/conexao.php';

$idUsuario = (int)($_SESSION['user_id'] ?? 0);
if ($idUsuario <= 0) { header('Location: /login.php'); exit; }

header('Location: /games.php?aba=banca', true, 302);
exit;
