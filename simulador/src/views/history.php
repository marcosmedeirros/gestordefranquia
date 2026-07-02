<?php
require_once dirname(__DIR__) . '/helpers.php';
render_header('Histórico');
$champions = League::champions();
$titles = League::titlesRanking();
$awardSeasons = League::awardSeasons();
$sel = (int) ($_GET['season'] ?? ($awardSeasons[0] ?? League::season()));
$awards = League::awards($sel);
$records = League::seasonRecords('pts', 10);
$byType = [];
foreach ($awards as $a) { $byType[$a['type']][] = $a; }
?>
<h1 class="page-title">Histórico da Liga</h1>

<?php
$gmId = League::gmTeam();
if ($gmId):
  $myTeam = League::team($gmId);
  $traj = League::teamSeasonHistory($gmId);
  $fs = League::franchiseSummary($gmId);
?>
<section class="card" style="border:1px solid <?= e($myTeam['primary_color']) ?>">
  <div class="card-head"><h2>🏟️ Minha franquia — <?= e(teamFull($myTeam)) ?></h2></div>
  <div class="fr-summary">
    <span class="fr-stat"><strong><?= $fs['titles'] ?></strong> 🏆 títulos</span>
    <span class="fr-stat"><strong><?= $fs['finals'] ?></strong> finais</span>
    <span class="fr-stat"><strong><?= $fs['playoffs'] ?></strong> playoffs</span>
    <span class="fr-stat"><strong><?= $fs['seasons'] ?></strong> temporadas</span>
    <?php if ($fs['best']): ?><span class="fr-stat">Melhor campanha: <strong><?= (int)$fs['best']['wins'] ?>-<?= (int)$fs['best']['losses'] ?></strong> (Temp. <?= (int)$fs['best']['season'] ?>)</span><?php endif; ?>
  </div>
  <?php if (!$traj): ?>
    <p class="muted">Conclua sua primeira temporada para registrar a trajetória da franquia.</p>
  <?php else: ?>
  <table class="box-table">
    <thead><tr><th>Temp.</th><th>Campanha</th><th>Seed</th><th>Resultado</th></tr></thead>
    <tbody>
      <?php foreach ($traj as $h): ?>
        <tr>
          <td>#<?= (int)$h['season'] ?></td>
          <td><strong><?= (int)$h['wins'] ?></strong>-<?= (int)$h['losses'] ?></td>
          <td><?= (int)$h['seed'] ? '#'.(int)$h['seed'] : '—' ?></td>
          <td class="<?= (int)$h['champion'] ? 'fr-champ' : '' ?>"><?= e(League::exitLabel((int)$h['exit_round'], (int)$h['made_playoffs'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="dashboard">
  <section class="card">
    <div class="card-head"><h2>🏆 Campeões</h2></div>
    <?php if (!$champions): ?>
      <p class="muted">Nenhum título ainda. Conclua uma temporada.</p>
    <?php else: ?>
    <table class="box-table">
      <thead><tr><th>Temp.</th><th>Campeão</th><th>Vice</th><th>MVP das Finais</th></tr></thead>
      <tbody>
      <?php foreach ($champions as $c): ?>
        <tr>
          <td><?= $c['season'] ?></td>
          <td><span class="dot" style="background:<?= e($c['champ_color']) ?>"></span>
              <a href="<?= url('team',['id'=>$c['team_id']]) ?>"><?= e($c['champ_city'].' '.$c['champ_name']) ?></a></td>
          <td class="muted"><?= e($c['run_abbr'] ?? '—') ?></td>
          <td><?= e($c['fmvp_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2>🏅 Dinastias (títulos)</h2></div>
    <?php if (!$titles): ?><p class="muted">Sem títulos ainda.</p><?php else: ?>
    <table class="mini-table ranked">
      <?php $r=1; foreach ($titles as $t): ?>
        <tr><td class="rank"><?= $r++ ?></td>
            <td><span class="dot" style="background:<?= e($t['primary_color']) ?>"></span><?= e($t['city'].' '.$t['name']) ?></td>
            <td class="num"><strong><?= $t['titles'] ?></strong> 🏆</td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <div class="card-head">
    <h2>Premiações</h2>
    <?php if ($awardSeasons): ?>
    <form method="get" style="margin:0">
      <input type="hidden" name="p" value="history">
      <select name="season" onchange="this.form.submit()" class="season-select">
        <?php foreach ($awardSeasons as $s): ?>
          <option value="<?= $s ?>" <?= $s==$sel?'selected':'' ?>>Temporada <?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>
  <?php if (!$awards): ?>
    <p class="muted">Sem premiações registradas. Conclua uma temporada (os prêmios saem ao fim dos playoffs).</p>
  <?php else:
    $singles = ['MVP'=>'MVP','Finals MVP'=>'MVP das Finais','DPOY'=>'Defensor do Ano (DPOY)','ROY'=>'Novato do Ano (ROY)']; ?>
    <div class="awards-row">
      <?php foreach ($singles as $type=>$label): if (empty($byType[$type])) continue; $a=$byType[$type][0]; ?>
        <div class="award-card">
          <div class="award-label"><?= e($label) ?></div>
          <a class="award-name" href="<?= url('player',['id'=>$a['player_id']]) ?>"><?= e($a['player_name']) ?></a>
          <div class="award-meta"><?= e($a['abbr']) ?> · <?= e($a['value']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php foreach (['All-NBA 1','All-NBA 2','All-NBA 3'] as $tt): if (empty($byType[$tt])) continue; ?>
      <h3 class="box-team"><?= str_replace('All-NBA','Quinteto All-NBA',$tt) ?>º Time</h3>
      <p class="allnba">
        <?php foreach ($byType[$tt] as $a): ?>
          <a href="<?= url('player',['id'=>$a['player_id']]) ?>"><?= e($a['player_name']) ?></a>
          <span class="muted">(<?= e($a['abbr']) ?>)</span>&nbsp;&nbsp;
        <?php endforeach; ?>
      </p>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<section class="card">
  <div class="card-head"><h2>📈 Recordes de pontos numa temporada</h2></div>
  <?php if (!$records): ?><p class="muted">Sem dados ainda.</p><?php else: ?>
  <table class="mini-table ranked">
    <?php $r=1; foreach ($records as $rec): ?>
      <tr><td class="rank"><?= $r++ ?></td>
          <td><?= e($rec['name']) ?> <span class="muted">Temp. <?= $rec['season'] ?> · OVR <?= $rec['ovr'] ?></span></td>
          <td class="num"><strong><?= e($rec['avg']) ?></strong> ppg</td></tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</section>
<?php render_footer(); ?>
