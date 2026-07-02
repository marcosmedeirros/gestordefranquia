<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/Offseason.php';
require_once dirname(__DIR__) . '/PlayerFace.php';
render_header('Sala do Draft');
if (League::phase() !== 'draft') {
    echo '<p class="muted">Não há draft em andamento. <a href="' . url('home') . '">Voltar ao início</a></p>';
    render_footer(); exit;
}
$st = Offseason::draftState();
$season = $st['season'];
$onClock = League::team($st['on_clock']);
$origTeam = !empty($st['orig']) ? League::team((int)$st['orig']) : null;
$viaTxt = ($origTeam && (int)$st['orig'] !== (int)$st['on_clock']) ? ' (via ' . e($origTeam['abbr']) . ')' : '';
$gm = League::team(League::gmTeam());
$avail = Offseason::availableProspects($season);
$recent = Offseason::recentPicks($season, 10);
$err = $_GET['err'] ?? '';
?>
<h1 class="page-title">🎓 Sala do Draft — Temporada <?= $season ?></h1>
<?php if ($err): ?><div class="trade-msg no"><?= e($err) ?></div><?php endif; ?>

<div class="draft-status">
  <div>Escolha <strong>#<?= $st['pick'] + 1 ?></strong> de <?= $st['total'] ?> <span class="muted">(<?= (int)$st['round']===2?'2ª':'1ª' ?> rodada)</span></div>
  <div>Na vez: <strong style="color:<?= e($onClock['primary_color']) ?>"><?= e(teamFull($onClock)) ?></strong><?= $viaTxt ?></div>
  <?php if ($st['is_user']): ?><div class="on-clock-you">⏰ É a SUA vez de escolher!</div><?php endif; ?>
</div>

<p class="legend">🔍 <strong>Névoa de guerra:</strong> você vê as <em>notas de olheiro</em> e a nota geral estimada — mas o
   <strong>OVR exato e o potencial são ocultos</strong>. Alguns calouros viram lendas; outros, <em>busts</em>. Confie no faro.</p>

<div class="dashboard">
  <section class="card span2">
    <div class="card-head"><h2>Prospectos disponíveis (board de consenso)</h2></div>
    <table class="box-table draft-table">
      <thead><tr>
        <th></th><th>Calouro</th><th>Pos</th><th>Idade</th><th>Nota Geral</th>
        <th>Arr.3</th><th>Interior</th><th>Defesa</th><th>Passe</th><th>Rebote</th><th>Atlet.</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach (array_slice($avail, 0, 40) as $p): ?>
        <tr>
          <td><img class="face-mini" src="<?= PlayerFace::url((int)$p['id'], $p['name'], $p['pos']) ?>" alt=""></td>
          <td class="bx-name"><?= e($p['name']) ?></td>
          <td><?= e($p['pos']) ?></td>
          <td class="num"><?= $p['age'] ?></td>
          <td><span class="grade <?= gradeClass($p['ovr']) ?>"><?= grade($p['ovr']) ?></span></td>
          <td><span class="grade <?= gradeClass($p['thr']) ?>"><?= grade($p['thr']) ?></span></td>
          <td><span class="grade <?= gradeClass($p['ins']) ?>"><?= grade($p['ins']) ?></span></td>
          <td><span class="grade <?= gradeClass($p['def']) ?>"><?= grade($p['def']) ?></span></td>
          <td><span class="grade <?= gradeClass($p['pmk']) ?>"><?= grade($p['pmk']) ?></span></td>
          <td><span class="grade <?= gradeClass($p['reb']) ?>"><?= grade($p['reb']) ?></span></td>
          <td><span class="grade <?= gradeClass($p['ath']) ?>"><?= grade($p['ath']) ?></span></td>
          <td>
            <?php if ($st['is_user']): ?>
              <a class="btn btn-primary btn-sm" href="<?= url('home', ['action'=>'draft-pick','prospect'=>$p['id']]) ?>"
                 onclick="return confirm('Draftar <?= e($p['name']) ?> com a pick #<?= $st['pick']+1 ?>?')">Draftar</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="card span2">
    <div class="card-head"><h2>Escolhas recentes</h2></div>
    <?php if (!$recent): ?><p class="muted">O draft está começando...</p><?php else: ?>
    <table class="mini-table">
      <?php foreach ($recent as $r): ?>
        <tr><td class="rank">#<?= $r['pick_no'] ?></td>
            <td><strong><?= e($r['team_abbr']) ?></strong> — <?= e($r['name']) ?> <span class="muted">(<?= e($r['pos']) ?>, OVR <?= $r['ovr'] ?>)</span></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </section>
</div>
<?php render_footer(); ?>
