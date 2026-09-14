<?php
require_once dirname(__DIR__) . '/helpers.php';

$since  = (int) ($_GET['since'] ?? 0);
$label  = trim((string) ($_GET['label'] ?? ''));
$events = $since > 0 ? League::inboxSince($since) : [];
$phase  = League::phase();
$gmId   = (int) League::gmTeam();

// Resultados da última data com jogos (temporada, play-in e playoffs)
$results = [];
$lastDay = 0;
if (in_array($phase, ['regular', 'playin', 'playoffs'], true)) {
    $lastDay = (int) Database::conn()->query("SELECT COALESCE(MAX(day),0) FROM games WHERE played=1")->fetchColumn();
    if ($lastDay > 0) $results = array_values(array_filter(League::gamesByDay($lastDay), fn($g) => !empty($g['played'])));
}
$mine = null;
foreach ($results as $i => $g) {
    if ((int) $g['home_id'] === $gmId || (int) $g['away_id'] === $gmId) { $mine = $g; unset($results[$i]); break; }
}

render_header('Resumo');
page_head($label !== '' ? $label : 'Resumo', [
    'eyebrow' => 'O que aconteceu',
    'sub' => $events
        ? (count($events) === 1 ? 'Um acontecimento' : count($events) . ' acontecimentos') . ' desde a sua última jogada.'
        : 'Nada de novo envolvendo o seu time desta vez.',
]);
?>
<div class="grid cols-main">
  <div class="stack">
    <?php if ($mine):
      $won = ((int) $mine['home_id'] === $gmId) === ((int) $mine['home_pts'] > (int) $mine['away_pts']);
      $awayWon = (int) $mine['away_pts'] > (int) $mine['home_pts']; ?>
    <a class="panel hot" href="<?= url('game', ['id' => $mine['id']]) ?>">
      <?= panel_head('Seu jogo', ['icon' => 'play-circle-fill', 'right' => chip($won ? 'Vitória' : 'Derrota', $won ? 'ok' : 'bad')]) ?>
      <div class="matchup">
        <div class="mu-side <?= $awayWon ? '' : 'lost' ?>"><?= team_logo($mine['away_abbr'], '#333', 'lg') ?><b><?= e($mine['away_abbr']) ?></b><span class="mu-score"><?= (int) $mine['away_pts'] ?></span></div>
        <div class="mu-mid">Final<?= !empty($mine['ot']) ? '<br>PR' : '' ?></div>
        <div class="mu-side <?= $awayWon ? 'lost' : '' ?>"><?= team_logo($mine['home_abbr'], '#333', 'lg') ?><b><?= e($mine['home_abbr']) ?></b><span class="mu-score"><?= (int) $mine['home_pts'] ?></span></div>
      </div>
    </a>
    <?php endif; ?>

    <section class="panel">
      <?= panel_head('Acontecimentos', ['icon' => 'lightning-charge-fill', 'more' => ['Caixa de entrada', url('inbox')]]) ?>
      <?php if ($events): ?>
        <div class="feed">
          <?php foreach ($events as $m) render_inbox_msg($m, 'home'); ?>
        </div>
      <?php else: ?>
        <?= empty_state('Sem novidades.', 'A liga seguiu sem nada que envolva o seu time.', 'moon-stars') ?>
      <?php endif; ?>
    </section>
  </div>

  <div class="stack">
    <?php if ($results): ?>
    <section class="panel">
      <?= panel_head('Outros resultados', ['icon' => 'calendar3', 'meta' => League::dateLabel($lastDay)]) ?>
      <div class="games">
        <?php foreach ($results as $g) echo game_tile($g, $gmId); ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php render_footer(); ?>
