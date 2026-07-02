<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once __DIR__ . '/_standings_table.php';
render_header('Classificação');
$east = League::standings('E');
$west = League::standings('W');
?>
<h1 class="page-title">Classificação</h1>
<p class="legend"><span class="key po"></span> Vaga direta nos playoffs (1–8) &nbsp; <span class="key pi"></span> Play-in (9–10)</p>
<div class="dashboard">
  <section class="card">
    <div class="card-head"><h2>Conferência Leste</h2></div>
    <?php renderStandings($east); ?>
  </section>
  <section class="card">
    <div class="card-head"><h2>Conferência Oeste</h2></div>
    <?php renderStandings($west); ?>
  </section>
</div>
<?php render_footer(); ?>
