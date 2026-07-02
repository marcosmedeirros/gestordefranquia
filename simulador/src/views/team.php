<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/PlayerFace.php';
$id = (int) ($_GET['id'] ?? 0);
$t = League::team($id);
if (!$t) { render_header('Time'); echo '<p>Time não encontrado.</p>'; render_footer(); exit; }
render_header(teamFull($t));
$roster = League::roster($id);
$standE = League::standings($t['conf']);
$seed = null;
foreach ($standE as $s) { if ($s['id'] == $id) { $seed = $s['seed']; break; } }
$confName = $t['conf'] === 'E' ? 'Leste' : 'Oeste';
$teamPayroll = League::teamPayroll($id);
$teamStrength = League::teamStrength($id);
?>
<div class="team-hero" style="<?= gradient($t) ?>">
  <div class="th-abbr"><?= e($t['abbr']) ?></div>
  <div>
    <h1><?= e(teamFull($t)) ?></h1>
    <p><?= e($confName) ?> · <?= e($t['div']) ?> · <strong><?= $t['wins'] ?>-<?= $t['losses'] ?></strong>
       <?= $seed ? "· #$seed na conferência" : '' ?></p>
    <p class="th-meta">💰 Folha: <strong><?= money($teamPayroll) ?></strong>
       &nbsp;·&nbsp; 💪 Força: <strong><?= $teamStrength ?></strong>
       &nbsp;·&nbsp; 🏀 Ataque: <?= e($t['scheme_off'] ?? '—') ?>
       &nbsp;·&nbsp; 🛡️ Defesa: <?= e($t['scheme_def'] ?? '—') ?></p>
  </div>
</div>

<section class="card">
  <div class="card-head"><h2>Elenco</h2></div>
  <table class="box-table roster-table">
    <thead><tr><th></th><th>Jogador</th><th>Pos</th><th>Idade</th><th>OVR</th><th>Pot</th><th>JG</th><th>PPG</th><th>RPG</th><th>APG</th></tr></thead>
    <tbody>
    <?php foreach ($roster as $p): ?>
      <tr class="<?= $p['is_starter'] ? 'starter' : '' ?>">
        <td><img class="face-mini" src="<?= PlayerFace::url((int)$p['id'], $p['name'], $p['pos']) ?>" alt=""></td>
        <td class="bx-name">
          <a href="<?= url('player',['id'=>$p['id']]) ?>"><?= e($p['name']) ?></a>
          <?= $p['is_starter'] ? '<span class="tag">Titular</span>' : '' ?>
          <?php if ((int)($p['seasons_pro'] ?? 1) === 0): ?><span class="tag" style="background:#7b2ff7">Calouro</span><?php endif; ?>
          <?php if ((int)($p['injury_games'] ?? 0) > 0): ?><span class="badge-inj">Lesão (<?= (int)$p['injury_games'] ?>j)</span><?php endif; ?>
        </td>
        <td><?= e($p['pos']) ?></td>
        <td class="num"><?= $p['age'] ?></td>
        <td class="num"><span class="ovr ovr-<?= $p['ovr']>=90?'elite':($p['ovr']>=80?'star':($p['ovr']>=75?'good':'role')) ?>"><?= $p['ovr'] ?></span></td>
        <td class="num"><?= (int)($p['potential'] ?? 0) > (int)$p['ovr'] ? '<span class="muted">'.(int)$p['potential'].'</span>' : '—' ?></td>
        <td class="num"><?= (int)($p['gp'] ?? 0) ?></td>
        <td class="num"><?= avg($p['s_pts'] ?? 0, $p['gp'] ?? 0) ?></td>
        <td class="num"><?= avg($p['s_reb'] ?? 0, $p['gp'] ?? 0) ?></td>
        <td class="num"><?= avg($p['s_ast'] ?? 0, $p['gp'] ?? 0) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php $picks = League::teamPicks($id); ?>
<section class="card">
  <div class="card-head"><h2>🎯 Draft Picks</h2></div>
  <?php if (!$picks): ?>
    <p class="muted">Sem picks futuras (todas negociadas).</p>
  <?php else: ?>
    <div class="picks-list">
      <?php foreach ($picks as $pk):
        $own = (int)$pk['original_team_id'] === (int)$pk['owner_team_id']; ?>
        <span class="pick-chip <?= (int)$pk['round']===1?'pick-r1':'pick-r2' ?>">
          R<?= (int)$pk['round'] ?> · <?= League::draftYearLabel((int)$pk['year']) ?>
          <?php if (!$own): ?><span class="muted">(via <?= e($pk['orig_abbr']) ?>)</span><?php endif; ?>
        </span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php render_footer(); ?>
