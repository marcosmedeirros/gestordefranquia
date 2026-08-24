<?php
/**
 * Include para head com meta tags PWA e responsivas
 * Usar: <?php include __DIR__ . '/includes/head-pwa.php'; ?>
 */
?>
  <!-- PWA Meta Tags -->
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
  <meta name="theme-color" content="#000000">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="FBA Manager">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="format-detection" content="telephone=no">
  <meta name="msapplication-TileColor" content="#000000">
  <meta name="msapplication-tap-highlight" content="no">
  <meta name="msapplication-TileImage" content="/img/icons/icon-144.png?v=6">
  
  <!-- PWA Manifest -->
  <link rel="manifest" href="/manifest.json?v=6">
  
  <!-- Favicon -->
  <link rel="icon" type="image/png" sizes="16x16" href="/img/icons/icon-16.png?v=6">
  <link rel="icon" type="image/png" sizes="32x32" href="/img/icons/icon-32.png?v=6">
  <link rel="icon" type="image/png" sizes="48x48" href="/img/icons/icon-48.png?v=6">
  <link rel="icon" type="image/png" sizes="96x96" href="/img/icons/icon-96.png?v=6">
  
  <!-- Apple Touch Icons -->
  <link rel="apple-touch-icon" sizes="180x180" href="/img/icons/icon-180.png?v=6">
  
  <!-- Apple Splash Screens removidos para usar plano de fundo do app -->

  <!--
    Os popups do site no lugar dos do navegador.

    Entra AQUI, no head compartilhado, e não em cada página: são mais de 400
    chamadas de alert() espalhadas por dezenas de arquivos, e o substituto só
    vale se estiver carregado ANTES da primeira delas rodar.

    Sem defer de propósito. Com defer ele só executaria depois do HTML todo,
    e um alert disparado por script inline no meio da página pegaria ainda o
    do navegador.
  -->
  <script src="/js/popups.js?v=<?= @filemtime(dirname(__DIR__) . '/js/popups.js') ?: 1 ?>"></script>
