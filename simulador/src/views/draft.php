<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/Offseason.php';
render_header('Draft');
$seasons = League::draftSeasons();
$sel = (int) ($_GET['season'] ?? ($seasons[0] ?? 0));
$picks = $sel ? League::draftResults($sel) : [];

// Mock de prospectos disponíveis (draft atual ou próxima classe)
$currentSeason = League::season();
$mockProspects = Offseason::availableProspects($currentSeason);
if (!$mockProspects) {
    $mockProspects = Offseason::availableProspects($currentSeason + 1);
}
?>
<div class="card-head page">
  <h1 class="page-title">Draft — Resultados</h1>
  <?php if ($seasons): ?>
  <form method="get" style="margin:0">
    <input type="hidden" name="p" value="draft">
    <select name="season" onchange="this.form.submit()" class="season-select">
      <?php foreach ($seasons as $s): ?>
        <option value="<?= $s ?>" <?= $s==$sel?'selected':'' ?>>Draft <?= $s ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
</div>

<p class="legend">🔍 <strong>Névoa de guerra:</strong> antes do Draft, os times só conhecem as <em>notas de olheiro</em> (não o OVR exato)
   e nunca o <em>potencial real</em>. Calouros chegam com OVR baixo — alguns viram lendas, outros são <em>busts</em>.
   Aqui os valores já estão revelados após a seleção.</p>

<?php if ($mockProspects): ?>
<section class="card" style="margin-bottom:18px;border-color:rgba(228,0,43,.3)">
  <div class="card-head">
    <h2>🎓 Mock Draft — Próximos Prospectos (Top 20)</h2>
    <?php if (League::phase() === 'draft'): ?>
      <a class="btn btn-primary btn-sm" href="<?= url('draftroom') ?>">Ir para a Sala do Draft →</a>
    <?php endif; ?>
  </div>
  <table class="box-table draft-table">
    <thead><tr>
      <th>#</th><th>Prospecto</th><th>Pos</th><th>Idade</th><th>Nota</th>
      <th>Arr.3</th><th>Interior</th><th>Defesa</th><th>Passe</th><th>Rebote</th>
    </tr></thead>
    <tbody>
    <?php foreach (array_slice($mockProspects, 0, 20) as $i => $p): ?>
      <tr>
        <td class="seed" style="color:var(--brand2);font-weight:800"><?= $i+1 ?></td>
        <td class="bx-name"><strong><?= e($p['name']) ?></strong></td>
        <td><?= e($p['pos']) ?></td>
        <td class="num"><?= $p['age'] ?></td>
        <td><span class="grade <?= gradeClass($p['ovr']) ?>"><?= grade($p['ovr']) ?></span></td>
        <td><span class="grade <?= gradeClass($p['thr']) ?>"><?= grade($p['thr']) ?></span></td>
        <td><span class="grade <?= gradeClass($p['ins']) ?>"><?= grade($p['ins']) ?></span></td>
        <td><span class="grade <?= gradeClass($p['def']) ?>"><?= grade($p['def']) ?></span></td>
        <td><span class="grade <?= gradeClass($p['pmk']) ?>"><?= grade($p['pmk']) ?></span></td>
        <td><span class="grade <?= gradeClass($p['reb']) ?>"><?= grade($p['reb']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<?php if (!$picks): ?>
  <p class="muted">Nenhum draft realizado ainda. Os calouros entram na virada de temporada (botão "Iniciar próxima temporada").</p>
<?php else: ?>
<section class="card">
  <table class="box-table draft-table">
    <thead><tr>
      <th>#</th><th>Time</th><th>Calouro</th><th>Pos</th><th>Idade</th>
      <th>OVR</th><th>Potencial</th><th>Arr. 3</th><th>Interior</th><th>Defesa</th><th>Passe</th>
    </tr></thead>
    <tbody>
    <?php foreach ($picks as $p):
      $potGap = (int)$p['potential'] - (int)$p['ovr']; ?>
      <tr>
        <td class="seed"><?= $p['pick_no'] ?></td>
        <td><a href="<?= url('team',['id'=>$p['picked_by']]) ?>"><?= e($p['team_abbr']) ?></a></td>
        <td class="bx-name"><?= e($p['name']) ?></td>
        <td><?= e($p['pos']) ?></td>
        <td class="num"><?= $p['age'] ?></td>
        <td class="num"><span class="ovr ovr-<?= $p['ovr']>=80?'star':($p['ovr']>=75?'good':'role') ?>"><?= $p['ovr'] ?></span></td>
        <td class="num"><span class="pot pot-<?= $potGap>=10?'high':($potGap>=5?'mid':'low') ?>"><?= $p['potential'] ?></span></td>
        <td><span class="grade <?= gradeClass($p['thr']) ?>"><?= grade($p['thr']) ?></span></td>
        <td><span class="grade <?= gradeClass($p['ins']) ?>"><?= grade($p['ins']) ?></span></td>
        <td><span class="grade <?= gradeClass($p['def']) ?>"><?= grade($p['def']) ?></span></td>
        <td><span class="grade <?= gradeClass($p['pmk']) ?>"><?= grade($p['pmk']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
<?php render_footer(); ?>
