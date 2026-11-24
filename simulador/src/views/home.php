<?php
require_once dirname(__DIR__) . '/helpers.php';

$phase  = League::phase();
$day    = League::currentDay();
$season = League::season();
$gmId   = (int) League::gmTeam();
$gm     = $gmId ? League::team($gmId) : null;
$fired  = $gmId && League::isFired();
$active = in_array($phase, ['regular', 'playin', 'playoffs'], true);

// Jogo de hoje ou próximo jogo do GM
$gmToday = null;
if ($gmId && $active) {
    $gg = League::gmGameOnDay($day);
    if ($gg && empty($gg['played'])) $gmToday = $gg;
}
$nextGame = ($gmId && $active && !$gmToday) ? (League::upcomingGames($gmId, 1)[0] ?? null) : null;

// Forma recente (5 jogos) e sequência
$form = [];
$streak = '';
if ($gmId) {
    $st = Database::conn()->prepare(
        "SELECT CASE WHEN home_id=:t THEN home_pts ELSE away_pts END AS my,
                CASE WHEN home_id=:t THEN away_pts ELSE home_pts END AS op
         FROM games WHERE (home_id=:t OR away_id=:t) AND played=1 ORDER BY day DESC LIMIT 5");
    $st->execute([':t' => $gmId]);
    $last = $st->fetchAll();
    foreach (array_reverse($last) as $g) $form[] = (int) $g['my'] > (int) $g['op'];
    if ($last) {
        $w0 = (int) $last[0]['my'] > (int) $last[0]['op'];
        $n = 0;
        foreach ($last as $g) { if (((int) $g['my'] > (int) $g['op']) === $w0) $n++; else break; }
        $streak = ($w0 ? 'V' : 'D') . $n;
    }
}

$seed = null;
$confRows = [];
if ($gm) {
    $confRows = League::standings($gm['conf']);
    foreach ($confRows as $r) { if ((int) $r['id'] === $gmId) { $seed = (int) $r['seed']; break; } }
}
$goal      = $gmId ? League::boardGoalProgress() : null;
$pat       = $gmId ? League::boardPatience() : 0;
$oc        = $gmId ? League::ownerConfidence() : null;
$cap       = $gmId ? Cap::summary($gmId) : null;
$decisions = $gmId ? League::pendingDecisions() : [];
$inbox     = $gmId ? League::inboxList(8) : [];
$todays    = ($phase === 'offseason') ? [] : League::gamesByDay($day);
$headlines = League::headlines(6);
$champ     = ($phase === 'offseason' && Database::meta('champion_id')) ? League::team((int) Database::meta('champion_id')) : null;
$race      = ($phase === 'regular' && $day >= 10) ? League::awardRace() : null;

// Destaques do elenco: pontos por jogo; antes da estreia, os maiores OVR
$top = [];
if ($gmId) {
    $roster = League::roster($gmId);
    $played = array_values(array_filter($roster, fn($p) => (int) ($p['gp'] ?? 0) > 0));
    if ($played) {
        usort($played, fn($a, $b) => ($b['s_pts'] / $b['gp']) <=> ($a['s_pts'] / $a['gp']));
        $top = array_slice($played, 0, 5);
    } else {
        usort($roster, fn($a, $b) => (int) $b['ovr'] <=> (int) $a['ovr']);
        $top = array_slice($roster, 0, 5);
    }
}

$confName = $gm ? ($gm['conf'] === 'E' ? 'Leste' : 'Oeste') : '';
$goalTone = ['andamento' => 'info', 'cumprida' => 'ok', 'falhou' => 'bad'];
$goalText = ['andamento' => 'em andamento', 'cumprida' => 'cumprida', 'falhou' => 'não cumprida'];

render_header('Central');
?>

<?php if ($fired): ?>
<section class="panel alert home-banner">
  <span class="eyebrow">Fim da linha</span>
  <h1>A diretoria do <?= e(teamFull($gm)) ?> te dispensou</h1>
  <p class="muted">A paciência acabou depois de temporadas abaixo da meta. Escolha outra franquia para seguir a carreira.</p>
</section>
<?php endif; ?>

<?php if ($champ): ?>
<section class="panel hot home-banner home-champ">
  <?= team_logo($champ['abbr'], $champ['primary_color'] ?? '#333', 'xl') ?>
  <div>
    <span class="eyebrow">Campeão da temporada <?= $season ?></span>
    <h1><?= e(teamFull($champ)) ?></h1>
  </div>
</section>
<?php endif; ?>

<?php if ($gm): ?>
<section class="home-hero">
  <div class="hh-id">
    <?= team_logo($gm['abbr'], $gm['primary_color'] ?? '#333', 'xl') ?>
    <div class="hh-id-txt">
      <span class="eyebrow"><?= e(Database::meta('era_name') ?: 'Era atual') ?> · Temporada <?= $season ?></span>
      <h1><?= e(teamFull($gm)) ?></h1>
      <div class="row hh-meta">
        <span class="hh-rec" title="Campanha"><?= (int) $gm['wins'] ?>-<?= (int) $gm['losses'] ?></span>
        <?php if ($seed && (int) $gm['wins'] + (int) $gm['losses'] > 0): ?><?= chip($seed . 'º no ' . $confName, $seed <= 6 ? 'ok' : ($seed <= 10 ? 'warn' : 'bad')) ?><?php endif; ?>
        <?php if ($streak): ?><?= chip('Sequência ' . $streak, $streak[0] === 'V' ? 'ok' : 'bad') ?><?php endif; ?>
        <?= $form ? form_boxes($form) : '' ?>
      </div>
    </div>
  </div>

  <div class="hh-side">
  <?php if ($gmToday || $nextGame):
      if ($gmToday) {
          $awayAbbr = $gmToday['away_abbr']; $homeAbbr = $gmToday['home_abbr'];
          $when = 'Hoje · ' . League::dateLabel($day);
          $href = url('game', ['id' => $gmToday['id'], 'live' => 1]);
          $series = $phase === 'playoffs' ? League::seriesStatus((int) ($gmToday['series_id'] ?? 0)) : null;
      } else {
          $opp = $nextGame['opp_abbr'];
          $awayAbbr = !empty($nextGame['is_home']) ? $opp : $gm['abbr'];
          $homeAbbr = !empty($nextGame['is_home']) ? $gm['abbr'] : $opp;
          $when = League::dateLabel((int) $nextGame['day']);
          $href = url('game', ['id' => $nextGame['id']]);
          $series = null;
      } ?>
    <a class="hh-card" href="<?= $href ?>">
      <span class="hh-label"><?= $gmToday ? 'Jogo de hoje' : 'Próximo jogo' ?></span>
      <div class="matchup">
        <div class="mu-side"><?= team_logo($awayAbbr, '#333', 'lg') ?><b><?= e($awayAbbr) ?></b><small>Visitante</small></div>
        <div class="mu-mid">@</div>
        <div class="mu-side"><?= team_logo($homeAbbr, '#333', 'lg') ?><b><?= e($homeAbbr) ?></b><small>Mandante</small></div>
      </div>
      <span class="hh-when"><?= e($when) ?><?= $series ? ' · ' . e($series['round_name'] . ', ' . $series['lead']) : '' ?></span>
    </a>
  <?php elseif ($phase === 'preseason'):
      $pd = League::preseasonDay(); $tot = League::PRESEASON_DAYS; ?>
    <div class="hh-card">
      <span class="hh-label">Pré-temporada</span>
      <b class="hh-big">Dia <?= $pd ?><small> de <?= $tot ?></small></b>
      <?= meter($pd / $tot * 100, 'go') ?>
      <p class="muted">Trocas, contratações e notícias acontecem a cada dia até a estreia.</p>
    </div>
  <?php else:
      $pc = [
          'lottery'    => ['dice-5-fill', 'Loteria do draft', 'O sorteio define quem escolhe primeiro na nova classe.'],
          'draft'      => ['mortarboard-fill', 'Draft em andamento', 'Os calouros da nova classe estão chegando aos times.'],
          'freeagency' => ['person-plus-fill', 'Free agency', 'Jogadores sem contrato esperam proposta antes da estreia.'],
          'offseason'  => ['trophy-fill', 'Entressafra', 'Hora de rever a temporada e planejar a próxima.'],
          'playoffs'   => ['trophy-fill', 'Playoffs', 'Seu time não joga nesta data.'],
          'playin'     => ['trophy-fill', 'Play-in', 'Seu time não joga nesta data.'],
          'regular'    => ['calendar-check', 'Temporada regular', 'Seu time não tem mais jogos marcados.'],
      ][$phase] ?? ['calendar3', phase_label($phase), '']; ?>
    <div class="hh-card">
      <span class="hh-label"><?= e($pc[1]) ?></span>
      <i class="bi bi-<?= e($pc[0]) ?> hh-ic" aria-hidden="true"></i>
      <p class="muted"><?= e($pc[2]) ?></p>
    </div>
  <?php endif; ?>
  </div>
</section>

<div class="home-status">
  <a class="hs-card" href="<?= url('manage') ?>">
    <span class="label">Meta da diretoria</span>
    <?php if ($goal): ?>
      <span class="hs-title"><?= e($goal['desc']) ?></span>
      <span class="row"><?= chip($goalText[$goal['status']] ?? $goal['status'], $goalTone[$goal['status']] ?? '') ?><span class="hs-line"><?= e($goal['detail']) ?></span></span>
    <?php else: ?>
      <span class="hs-line">Sem meta definida.</span>
    <?php endif; ?>
  </a>
  <a class="hs-card" href="<?= url('manage') ?>">
    <span class="label">Paciência da diretoria</span>
    <span class="row hs-pat"><?= pips($pat, League::PATIENCE_MAX) ?><?php if ($pat <= 1): ?><?= chip('Última chance', 'bad') ?><?php endif; ?></span>
    <?php if ($oc): ?>
      <span class="hs-line">Confiança do dono: <b class="strong"><?= e($oc['label']) ?></b> · <?= (int) $oc['value'] ?>%</span>
      <?= meter((float) $oc['value'], $oc['value'] >= 45 ? 'ok' : ($oc['value'] >= 30 ? 'warn' : 'bad')) ?>
    <?php endif; ?>
  </a>
  <?php if ($cap):
      $capPct = $cap['cap_max'] > 0 ? $cap['payroll'] / $cap['cap_max'] * 100 : 0;
      $capTone = $cap['status'] === 'over' ? 'bad' : ($cap['status'] === 'under' ? 'warn' : 'ok'); ?>
  <a class="hs-card" href="<?= url('cap') ?>">
    <span class="label">Folha salarial</span>
    <span class="hs-big"><?= Cap::m((int) $cap['payroll']) ?><small> / <?= Cap::m((int) $cap['cap_max']) ?></small></span>
    <?= meter($capPct, $capTone) ?>
    <span class="hs-line <?= $capTone === 'ok' ? '' : ($capTone === 'bad' ? 'neg' : 'warn-txt') ?>">
      <?= $cap['status'] === 'over' ? Cap::m((int) ($cap['excess'] ?? 0)) . ' acima do teto'
        : ($cap['status'] === 'under' ? Cap::m((int) ($cap['deficit'] ?? 0)) . ' abaixo do piso' : Cap::m((int) $cap['space']) . ' de espaço no teto') ?>
      <?php if ($phase === 'regular' && $day <= Cap::deadlineDay()): ?> · deadline no dia <?= Cap::deadlineDay() ?><?php endif; ?>
    </span>
  </a>
  <?php endif; ?>
</div>
<?php endif; /* $gm */ ?>

<div class="grid cols-main home-cols">
  <div class="stack">
    <?php render_decisions($decisions, url('home')); ?>

    <section class="panel">
      <?= panel_head('Caixa de entrada', ['icon' => 'inbox-fill', 'more' => ['Ver todas', url('inbox')]]) ?>
      <?php
        $shown = 0;
        echo '<div class="feed">';
        foreach ($inbox as $m) {
            if ($decisions && $m['kind'] === 'decision') continue; // já estão no painel acima
            render_inbox_msg($m, 'home', ['unread' => empty($m['is_read'])]);
            if (++$shown >= 5) break;
        }
        echo '</div>';
        if (!$shown) echo empty_state('Nada novo por aqui.', 'Avance os dias para movimentar a liga.', 'inbox');
      ?>
    </section>

    <?php if ($todays): ?>
    <section class="panel">
      <?= panel_head($phase === 'regular' ? 'Jogos da data' : phase_label($phase), ['icon' => 'calendar3', 'meta' => League::dateLabel($day), 'more' => ['Calendário', url('schedule')]]) ?>
      <div class="games">
        <?php foreach ($todays as $g) echo game_tile($g, $gmId); ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($phase === 'offseason'):
      $aw = League::awards($season);
      $byType = [];
      foreach ($aw as $a) $byType[$a['type']][] = $a;
      $main = ['MVP' => 'MVP', 'Finals MVP' => 'MVP das finais', 'DPOY' => 'Defensor do ano', 'ROY' => 'Novato do ano'];
    ?>
    <section class="panel">
      <?= panel_head('Prêmios da temporada ' . $season, ['icon' => 'award-fill', 'more' => ['Cerimônia', url('awards')]]) ?>
      <div class="grid cols-2">
        <?php foreach ($main as $type => $label): if (empty($byType[$type])) continue; $a = $byType[$type][0]; ?>
          <a class="hs-card" href="<?= url('player', ['id' => $a['player_id']]) ?>">
            <span class="label"><?= e($label) ?></span>
            <span class="hs-title"><?= e($a['player_name']) ?></span>
            <span class="hs-line"><?= e($a['abbr']) ?> · <?= e($a['value']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <section class="panel">
      <?= panel_head('Manchetes da liga', ['icon' => 'newspaper', 'more' => ['História', url('history')]]) ?>
      <?php if ($headlines): ?>
        <div class="headlines">
          <?php foreach ($headlines as $h): ?><div class="headline"><span><?= e($h['text']) ?></span></div><?php endforeach; ?>
        </div>
      <?php else: ?>
        <?= empty_state('A liga ainda está quieta.', 'As manchetes aparecem conforme os dias passam.', 'newspaper') ?>
      <?php endif; ?>
    </section>
  </div>

  <div class="stack">
    <?php if ($top): ?>
    <section class="panel">
      <?= panel_head($played ? 'Destaques do elenco' : 'Seus melhores', ['icon' => 'star-fill', 'more' => ['Escalação', url('lineup')]]) ?>
      <div class="stack-sm">
        <?php foreach ($top as $p):
          $gp = (int) ($p['gp'] ?? 0);
          $small = $p['pos'] . ($gp ? ' · ' . avg($p['s_pts'] ?? 0, $gp) . ' pts/jogo' : ' · ' . (int) $p['age'] . ' anos')
                 . ((int) ($p['injury_games'] ?? 0) ? ' · lesionado' : ''); ?>
          <div class="spread home-player">
            <?= player_who($p, $small, $gm['primary_color'] ?? '#1a1a2e') ?>
            <?= ovr_badge($p['ovr']) ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($confRows): ?>
    <section class="panel">
      <?= panel_head('Conferência ' . $confName, ['icon' => 'bar-chart-fill', 'more' => ['Tabela', url('standings')]]) ?>
      <div class="table-wrap">
        <table class="tbl compact">
          <thead><tr><th class="c">#</th><th>Time</th><th class="num">V</th><th class="num">D</th></tr></thead>
          <tbody>
          <?php
            $rows = array_slice($confRows, 0, 10);
            $inTop = false;
            foreach ($rows as $r) if ((int) $r['id'] === $gmId) $inTop = true;
            if (!$inTop && $seed) foreach ($confRows as $r) if ((int) $r['id'] === $gmId) $rows[] = $r;
            foreach ($rows as $r): $mine = (int) $r['id'] === $gmId; ?>
            <tr class="<?= $mine ? 'mine' : '' ?>">
              <td class="rank"><?= (int) $r['seed'] ?></td>
              <td><a class="who" href="<?= url('team', ['id' => $r['id']]) ?>"><?= team_logo($r['abbr'], $r['primary_color'] ?? '#333', 'sm') ?><b><?= e($r['abbr']) ?></b><small class="hide-sm"><?= e($r['name']) ?></small></a></td>
              <td class="num"><?= (int) $r['wins'] ?></td>
              <td class="num"><?= (int) $r['losses'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($race): ?>
    <section class="panel">
      <?= panel_head('Corrida pelos prêmios', ['icon' => 'award-fill']) ?>
      <div class="race">
        <?php foreach (['mvp' => ['MVP', fn($p) => number_format((float) $p['ppg'], 1) . ' pts'],
                        'dpoy' => ['Defensor', fn($p) => number_format((float) $p['bpg'], 1) . ' toc · ' . number_format((float) $p['spg'], 1) . ' rb'],
                        'roy' => ['Novato', fn($p) => number_format((float) $p['ppg'], 1) . ' pts']] as $k => [$label, $val]): ?>
          <div class="race-col">
            <div class="sub-h"><?= e($label) ?></div>
            <?php if (!empty($race[$k])): foreach (array_slice($race[$k], 0, 3) as $i => $p): ?>
              <a class="race-row" href="<?= url('player', ['id' => $p['id']]) ?>">
                <span class="rk"><?= $i + 1 ?></span><span class="nm"><?= e($p['name']) ?> <span class="dim"><?= e($p['abbr']) ?></span></span><span class="vl"><?= e($val($p)) ?></span>
              </a>
            <?php endforeach; else: ?>
              <p class="dim">Jogos insuficientes.</p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php render_footer(); ?>
