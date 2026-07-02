<?php
require_once dirname(__DIR__) . '/helpers.php';
render_header('Power Rankings');
$rank = League::powerRankings();
?>
<h1 class="page-title">📊 Power Rankings</h1>
<p class="legend">Ranking de força combinando aproveitamento, saldo de pontos, sequência atual e força do elenco.</p>
<section class="card">
  <table class="standings-table">
    <thead><tr><th>#</th><th>Time</th><th>Conf.</th><th>V-D</th><th>Saldo/jogo</th><th>Sequência</th><th>Índice</th></tr></thead>
    <tbody>
    <?php foreach ($rank as $t):
      $stk = (int) $t['streak'];
      $stkTxt = $stk > 0 ? "$stk V" : ($stk < 0 ? abs($stk) . ' D' : '—');
      $stkCls = $stk > 0 ? 'cap-ok' : ($stk < 0 ? 'cap-over' : ''); ?>
      <tr>
        <td class="seed"><?= $t['rank'] ?></td>
        <td><span class="dot" style="background:<?= e($t['primary_color']) ?>"></span>
            <a href="<?= url('team',['id'=>$t['id']]) ?>"><?= e($t['city'].' '.$t['name']) ?></a></td>
        <td><?= $t['conf']==='E'?'Leste':'Oeste' ?></td>
        <td class="num"><?= $t['wins'] ?>-<?= $t['losses'] ?></td>
        <td class="num"><?= $t['avg_margin'] > 0 ? '+' : '' ?><?= number_format($t['avg_margin'],1) ?></td>
        <td><span class="cap-status <?= $stkCls ?>"><?= $stkTxt ?></span></td>
        <td class="num"><strong><?= number_format($t['power'],1) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php render_footer(); ?>
