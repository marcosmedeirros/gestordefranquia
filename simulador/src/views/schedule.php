<?php
require_once dirname(__DIR__) . '/helpers.php';
render_header('Jogos');
$phase  = League::phase();
$cur    = League::currentDay();
$day    = max(1, (int) ($_GET['day'] ?? $cur));
$total  = League::totalDays();
$games  = League::gamesByDay($day);
$gmId   = League::gmTeam();
?>

<div class="card-head page" style="margin-bottom:18px">
  <h1 class="page-title">📅 <?= e(League::dateLabel($day)) ?>
    <span style="font-size:16px;color:var(--muted);font-weight:400"> — Dia <?= $day ?><?= $phase==='regular' ? "/$total" : '' ?><?= $phase==='playoffs' ? ' · Playoffs' : '' ?></span>
  </h1>
  <div class="actions">
    <a class="btn btn-primary" href="<?= url('home', ['action'=>'advance','back'=>url('schedule',['day'=>$cur])]) ?>">▶ Avançar data</a>
    <?php if ($phase === 'regular'): ?>
      <a class="btn" href="<?= url('home', ['action'=>'sim-season']) ?>" onclick="return confirm('Simular toda a temporada regular de uma vez?')">⏭ Simular tudo</a>
    <?php endif; ?>
  </div>
</div>

<div class="day-nav" style="margin-bottom:18px">
  <?php if ($day > 1): ?>
    <a class="btn" href="<?= url('schedule',['day'=>$day-1]) ?>">← Dia <?= $day-1 ?></a>
  <?php else: ?>
    <span></span>
  <?php endif; ?>
  <span class="muted" style="font-size:13px">Dia atual da liga: <strong><?= $cur ?></strong><?= $phase==='regular' ? " / $total" : '' ?></span>
  <a class="btn" href="<?= url('schedule',['day'=>$day+1]) ?>">Dia <?= $day+1 ?> →</a>
</div>

<?php if (!$games): ?>
  <div class="card" style="text-align:center;padding:40px"><p class="muted">Nenhum jogo neste dia.</p></div>
<?php else: ?>
<div class="schedule-list">
  <?php foreach ($games as $g):
    $isGm      = $gmId && ((int)$g['home_id'] === $gmId || (int)$g['away_id'] === $gmId);
    $awayWin   = $g['played'] && $g['away_pts'] > $g['home_pts'];
    $homeWin   = $g['played'] && $g['home_pts'] > $g['away_pts'];
    $stripeCol = $isGm ? ($gmId == $g['home_id'] ? $g['home_color'] : $g['away_color']) : $g['home_color'];
  ?>
  <a class="match-card <?= $g['played']?'played':'' ?> <?= $isGm?'gm-game':'' ?>" href="<?= url('game',['id'=>$g['id']]) ?>">
    <div class="match-stripe" style="background:linear-gradient(90deg,<?= e($g['away_color']) ?>,<?= e($g['home_color']) ?>)"></div>
    <div class="match-body">
      <div class="match-teams">
        <!-- Away -->
        <div class="match-side">
          <img src="<?= e(logo_url($g['away_abbr'])) ?>" class="ms-logo"
               onerror="this.src='';this.style.width='38px';this.style.height='38px'"
               alt="<?= e($g['away_abbr']) ?>">
          <span class="ms-abbr"><?= e($g['away_abbr']) ?></span>
          <?php if ($g['played']): ?>
            <span class="ms-pts <?= $awayWin?'winner':'loser' ?>"><?= $g['away_pts'] ?></span>
          <?php endif; ?>
        </div>

        <!-- VS / @ -->
        <div class="match-vs">
          <?php if (!$g['played']): ?>
            <span class="vs-at">@</span>
            <span style="font-size:10px;color:var(--muted)">Casa: <?= e($g['home_abbr']) ?></span>
          <?php else: ?>
            <span style="font-size:11px;color:var(--muted);font-weight:700">FINAL<?= $g['ot'] ? '<br><span class="ot-tag">PR'.($g['ot']>1?$g['ot']:'').'</span>' : '' ?></span>
          <?php endif; ?>
        </div>

        <!-- Home -->
        <div class="match-side">
          <img src="<?= e(logo_url($g['home_abbr'])) ?>" class="ms-logo" alt="<?= e($g['home_abbr']) ?>">
          <span class="ms-abbr"><?= e($g['home_abbr']) ?></span>
          <?php if ($g['played']): ?>
            <span class="ms-pts <?= $homeWin?'winner':'loser' ?>"><?= $g['home_pts'] ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="match-footer">
      <span class="mf-date"><?= e(League::dateLabel($day)) ?></span>
      <?php if (!$g['played']): ?>
        <span class="mf-status live"><?= $isGm ? '🎮 Seu jogo' : 'Simular ▶' ?></span>
      <?php else: ?>
        <span class="mf-status">Ver box score →</span>
      <?php endif; ?>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php render_footer(); ?>
