<?php
require_once dirname(__DIR__) . '/helpers.php';
require_once dirname(__DIR__) . '/PlayerFace.php';
render_header('Líderes');
$cats = ['pts' => 'Pontos', 'reb' => 'Rebotes', 'ast' => 'Assistências', 'stl' => 'Roubos', 'blk' => 'Tocos'];
?>
<h1 class="page-title">Líderes da Liga</h1>
<div class="leaders-grid">
  <?php foreach ($cats as $key => $label):
    $rows = League::leaders($key, 10); ?>
    <section class="card">
      <div class="card-head"><h2><?= e($label) ?> por jogo</h2></div>
      <table class="mini-table ranked">
        <?php $r = 1; foreach ($rows as $l): ?>
          <tr>
            <td class="rank"><?= $r++ ?></td>
            <td>
              <img src="<?= PlayerFace::url((int)$l['id'], $l['name'], $l['pos']) ?>"
                   style="width:28px;height:34px;border-radius:5px;object-fit:cover;object-position:top;vertical-align:middle;margin-right:6px;background:var(--card2)"
                   alt="">
              <a href="<?= url('player',['id'=>$l['id']]) ?>"><?= e($l['name']) ?></a>
              <span class="muted"><?= e($l['abbr']) ?> · <?= e($l['pos']) ?></span>
            </td>
            <td class="num"><strong><?= e($l['avg']) ?></strong></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td class="muted">Simule jogos primeiro.</td></tr><?php endif; ?>
      </table>
    </section>
  <?php endforeach; ?>
</div>
<?php render_footer(); ?>
