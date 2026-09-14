<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/Offseason.php';
require_once dirname(__DIR__) . '/PlayerFace.php';
render_header('Drafts');
$seasons = League::draftSeasons();
$sel = (int) ($_GET['season'] ?? ($seasons[0] ?? 0));
$picks = $sel ? League::draftResults($sel) : [];

// Mock de prospectos disponíveis (draft atual ou próxima classe)
$currentSeason = League::season();
$mockProspects = Offseason::availableProspects($currentSeason);
if (!$mockProspects) {
    $mockProspects = Offseason::availableProspects($currentSeason + 1);
}

$phase = League::phase();
$gmIdD = League::gmTeam();
$teamMap = [];
foreach (League::allTeams() as $t) $teamMap[(int) $t['id']] = $t;
$live = $phase === 'draft' && $sel === (int) Database::meta('draft_season', 0);
$mockSkills = ['thr' => 'ARR', 'ins' => 'INT', 'def' => 'DEF', 'pmk' => 'PAS', 'reb' => 'REB'];
$skills = ['thr' => 'ARR', 'ins' => 'INT', 'def' => 'DEF', 'pmk' => 'PAS'];

page_head('Drafts', [
    'eyebrow' => 'Mercado',
    'sub' => 'As classes de calouros de cada temporada. Antes da escolha só existem as notas dos olheiros; depois dela o OVR aparece, e o potencial continua sendo uma estimativa.',
]);
?>
<div class="stack">
  <?php if ($mockProspects): ?>
  <section class="panel hot pad-0 dft-mock">
    <?= panel_head($phase === 'draft' ? 'Calouros ainda disponíveis' : 'Próxima classe', [
        'icon' => 'mortarboard-fill',
        'meta' => 'Top 20 do board',
        'more' => $phase === 'draft' ? ['Sala do draft', url('draftroom')] : ($phase === 'lottery' ? ['Loteria', url('lottery')] : null),
    ]) ?>
    <p class="dft-key">Notas dos olheiros: <b>ARR</b> arremesso de 3 · <b>INT</b> jogo interior · <b>DEF</b> defesa · <b>PAS</b> passe · <b>REB</b> rebote</p>
    <div class="table-wrap">
      <table class="tbl compact">
        <thead><tr>
          <th class="c">#</th><th>Prospecto</th><th class="c">Geral</th>
          <?php foreach ($mockSkills as $ab): ?><th class="c hide-sm"><?= $ab ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach (array_slice($mockProspects, 0, 20) as $i => $p): ?>
          <tr>
            <td class="rank"><?= $i + 1 ?></td>
            <td class="t-who"><span class="who"><img class="face" src="<?= e(PlayerFace::url((int) $p['id'], $p['name'], $p['pos'])) ?>" alt="" loading="lazy"><span class="w-txt"><b><?= e($p['name']) ?></b><small><?= e($p['pos']) ?> · <?= (int) $p['age'] ?> anos</small></span></span></td>
            <td class="c"><span class="grade <?= gradeClass($p['ovr']) ?>"><?= grade($p['ovr']) ?></span></td>
            <?php foreach ($mockSkills as $k => $ab): ?><td class="c hide-sm"><span class="grade <?= gradeClass($p[$k]) ?>"><?= grade($p[$k]) ?></span></td><?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($seasons): ?>
  <nav class="seg" aria-label="Temporada do draft">
    <?php foreach ($seasons as $s): ?>
      <a class="<?= $s === $sel ? 'on' : '' ?>" href="<?= url('draft', ['season' => $s]) ?>">Temporada <?= $s ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php if (!$picks): ?>
  <section class="panel">
    <?= empty_state($seasons ? 'Nenhuma escolha nesta temporada.' : 'Nenhum draft realizado ainda.', 'Os calouros chegam na entressafra: primeiro a loteria, depois o draft.', 'mortarboard') ?>
  </section>
  <?php else: ?>
  <?php $myPicks = $gmIdD ? array_values(array_filter($picks, fn($p) => (int) $p['picked_by'] === $gmIdD)) : []; ?>
  <?php if ($myPicks): ?>
  <section class="panel hot">
    <?= panel_head('Suas escolhas', ['icon' => 'person-check-fill', 'meta' => 'Draft da temporada ' . $sel]) ?>
    <div class="pcards">
      <?php foreach ($myPicks as $p): ?>
        <div class="pcard starter">
          <span class="dft-no">#<?= (int) $p['pick_no'] ?></span>
          <div class="pc-txt">
            <b><?= e($p['name']) ?></b>
            <small><?= e($p['pos']) ?> · <?= (int) $p['age'] ?> anos</small>
            <div class="pc-flags"><span class="dft-pot">Potencial <span class="grade <?= gradeClass((int) $p['potential']) ?>" title="Estimativa dos olheiros"><?= grade((int) $p['potential']) ?></span></span></div>
          </div>
          <?= ovr_badge($p['ovr']) ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="panel pad-0">
    <?= panel_head('Classe da temporada ' . $sel, ['icon' => 'list-ol', 'right' => $live ? chip('Em andamento', 'warn', 'broadcast') : '', 'meta' => count($picks) === 1 ? '1 escolha' : count($picks) . ' escolhas']) ?>
    <div class="table-wrap">
      <table class="tbl compact">
        <thead><tr>
          <th class="c">#</th><th>Time</th><th>Calouro</th><th class="c">OVR</th><th class="c" title="Estimativa dos olheiros">Pot.</th>
          <?php foreach ($skills as $ab): ?><th class="c hide-sm"><?= $ab ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($picks as $p):
          $mine = $gmIdD && (int) $p['picked_by'] === $gmIdD;
          $pt = $teamMap[(int) $p['picked_by']] ?? null; ?>
          <tr class="<?= $mine ? 'mine' : '' ?>">
            <td class="rank"><?= (int) $p['pick_no'] ?></td>
            <td><a class="who" href="<?= url('team', ['id' => $p['picked_by']]) ?>"><?= team_logo((string) $p['team_abbr'], $pt['primary_color'] ?? '#333', 'sm') ?><b class="hide-sm"><?= e($p['team_abbr']) ?></b></a></td>
            <td class="dft-name"><b><?= e($p['name']) ?></b><small><?= e($p['pos']) ?> · <?= (int) $p['age'] ?> anos</small></td>
            <td class="c"><?= ovr_badge($p['ovr'], 'sm') ?></td>
            <td class="c"><span class="grade <?= gradeClass((int) $p['potential']) ?>" title="Estimativa dos olheiros"><?= grade((int) $p['potential']) ?></span></td>
            <?php foreach ($skills as $k => $ab): ?><td class="c hide-sm"><span class="grade <?= gradeClass($p[$k]) ?>"><?= grade($p[$k]) ?></span></td><?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="dft-foot">Salário de calouro no 1º ano: #1–3 18M · #4–8 14M · #9–12 12M · #13–16 8M · #17–22 5M · #23+ 3M · 2ª rodada 2M.</p>
  </section>
  <?php endif; ?>
</div>
<?php render_footer(); ?>
