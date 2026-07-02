<?php
require_once dirname(__DIR__) . '/helpers.php';
render_header('Modo GM');
$teams = League::allTeams();
$current = League::gmTeam();
?>
<h1 class="page-title">🎮 Modo GM — escolha sua franquia</h1>
<p class="legend">Você assume o comando de <strong>um</strong> time: decide esquema, rotação, minutagem e trocas.
   Os outros 29 são controlados pela IA. Você pode trocar de franquia quando quiser.</p>

<div class="gm-grid">
  <?php foreach ($teams as $t):
    $str = League::teamStrength((int) $t['id']); ?>
    <a class="gm-card <?= $current == $t['id'] ? 'active' : '' ?>" style="<?= gradient($t) ?>"
       href="<?= url('home', ['action' => 'set-gm', 'team' => $t['id']]) ?>">
      <div class="gm-abbr"><?= e($t['abbr']) ?></div>
      <div class="gm-name"><?= e(teamFull($t)) ?></div>
      <div class="gm-meta"><?= $t['conf'] === 'E' ? 'Leste' : 'Oeste' ?> · Força <?= $str ?> · <?= (int)$t['titles'] ?> 🏆</div>
      <?php if ($current == $t['id']): ?><div class="gm-current">✓ Sua franquia</div><?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>
<?php render_footer(); ?>
