<?php
require_once dirname(__DIR__) . '/helpers.php';
render_header('Nova franquia');
$teams = League::allTeams();
$current = League::gmTeam();
$fired = League::isFired();
$err = $_GET['err'] ?? '';
$cur = $current ? League::team($current) : null;
usort($teams, fn($a, $b) => League::teamStrength((int) $b['id']) <=> League::teamStrength((int) $a['id']));
$avg = League::avgStrength();
?>
<?php if ($err): ?><div class="cap-alert cap-alert-danger"><?= e($err) ?></div><?php endif; ?>

<?php if ($fired && $cur): ?>
<div class="fired-banner">
  <div class="fb-kicker">🔴 Demitido</div>
  <h1>O <?= e(teamFull($cur)) ?> te dispensou.</h1>
  <p>A diretoria perdeu a paciência com as temporadas abaixo da meta. Sua carreira continua: outras franquias querem o seu trabalho.
     Escolha a nova casa — o técnico vai com você, a paciência da diretoria recomeça e a meta é definida pelo elenco que você encontrar.</p>
</div>
<?php elseif ($current): ?>
<h1 class="page-title">🏟️ Franquias da liga</h1>
<p class="legend">Você comanda o <strong><?= e(teamFull($cur)) ?></strong>. Só é possível assumir outra franquia quando a diretoria te demite —
   isso acontece quando a paciência dela chega a zero (ver Meu Time).</p>
<?php else: ?>
<h1 class="page-title">🎮 Escolha sua franquia</h1>
<?php endif; ?>

<div class="gm-grid">
  <?php foreach ($teams as $t):
    $str = League::teamStrength((int) $t['id']);
    $d = $str - $avg;
    $tier = $d >= 8 ? 'Candidato ao título' : ($d >= 0 ? 'Time de playoffs' : ($d >= -8 ? 'Briga pelo play-in' : 'Reconstrução'));
    $cap = Cap::summary((int) $t['id']);
    $canPick = $fired || !$current;
    $isCur = $current == $t['id']; ?>
    <a class="gm-card <?= $isCur ? 'active' : '' ?> <?= $canPick && !$isCur ? '' : 'gm-card-static' ?>" style="<?= gradient($t) ?>"
       href="<?= $canPick && !$isCur ? url('home', ['action' => 'set-gm', 'team' => $t['id']]) : url('team', ['id' => $t['id']]) ?>"
       <?= $canPick && !$isCur ? 'data-confirm="Assumir o ' . e(teamFull($t)) . '?" data-confirm-title="Assumir franquia" data-confirm-ok="Assumir"' : '' ?>>
      <div class="gm-abbr"><?= e($t['abbr']) ?></div>
      <div class="gm-name"><?= e(teamFull($t)) ?></div>
      <div class="gm-meta"><?= $t['conf'] === 'E' ? 'Leste' : 'Oeste' ?> · <?= $t['wins'] ?>-<?= $t['losses'] ?> · Força <?= $str ?> · <?= (int)$t['titles'] ?> 🏆</div>
      <div class="gm-meta"><?= $tier ?> · Folha <?= Cap::m($cap['payroll']) ?><?= $cap['status'] === 'over' ? ' (acima do teto)' : '' ?></div>
      <?php if ($isCur): ?><div class="gm-current"><?= $fired ? '✗ Te demitiu' : '✓ Sua franquia' ?></div>
      <?php elseif ($canPick): ?><div class="gm-current">Assumir →</div><?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>
<?php render_footer(); ?>
