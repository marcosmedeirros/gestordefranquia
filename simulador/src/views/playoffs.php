<?php
require_once dirname(__DIR__) . '/helpers.php';
render_header('Playoffs');
$phase = League::phase();
$bracket = League::playoffBracket();
$roundNames = [1 => 'Primeira Rodada', 2 => 'Semifinais de Conferência', 3 => 'Finais de Conferência', 4 => 'Finais da Liga'];
$byRound = [];
foreach ($bracket as $s) { $byRound[$s['round']][] = $s; }
?>
<div class="card-head page">
  <h1 class="page-title">Playoffs — Temporada <?= League::season() ?></h1>
  <?php if ($phase === 'playoffs'): ?>
    <div class="actions"><a class="btn btn-primary" href="<?= url('home',['action'=>'advance','back'=>url('playoffs')]) ?>">▶ Avançar playoffs</a></div>
  <?php endif; ?>
</div>

<?php if (!$bracket): ?>
  <p class="muted">Os playoffs ainda não começaram. Conclua a temporada regular.</p>
  <?php if ($phase === 'regular'): ?>
    <a class="btn" href="<?= url('home',['action'=>'sim-season']) ?>" onclick="return confirm('Simular toda a temporada regular?')">⏭ Simular temporada e ir aos playoffs</a>
  <?php endif; ?>
<?php else: ?>
  <?php if ($phase === 'offseason' && Database::meta('champion_id')):
        $champ = League::team((int) Database::meta('champion_id')); ?>
    <div class="champion-banner" style="<?= gradient($champ) ?>">🏆 Campeão: <strong><?= e(teamFull($champ)) ?></strong></div>
  <?php endif; ?>

  <div class="bracket">
  <?php foreach ($byRound as $round => $series): ?>
    <div class="round-col">
      <h3 class="round-title"><?= e($roundNames[$round] ?? "Rodada $round") ?></h3>
      <?php foreach ($series as $s):
        $hw = (int)$s['high_wins']; $lw = (int)$s['low_wins'];
        $confLabel = $s['conf'] === 'E' ? 'Leste' : ($s['conf'] === 'W' ? 'Oeste' : 'Final'); ?>
        <div class="series <?= $s['winner_id'] ? 'done' : '' ?>">
          <div class="series-conf"><?= e($confLabel) ?></div>
          <div class="series-team <?= $s['winner_id']==$s['high_seed_id']?'winner':'' ?>">
            <span class="seed"><?= $s['high_seed'] ?></span>
            <a href="<?= url('team',['id'=>$s['high_seed_id']]) ?>"><?= e($s['high_abbr']) ?></a>
            <span class="wins"><?= $hw ?></span>
          </div>
          <div class="series-team <?= $s['winner_id']==$s['low_seed_id']?'winner':'' ?>">
            <span class="seed"><?= $s['low_seed'] ?></span>
            <a href="<?= url('team',['id'=>$s['low_seed_id']]) ?>"><?= e($s['low_abbr']) ?></a>
            <span class="wins"><?= $lw ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php render_footer(); ?>
