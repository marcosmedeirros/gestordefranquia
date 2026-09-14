<?php
require_once dirname(__DIR__) . '/helpers.php';

$phase = League::phase();
$cur   = League::currentDay();
$total = League::totalDays();
$last  = max($total, $cur);
$day   = max(1, min($last, (int) ($_GET['day'] ?? $cur)));
$gmId  = (int) League::gmTeam();
$games = League::gamesByDay($day);
$count = count($games);
$done  = count(array_filter($games, fn($g) => !empty($g['played'])));

// O seu jogo da data vai para o destaque; os outros ficam na grade
$mine = null;
foreach ($games as $i => $g) {
    if ($gmId && ((int) $g['home_id'] === $gmId || (int) $g['away_id'] === $gmId)) { $mine = $g; unset($games[$i]); break; }
}
$first     = $mine ?? ($games ? reset($games) : null);
$stage     = (string) ($first['stage'] ?? '');
$stageName = ['regular' => 'Temporada regular', 'playin' => 'Play-in', 'playoffs' => 'Playoffs'][$stage] ?? '';

// Faixa de datas: sete no computador, cinco no celular (as demais levam .far)
$from  = max(1, min($day - 3, $last - 6));
$to    = min($last, $from + 6);
$mFrom = max(1, min($day - 2, $last - 4));
$mTo   = min($last, $mFrom + 4);
$parts = function (int $d): array {
    // "Sáb, 25 Out" → ['Sáb', '25', 'Out']
    [$dow, $rest] = array_pad(explode(', ', League::dateLabel($d), 2), 2, '');
    [$dd, $mon] = array_pad(explode(' ', $rest, 2), 2, '');
    return [$dow, $dd, $mon];
};

$meta = [];
if ($stage === 'regular' && $total) $meta[] = "Dia $day de $total";
elseif ($stageName !== '') $meta[] = $stageName;
if ($count) {
    $meta[] = $count . ($count === 1 ? ' jogo' : ' jogos')
        . ($done === $count ? ($count === 1 ? ' encerrado' : ' encerrados') : ($done ? " · $done encerrados" : ''));
}

render_header('Jogos');
page_head('Jogos', [
    'eyebrow' => 'Liga · ' . phase_label($phase),
    'sub' => 'Resultados e jogos marcados, data por data. Toque num jogo para ver o box score ou a prévia.',
]);
?>
<div class="stack">
  <nav class="sch-nav" aria-label="Escolher a data">
    <a class="btn btn-icon sch-arrow<?= $day <= 1 ? ' is-off' : '' ?>" href="<?= url('schedule', ['day' => max(1, $day - 1)]) ?>" aria-label="Data anterior"><?= bi('chevron-left') ?></a>
    <div class="sch-days">
      <?php for ($d = $from; $d <= $to; $d++):
        [$dow, $dd, $mon] = $parts($d);
        $cls = 'sch-day' . ($d === $day ? ' on' : '') . ($d === $cur ? ' today' : '') . ($d < $mFrom || $d > $mTo ? ' far' : ''); ?>
        <a class="<?= $cls ?>" href="<?= url('schedule', ['day' => $d]) ?>"<?= $d === $day ? ' aria-current="date"' : '' ?>>
          <small><?= e($d === $cur ? 'Hoje' : $dow) ?></small><b><?= e($dd) ?></b><small><?= e($mon) ?></small>
        </a>
      <?php endfor; ?>
    </div>
    <a class="btn btn-icon sch-arrow<?= $day >= $last ? ' is-off' : '' ?>" href="<?= url('schedule', ['day' => min($last, $day + 1)]) ?>" aria-label="Próxima data"><?= bi('chevron-right') ?></a>
  </nav>

  <section class="panel">
    <?= panel_head(League::dateLabel($day), [
        'icon' => 'calendar3',
        'meta' => implode(' · ', $meta),
        'right' => $day === $cur ? chip('Hoje', 'info') : '<a class="btn" href="' . url('schedule') . '">' . bi('arrow-counterclockwise') . 'Voltar para hoje</a>',
    ]) ?>

    <?php if ($mine):
      $played  = !empty($mine['played']);
      $awayWon = $played && (int) $mine['away_pts'] > (int) $mine['home_pts'];
      $won     = $played && (((int) $mine['home_id'] === $gmId) !== $awayWon);
      $isToday = $day === $cur;
      $ot      = (int) ($mine['ot'] ?? 0);
      $href    = url('game', ['id' => $mine['id']] + (!$played && $isToday ? ['live' => 1] : []));
      $series  = !empty($mine['series_id']) ? League::seriesStatus((int) $mine['series_id']) : null;
      $context = $stageName;
      if ($series) {
          $context = $series['round_name'];
          if ($isToday && !$played) $context .= ' · Jogo ' . $series['game'] . ($series['high_wins'] + $series['low_wins'] ? ', ' . $series['lead'] : '');
          elseif ($isToday) $context .= ' · ' . $series['lead'];
      }
      $status  = $played ? chip($won ? 'Vitória' : 'Derrota', $won ? 'ok' : 'bad') : chip($isToday ? 'Hoje' : 'Marcado', $isToday ? 'team' : '', 'clock');
      $cta     = $played ? 'Ver box score' : ($isToday ? 'Comandar ao vivo' : 'Ver prévia'); ?>
      <a class="sch-mine" href="<?= $href ?>">
        <div class="sch-mine-top"><span class="label">Seu jogo</span><?= $status ?></div>
        <div class="matchup">
          <div class="mu-side<?= $played && !$awayWon ? ' lost' : '' ?>">
            <?= team_logo((string) $mine['away_abbr'], (string) ($mine['away_color'] ?? '#333'), 'lg') ?>
            <b><?= e($mine['away_abbr']) ?></b>
            <?= $played ? '<span class="mu-score">' . (int) $mine['away_pts'] . '</span>' : '<small>Visitante</small>' ?>
          </div>
          <div class="mu-mid"><?= $played ? 'Final' . ($ot ? '<br>PR' . ($ot > 1 ? $ot : '') : '') : '@' ?></div>
          <div class="mu-side<?= $played && $awayWon ? ' lost' : '' ?>">
            <?= team_logo((string) $mine['home_abbr'], (string) ($mine['home_color'] ?? '#333'), 'lg') ?>
            <b><?= e($mine['home_abbr']) ?></b>
            <?= $played ? '<span class="mu-score">' . (int) $mine['home_pts'] . '</span>' : '<small>Mandante</small>' ?>
          </div>
        </div>
        <div class="sch-mine-foot">
          <span><?= e($context) ?></span>
          <b><?= e($cta) ?><?= bi('chevron-right') ?></b>
        </div>
      </a>
    <?php endif; ?>

    <?php if ($games): ?>
      <?php if ($mine): ?><div class="sub-h">Outros jogos</div><?php endif; ?>
      <div class="games">
        <?php foreach ($games as $g) echo game_tile($g, $gmId); ?>
      </div>
    <?php elseif (!$mine): ?>
      <?= empty_state('Nenhum jogo nesta data.', $day > $cur ? 'Os jogos desta data ainda não foram marcados.' : 'A liga não teve jogos neste dia.', 'calendar-x') ?>
    <?php endif; ?>
  </section>
</div>
<?php render_footer(); ?>
