<?php require_once dirname(__DIR__) . '/helpers.php'; render_header('Instalar'); ?>
<div class="hero">
  <h1>🏀 NBA Sim</h1>
  <p>Simulador de temporada no estilo 2K MyLeague: 30 times reais, elencos da NBA,
     classificação, líderes de estatística, playoffs e <strong>simcast ao vivo</strong> dos jogos.</p>
  <p>Clique abaixo para criar o banco de dados, montar os elencos e gerar o calendário da temporada.</p>
  <a class="btn btn-primary btn-lg" href="<?= url('home', ['action' => 'install']) ?>">Iniciar nova liga</a>
  <p class="muted" style="margin-top:18px">Isso recria o banco do zero (storage/game.sqlite).</p>
</div>
<?php render_footer(); ?>
